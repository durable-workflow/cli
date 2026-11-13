"""Pinned SPC data acquisition and Linux immutable execution for the legacy builder."""
import argparse
import fcntl
import hashlib
import os
from pathlib import Path
import platform
import secrets
import stat
import subprocess
import sys
import time

VERSION = "2.8.5"
BWRAP = "/usr/bin/bwrap"
# asset id, archive sha/size, sole-member sha/size
ARTIFACTS = {
    "linux-x86_64": (399146238, "523ba4279c54c7a377156c0dd3a36adf92ee64b01e9a7f5e9e2ec084b8e458e5", 4170453,
                     "d1e8815bbf6b1c98324d3a0c93af3a40b9ba1e4a1af4021921d19d14b244afcd", 4378067),
    "linux-aarch64": (399146236, "675a3840dcdc4ed041fe20eaa54310ce019a9984c1c03951df9ec66df5795213", 4301050,
                      "e82524fa737790c8189eb8211a53298e63ccd81b8b87f776c9fa45180d62c43b", 4526270),
    "macos-x86_64": (399146531, "e8b798048f62ca4960764196543b60ae703f7174aa418824cf542aeec1d2cd6a", 5024260,
                     "c5f1ec6285fc259e4a081909183f1f458ffdfbf4ab05b097fc082bd21130d43c", 9232412),
    "macos-aarch64": (399146400, "acf2f25d56d0cbf8e65aa82e5054fef555f7be7c5c38046c6e0819f266d83225", 4916396,
                      "f21cd8e5032a27000369470909fbe724fa81d7b41e1de35fa9288e6115a557e1", 9222300),
}


class Refused(RuntimeError):
    pass


def execution_environment():
    # The compiler's selected tools and reproducibility clock are explicit.
    # Do not forward loader, credential or language-startup environment hooks.
    result = {key: os.environ[key] for key in ("PATH", "HOME", "TMPDIR", "SOURCE_DATE_EPOCH") if key in os.environ}
    result["LC_ALL"] = "C"
    return result


def identity(value):
    return (value.st_dev, value.st_ino, value.st_mode, value.st_uid, value.st_gid,
            value.st_nlink, value.st_size, value.st_mtime_ns, value.st_ctime_ns)


class TrustedBwrap:
    """Bind the root-controlled namespace helper for this invocation."""
    def __init__(self):
        self.fd = None
        try:
            self.check_parents()
            self.fd = os.open(BWRAP, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
            value = os.fstat(self.fd)
            if (not stat.S_ISREG(value.st_mode) or value.st_uid != 0 or value.st_nlink != 1
                    or value.st_mode & (0o022 | stat.S_ISUID | stat.S_ISGID)
                    or not value.st_mode & 0o111 or not 0 < value.st_size <= 4 * 1024 * 1024):
                raise Refused("bubblewrap must be an unprivileged root-controlled regular executable")
            if "security.capability" in os.listxattr(self.fd):
                raise Refused("bubblewrap file capabilities are unsupported")
            self.expected = identity(value)
            self.digest = hashlib.sha256(os.pread(self.fd, value.st_size + 1, 0)).hexdigest()
            self.recheck()
        except BaseException:
            self.close()
            raise

    @staticmethod
    def check_parents():
        for parent in Path(BWRAP).parents:
            value = parent.lstat()
            if not stat.S_ISDIR(value.st_mode) or value.st_uid != 0 or value.st_mode & 0o022:
                raise Refused("bubblewrap parents must be root-controlled directories without links")

    def recheck(self):
        self.check_parents()
        if (identity(os.fstat(self.fd)) != self.expected or identity(Path(BWRAP).lstat()) != self.expected
                or hashlib.sha256(os.pread(self.fd, self.expected[6] + 1, 0)).hexdigest() != self.digest):
            raise Refused("bubblewrap identity changed")

    def close(self):
        if self.fd is not None:
            os.close(self.fd)
            self.fd = None


def read_verified(path, digest, size):
    """Capture only the exact owned, no-follow, single-link regular inode."""
    path = Path(path)
    if not path.is_absolute() or path.resolve() != path:
        raise Refused("artifact path must be absolute and canonical")
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC)
    try:
        before = os.fstat(fd)
        if (not stat.S_ISREG(before.st_mode) or before.st_uid != os.getuid() or before.st_nlink != 1
                or before.st_mode & 0o022 or before.st_size != size):
            raise Refused("artifact inode owner/type/link/mode/size differs")
        data = os.pread(fd, size + 1, 0)
        if len(data) != size or hashlib.sha256(data).hexdigest() != digest:
            raise Refused("artifact descriptor digest differs")
        if identity(os.fstat(fd)) != identity(before) or identity(path.lstat()) != identity(before):
            raise Refused("artifact identity changed during capture")
        return fd, data
    except BaseException:
        os.close(fd)
        raise


