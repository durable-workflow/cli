#!/usr/bin/env python3
"""Pack or inspect the two regular files in a private verifier-tool artifact."""

from __future__ import annotations

import io
import json
from pathlib import Path
import stat
import sys
import tarfile

MAX_BINARY = 64 * 1024 * 1024
MAX_MANIFEST = 16 * 1024
MAX_ARCHIVE = MAX_BINARY + MAX_MANIFEST + 32 * 1024
MANIFEST = "verifier-tool.json"


def regular_bytes(filename: Path, maximum: int) -> bytes:
    value = filename.lstat()
    if not stat.S_ISREG(value.st_mode) or not 0 < value.st_size <= maximum:
        raise ValueError("Private tool inputs must be bounded nonempty regular files")
    return filename.read_bytes()


def binary_name(name: str) -> str:
    if name not in ("php", "php.exe"):
        raise ValueError("Unknown private verifier executable name")
    return name


def pack(executable: Path, manifest: Path, destination: Path, name: str) -> None:
    members = ((binary_name(name), regular_bytes(executable, MAX_BINARY)),
               (MANIFEST, regular_bytes(manifest, MAX_MANIFEST)))
    with destination.open("xb") as output:
        with tarfile.open(fileobj=output, mode="w", format=tarfile.USTAR_FORMAT) as archive:
            for member, contents in members:
                info = tarfile.TarInfo(member)
                info.size = len(contents)
                info.mode = 0o755 if member == name else 0o644
                archive.addfile(info, io.BytesIO(contents))


def read(filename: Path, name: str) -> dict[str, bytes]:
    contents = regular_bytes(filename, MAX_ARCHIVE)
    expected = {binary_name(name): MAX_BINARY, MANIFEST: MAX_MANIFEST}
    members = {}
    with io.BytesIO(contents) as source:
        with tarfile.open(fileobj=source, mode="r:") as archive:
            for member in archive:
                if (member.name not in expected or member.name in members or not member.isreg()
                        or member.pax_headers or member.issparse()
                        or not 0 < member.size <= expected[member.name]):
                    raise ValueError("Private tool archive does not have the closed regular-file inventory")
                entry = archive.extractfile(member)
                if entry is None:
                    raise ValueError("Private tool archive member cannot be read")
                with entry:
                    data = entry.read(expected[member.name] + 1)
                if len(data) != member.size:
                    raise ValueError("Private tool archive member size differs")
                members[member.name] = data
            if set(members) != set(expected) or any(source.read()):
                raise ValueError("Private tool archive has missing members or nonzero trailing data")
    return members


def unpack(filename: Path, destination: Path, name: str) -> None:
    members = read(filename, name)
    if destination.is_symlink() or not destination.is_dir() or any(destination.iterdir()):
        raise ValueError("Private tool extraction requires an empty owned directory")
    for member, contents in members.items():
        target = destination / member
        with target.open("xb") as output:
            output.write(contents)
        target.chmod(0o755 if member == name else 0o600)


def main() -> None:
    phase, *arguments = sys.argv[1:]
    if phase == "pack" and len(arguments) == 4:
        executable, manifest, destination, name = arguments
        pack(Path(executable), Path(manifest), Path(destination), name)
    elif phase == "unpack" and len(arguments) == 3:
        filename, destination, name = arguments
        unpack(Path(filename), Path(destination), name)
    elif phase == "inspect" and len(arguments) == 2:
        filename, name = arguments
        members = read(Path(filename), name)
        print(json.dumps({"members": sorted(members)}))
    else:
        raise ValueError("Use pack, unpack or inspect with exact private-tool paths")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, tarfile.TarError, IndexError) as error:
        print(f"Private verifier archive refused: {error}", file=sys.stderr)
        sys.exit(1)
