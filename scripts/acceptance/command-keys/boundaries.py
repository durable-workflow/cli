"""Held inputs and exact container ownership for the manual Linux experiment."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
import types


class HeldInput:
    def __init__(self, path, expected, *, maximum=16 * 1024 * 1024):
        self.path = Path(path)
        if not self.path.is_absolute() or self.path.resolve() != self.path:
            raise ValueError("input must be an absolute path without symlinks")
        self.fd = os.open(self.path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        self.sealed_fd = None
        try:
            self.identity = self._identity(os.fstat(self.fd))
            value = os.fstat(self.fd)
            if (not stat.S_ISREG(value.st_mode) or value.st_uid != os.getuid()
                    or value.st_nlink != 1 or value.st_mode & 0o022
                    or not 0 < value.st_size <= maximum):
                raise ValueError("input must be an owned single-link non-writable-by-others regular file")
            self.data = os.pread(self.fd, maximum + 1, 0)
            actual = hashlib.sha256(self.data).hexdigest()
            if len(self.data) != value.st_size or (expected is not None and actual != expected):
                raise ValueError("input content identity differs")
            # None is used only to capture an already-running entrypoint.
            # Every external executable/module/fixture has a literal digest.
            self.digest = actual
            self.recheck()
            self.sealed_fd = os.memfd_create("cli-216-input", os.MFD_CLOEXEC | os.MFD_ALLOW_SEALING)
            with os.fdopen(os.dup(self.sealed_fd), "wb") as output:
                output.write(self.data)
            os.fchmod(self.sealed_fd, 0o500)
            fcntl.fcntl(self.sealed_fd, fcntl.F_ADD_SEALS,
                        fcntl.F_SEAL_WRITE | fcntl.F_SEAL_GROW | fcntl.F_SEAL_SHRINK | fcntl.F_SEAL_SEAL)
        except BaseException:
            self.close()
            raise

    @staticmethod
    def _identity(value):
        return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
                value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)

    def recheck(self):
        if (self.path.resolve() != self.path or self._identity(self.path.lstat()) != self.identity
                or self._identity(os.fstat(self.fd)) != self.identity):
            raise ValueError("held input path or descriptor identity changed")
        current = os.pread(self.fd, len(self.data) + 1, 0)
        if hashlib.sha256(current).hexdigest() != self.digest:
            raise ValueError("held input content changed")

    def module(self, name):
        self.recheck()
        module = types.ModuleType(name)
        module.__file__ = str(self.path)
        sys.modules[name] = module
        exec(compile(self.data, str(self.path), "exec"), module.__dict__)
        self.recheck()
        return module

    @property
    def executable(self):
        return "/proc/self/fd/" + str(self.sealed_fd)

    def close(self):
        for name in ("fd", "sealed_fd"):
            descriptor = getattr(self, name, None)
            if descriptor is not None:
                os.close(descriptor)
                setattr(self, name, None)


def private_directory(path):
    path = Path(path)
    if not path.is_absolute() or path.resolve() != path:
        raise ValueError("private directory must be absolute and have no symlink component")
    path.mkdir(mode=0o700, exist_ok=True)
    value = path.lstat()
    if not stat.S_ISDIR(value.st_mode) or value.st_uid != os.getuid() or stat.S_IMODE(value.st_mode) != 0o700:
        raise ValueError("private directory must be owned and mode 0700")
    return path


# Adapted from sdk-python 377a3eee workspace_guards.py. The qualified
# image checks the actual acquired bind before executing its native binary.
NATIVE_PREFLIGHT = """set -eu
test -d "$1" && test ! -L "$1" || exit 78
test -f "$1/$2" && test ! -L "$1/$2" || exit 78
test "$(stat -c '%d:%i:%u:%g:%a:%h' -- "$1")" = "$3" || exit 78
test "$(stat -c '%d:%i:%u:%g:%a:%h' -- "$1/$2")" = "$4" || exit 78
shift 4
exec /usr/local/bin/durable-workflow-server "$@"
"""


class HeldDirectory:
    """Keep an owned directory inode, including its no-follow parent binding."""
    def __init__(self, path, parent=None):
        self.path, self.parent = Path(path), parent
        if not self.path.is_absolute() or self.path.resolve() != self.path:
            raise ValueError("directory must be canonical without symlinks")
        if parent is not None:
            parent.recheck()
            if self.path.parent != parent.path:
                raise ValueError("held directory must be a direct child")
        self.fd = os.open(self.path if parent is None else self.path.name,
                          os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC,
                          dir_fd=None if parent is None else parent.fd)
        self.identity = self.key(os.fstat(self.fd))
        try:
            self.recheck()
        except BaseException:
            self.close()
            raise

    @staticmethod
    def key(value):
        return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid, value.st_nlink)

    def recheck(self):
        if self.parent is not None:
            self.parent.recheck()
        value = os.fstat(self.fd)
        named = os.stat(self.path if self.parent is None else self.path.name,
                        dir_fd=None if self.parent is None else self.parent.fd, follow_symlinks=False)
        if (not stat.S_ISDIR(value.st_mode) or value.st_uid != os.getuid()
                or stat.S_IMODE(value.st_mode) != 0o700 or value.st_nlink < 2
                or self.key(value) != self.identity or self.key(named) != self.identity
                or self.path.resolve() != self.path or self.key(self.path.lstat()) != self.identity):
            raise ValueError("held directory identity changed")

    @property
    def anchor(self):
        return "/proc/" + str(os.getpid()) + "/fd/" + str(self.fd)

    def close(self):
        os.close(self.fd)


class HeldWritableFile:
    """Mutable SQLite data or a frozen report, always tied to one parent/inode."""
    def __init__(self, parent, name, *, create=False, maximum=64 * 1024 * 1024):
        if Path(name).name != name or name in (".", ".."):
            raise ValueError("held file requires one filename")
        self.parent, self.name, self.maximum, self.digest = parent, name, maximum, None
        parent.recheck()
        flags = os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC
        if create:
            flags |= os.O_CREAT | os.O_EXCL
        self.fd = os.open(name, flags, 0o600, dir_fd=parent.fd)
        self.identity = HeldDirectory.key(os.fstat(self.fd))
        try:
            self.recheck()
        except BaseException:
            self.close()
            raise

    def recheck(self):
        self.parent.recheck()
        value = os.fstat(self.fd)
        named = os.stat(self.name, dir_fd=self.parent.fd, follow_symlinks=False)
        if (not stat.S_ISREG(value.st_mode) or value.st_uid != os.getuid() or value.st_nlink != 1
                or stat.S_IMODE(value.st_mode) != 0o600 or not 0 <= value.st_size <= self.maximum
                or HeldDirectory.key(value) != self.identity or HeldDirectory.key(named) != self.identity):
            raise ValueError("held writable file identity changed")
        if self.digest is not None and hashlib.sha256(os.pread(self.fd, self.maximum + 1, 0)).hexdigest() != self.digest:
            raise ValueError("held report content changed")

    @property
    def anchor(self):
        return "/proc/" + str(os.getpid()) + "/fd/" + str(self.fd)

    def write(self, data, *, freeze=False):
        self.recheck()
        if len(data) > self.maximum:
            raise ValueError("held file byte bound exceeded")
        self.digest = None
        os.ftruncate(self.fd, 0)
        offset = 0
        while offset < len(data):
            count = os.pwrite(self.fd, data[offset:], offset)
            if count <= 0:
                raise OSError("held file write made no progress")
            offset += count
        os.fsync(self.fd)
        self.digest = hashlib.sha256(data).hexdigest() if freeze else None
        self.recheck()

    def close(self):
        os.close(self.fd)


class Workspace:
    """Bind comparison, Docker data, and terminal evidence to held objects."""
    def __init__(self, root):
        for name in ("home", "config", "tmp", "docker-empty", "bin"):
            private_directory(Path(root) / name)
        self.root = HeldDirectory(root)
        self.data = HeldDirectory(Path(root) / "data", self.root)
        self.files = {}
        self.children = {name: HeldDirectory(Path(root) / name, self.root)
                         for name in ("home", "config", "tmp", "docker-empty", "bin")}
        try:
            # The first phase creates the file; later phases hold the existing
            # inode. Server mutates it across restarts and schema upgrades.
            exists = os.path.lexists(Path(root) / "data/database.sqlite")
            self.database = HeldWritableFile(self.data, "database.sqlite", create=not exists)
            self.recheck()
        except BaseException:
            self.data.close()
            self.root.close()
            raise

    def recheck(self):
        self.root.recheck()
        self.data.recheck()
        self.database.recheck()
        for value in self.children.values():
            value.recheck()
        for value in self.files.values():
            value.recheck()

    def evidence(self):
        self.recheck()
        return {"root": list(self.root.identity), "data": list(self.data.identity),
                "database": list(self.database.identity)}

    def connection(self, *, readonly=True):
        from contextlib import contextmanager
        import sqlite3

        @contextmanager
        def opened():
            self.recheck()
            # The directory descriptor preserves SQLite's WAL/SHM sibling
            # semantics. Check SQLite's opened main file against our held DB.
            uri = "file:" + self.data.anchor + "/database.sqlite?mode=" + ("ro" if readonly else "rw")
            db = sqlite3.connect(uri, uri=True, timeout=2)
            try:
                named = db.execute("PRAGMA database_list").fetchone()[2]
                if HeldDirectory.key(os.stat(named)) != self.database.identity:
                    raise ValueError("SQLite opened a different database")
                self.recheck()
                yield db
            finally:
                db.close()
                self.recheck()
        return opened()

    def mount_arguments(self):
        self.recheck()
        return ("--mount", "type=bind,source=" + str(self.data.path) + ",target=/app/database")

    def native_arguments(self, *arguments):
        self.recheck()
        def identity(descriptor):
            value = os.fstat(descriptor)
            return f"{value.st_dev}:{value.st_ino}:{value.st_uid}:{value.st_gid}:{stat.S_IMODE(value.st_mode):o}:{value.st_nlink}"
        return ("-c", NATIVE_PREFLIGHT, "checked-native", "/app/database", "database.sqlite",
                identity(self.data.fd), identity(self.database.fd), *arguments)

    def inspect_mounts(self, value):
        self.recheck()
        matches = [item for item in value["Mounts"] if item["Destination"] == "/app/database"]
        if (len(matches) != 1 or matches[0]["Type"] != "bind" or matches[0]["Source"] != str(self.data.path)
                or matches[0]["RW"] is not True):
            raise ValueError("Docker did not acquire the guarded canonical data bind")
        self.recheck()

    def child_anchor(self, name):
        return self.children[name].anchor

    def inherited(self):
        return tuple(item.fd for item in [*self.children.values(), *self.files.values()])

    def read(self, name):
        self.recheck()
        if name not in self.files:
            self.files[name] = HeldWritableFile(self.root, name, maximum=32 * 1024 * 1024)
        item = self.files[name]
        item.recheck()
        data = os.pread(item.fd, item.maximum + 1, 0)
        item.digest = hashlib.sha256(data).hexdigest()
        self.recheck()
        return data

    def write(self, name, data):
        self.recheck()
        if name not in self.files:
            exists = os.path.lexists(self.root.path / name)
            self.files[name] = HeldWritableFile(self.root, name, create=not exists, maximum=32 * 1024 * 1024)
        self.files[name].write(data, freeze=True)
        self.recheck()

    def unlink(self, name):
        # Unlink only the captured entry through its held parent; root-path
        # refusal must not prevent credential cleanup or touch a replacement.
        item = self.files.get(name)
        if item is not None:
            value = os.stat(item.name, dir_fd=item.parent.fd, follow_symlinks=False)
            if HeldDirectory.key(value) != item.identity or HeldDirectory.key(os.fstat(item.fd)) != item.identity:
                raise ValueError("cleanup entry was replaced; preserving it")
            os.unlink(name, dir_fd=self.root.fd)
            item.close()
            del self.files[name]

    def close(self):
        for value in self.files.values():
            value.close()
        self.database.close()
        for value in self.children.values():
            value.close()
        self.data.close()
        self.root.close()


class OwnedContainer:
    def __init__(self, docker, name, workspace=None):
        self.docker, self.name, self.id = docker, name, None
        self.workspace = workspace
        self.created_ids = []

    def run(self, *arguments):
        if self.workspace is not None:
            self.workspace.recheck()
        if self.exists() or self.docker("ps", "-aq", "--filter", "name=^" + self.name + "$"):
            raise ValueError("container name already exists; no cleanup authority acquired")
        created = self.docker("run", *arguments)
        if not re.fullmatch(r"[0-9a-f]{64}", created, re.ASCII):
            raise ValueError("Docker did not return one canonical created container ID")
        self.id = created
        self.created_ids.append(created)
        return self.inspect()

    def exists(self):
        return self.id is not None and self.docker("ps", "-aq", "--no-trunc", "--filter", "id=" + self.id) == self.id

    def inspect(self, *, check_workspace=True):
        if check_workspace and self.workspace is not None:
            self.workspace.recheck()
        if not self.exists():
            raise ValueError("created container no longer exists")
        value = json.loads(self.docker("inspect", self.id, "--format", "{{json .}}"))
        if value["Id"] != self.id or value["Name"] != "/" + self.name:
            raise ValueError("created container ID/name changed")
        if check_workspace and self.workspace is not None:
            self.workspace.inspect_mounts(value)
        return value

    def stop(self):
        self.inspect()
        self.docker("stop", "--time=10", self.id)
        if self.workspace is not None:
            self.workspace.recheck()

    def remove(self):
        if self.exists():
            # A failed workspace check must not prevent exact-ID cleanup.
            self.inspect(check_workspace=False)
            self.docker("rm", "-fv", self.id)