def publish(data, destination):
    """One atomic no-clobber link publishes complete inert bytes, never mv-overwrite."""
    destination = Path(destination)
    parent = destination.parent
    if not destination.is_absolute() or parent.resolve() != parent:
        raise Refused("publication parent must be canonical")
    directory = os.open(parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC)
    temporary = ".spc-publish-" + secrets.token_hex(16)
    descriptor = None
    try:
        folder = os.fstat(directory)
        if folder.st_uid != os.getuid() or folder.st_mode & 0o022:
            raise Refused("publication directory must be owner-controlled")
        descriptor = os.open(temporary, os.O_RDWR | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW | os.O_CLOEXEC,
                             0o600, dir_fd=directory)
        with os.fdopen(os.dup(descriptor), "wb") as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        # The cache is data. Only the sealed execution descriptor gets x bits.
        os.fchmod(descriptor, 0o400)
        expected = os.fstat(descriptor)
        os.link(temporary, destination.name, src_dir_fd=directory, dst_dir_fd=directory, follow_symlinks=False)
        linked = os.open(destination.name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC,
                         dir_fd=directory)
        try:
            current = os.fstat(linked)
            if (current.st_dev, current.st_ino) != (expected.st_dev, expected.st_ino):
                raise Refused("publication linked a different inode")
            if os.pread(linked, len(data) + 1, 0) != data:
                raise Refused("published descriptor bytes differ")
        finally:
            os.close(linked)
        if (parent.lstat().st_dev, parent.lstat().st_ino) != (folder.st_dev, folder.st_ino):
            raise Refused("publication parent changed")
    finally:
        if descriptor is not None:
            try:
                named = os.stat(temporary, dir_fd=directory, follow_symlinks=False)
                held = os.fstat(descriptor)
                if (named.st_dev, named.st_ino) == (held.st_dev, held.st_ino):
                    os.unlink(temporary, dir_fd=directory)
            except FileNotFoundError:
                pass
            os.close(descriptor)
        os.close(directory)


def can_execute(artifact_platform):
    if sys.platform == "darwin":
        raise Refused("secure legacy macOS SPC execution is unavailable; use the supported native Rust CLI or a qualified Linux legacy build")
    if sys.platform != "linux" or not hasattr(os, "memfd_create"):
        raise Refused("SPC execution requires Linux sealed-memory file descriptors")
    if not os.access(BWRAP, os.X_OK):
        raise Refused("SPC execution requires bubblewrap at /usr/bin/bwrap with unprivileged user namespaces")
    machine = {"AMD64": "x86_64", "arm64": "aarch64"}.get(platform.machine(), platform.machine())
    if artifact_platform != "linux-" + machine:
        raise Refused("SPC platform does not match the executing Linux architecture")
    helper = TrustedBwrap()
    try:
        check = subprocess.run(namespace_command() + ["/bin/true"], env=execution_environment(), capture_output=True, text=True)
        helper.recheck()
        if check.returncode:
            raise Refused("SPC requires working bubblewrap unprivileged user namespaces: " + check.stderr.strip())
    finally:
        helper.close()


def namespace_command(descriptor=None):
    command = [BWRAP, "--die-with-parent", "--new-session", "--unshare-user", "--unshare-pid",
               "--disable-userns", "--cap-drop", "ALL", "--bind", "/", "/", "--proc", "/proc",
               "--dev", "/dev", "--dir", "/dev/shm"]
    if descriptor is not None:
        command += ["--perms", "0500", "--ro-bind-data", str(descriptor), "/dev/shm/cli-spc"]
    return command + ["--remount-ro", "/dev", "--remount-ro", "/proc", "--"]


