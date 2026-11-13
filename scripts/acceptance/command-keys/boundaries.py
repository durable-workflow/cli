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


class OwnedContainer:
    def __init__(self, docker, name):
        self.docker, self.name, self.id = docker, name, None
        self.created_ids = []

    def run(self, *arguments):
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

    def inspect(self):
        if not self.exists():
            raise ValueError("created container no longer exists")
        value = json.loads(self.docker("inspect", self.id, "--format", "{{json .}}"))
        if value["Id"] != self.id or value["Name"] != "/" + self.name:
            raise ValueError("created container ID/name changed")
        return value

    def stop(self):
        self.inspect()
        self.docker("stop", "--time=10", self.id)

    def remove(self):
        if self.exists():
            self.inspect()
            self.docker("rm", "-fv", self.id)