class HeldExecutable:
    def __init__(self, path, digest, size):
        self.source_fd, data = read_verified(path, digest, size)
        self.fd = None
        self.helper = None
        try:
            self.helper = TrustedBwrap()
            self.environment = execution_environment()
            self.fd = os.memfd_create("cli-spc", os.MFD_CLOEXEC | os.MFD_ALLOW_SEALING)
            with os.fdopen(os.dup(self.fd), "wb") as output:
                output.write(data)
            os.fchmod(self.fd, 0o500)
            self.seals = fcntl.F_SEAL_WRITE | fcntl.F_SEAL_GROW | fcntl.F_SEAL_SHRINK | fcntl.F_SEAL_SEAL
            fcntl.fcntl(self.fd, fcntl.F_ADD_SEALS, self.seals)
            self.digest, self.size = digest, size
            self.expected = identity(os.fstat(self.fd))[:7]
            self.recheck()
        except BaseException:
            self.close()
            raise

    def recheck(self):
        self.helper.recheck()
        if fcntl.fcntl(self.fd, fcntl.F_GET_SEALS) & self.seals != self.seals:
            raise Refused("SPC execution descriptor is not sealed")
        if identity(os.fstat(self.fd))[:7] != self.expected or hashlib.sha256(os.pread(self.fd, self.size + 1, 0)).hexdigest() != self.digest:
            raise Refused("SPC execution descriptor bytes changed")

    def run(self, arguments, *, cwd):
        self.recheck()
        # Micro canonicalizes AT_EXECFN and reopens that pathname, so direct
        # anonymous-FD execution fails. Bubblewrap copies only the held sealed
        # bytes into a read-only private mount. Its parent is also read-only,
        # capabilities are dropped and nested user namespaces are disabled.
        # This binds SPC itself; the build still uses its host toolchain.
        os.lseek(self.fd, 0, os.SEEK_SET)
        result = subprocess.run(namespace_command(self.fd) + ["/dev/shm/cli-spc", *arguments],
                                pass_fds=(self.fd,), cwd=cwd, env=self.environment)
        self.recheck()
        return result.returncode

    def close(self):
        if self.helper is not None:
            self.helper.close()
            self.helper = None
        for name in ("fd", "source_fd"):
            value = getattr(self, name, None)
            if value is not None:
                os.close(value)
                setattr(self, name, None)


def pipeline(holder, php, extensions, retry, attempts, phar, output, directory):
    for attempt in range(1, attempts + 1):
        code = holder.run(["download", "--with-php=" + php, "--for-extensions=" + extensions,
                           "--prefer-pre-built", "--without-suggestions", "--retry=" + str(retry)], cwd=directory)
        if code == 0:
            break
        if attempt == attempts:
            raise Refused("spc dependency download failed after " + str(attempts) + " attempts")
        delay = attempt * 30
        print(f">> spc dependency download attempt {attempt}/{attempts} failed; retrying in {delay}s", file=sys.stderr)
        time.sleep(delay)
    code = holder.run(["build", extensions, "--build-micro"], cwd=directory)
    if code:
        raise Refused("spc build failed with exit " + str(code))
    code = holder.run(["micro:combine", phar, "--output=" + output], cwd=directory)
    if code:
        raise Refused("spc micro:combine failed with exit " + str(code))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("role", choices=("metadata", "publish", "check", "run"))
    parser.add_argument("platform", choices=tuple(ARTIFACTS))
    parser.add_argument("arguments", nargs="*")
    args = parser.parse_args()
    asset, archive_sha, archive_size, digest, size = ARTIFACTS[args.platform]
    if args.role == "metadata":
        url = f"https://github.com/crazywhalecc/static-php-cli/releases/download/{VERSION}/spc-{args.platform}.tar.gz"
        print(VERSION, asset, archive_sha, archive_size, digest, size, url)
    elif args.role == "check":
        can_execute(args.platform)
    elif args.role == "publish":
        if len(args.arguments) != 2:
            raise Refused("publish requires staged member and cache destination")
        descriptor, data = read_verified(args.arguments[0], digest, size)
        try:
            publish(data, args.arguments[1])
        finally:
            os.close(descriptor)
    else:
        can_execute(args.platform)
        if len(args.arguments) != 8:
            raise Refused("run requires cache/php/extensions/retry/attempts/phar/output/directory")
        cache, php, extensions, retry, attempts, phar, output, directory = args.arguments
        if not retry.isascii() or not retry.isdecimal() or not attempts.isascii() or not attempts.isdecimal() or int(attempts) < 1:
            raise Refused("SPC retries must be nonnegative and attempts positive integers")
        holder = HeldExecutable(cache, digest, size)
        try:
            pipeline(holder, php, extensions, int(retry), int(attempts), phar, output, directory)
        finally:
            holder.close()


if __name__ == "__main__":
    try:
        main()
    except (Refused, OSError) as error:
        print("SPC refused: " + str(error), file=sys.stderr)
        raise SystemExit(1)
