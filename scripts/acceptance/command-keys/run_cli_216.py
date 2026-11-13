"""Verify the selected command-key implementation before executing its bytes."""
import hashlib
import os
from pathlib import Path
import stat
import types

if __name__ == "__main__" and "BOOTSTRAP_LAUNCHER_SHA256" not in globals():
    raise SystemExit("Use the reviewed literal -c Git bootstrap before the dispatcher")

SOURCE = Path(__file__).resolve().parent
BOUNDARY_SHA = "5de532a128931b56bd7ca2232d60e07a2fa65d550cfbf56d9cd78d16ed3a5095"
IMPLEMENTATIONS = {
    'modern': ('cli_216.py', 'f8a51ec2119cd24acf6b861b0a3c09e5ab7f651539136db506d6d8ef4b8e448b'),
    'legacy': ('legacy_cli.py', 'c2d19bd944bfb1cca7d370de923be2ccbd788f51b7ed4131622de691d58a08eb'),
    'physical': ('physical_impl.py', '446676dbfb755cc2f3aebeffdcf56977d3d064e2796bf4f86379bda0cf341ddc'),
}


def load_boundary():
    path = SOURCE / "boundaries.py"
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        observed = os.fstat(descriptor)
        if (not stat.S_ISREG(observed.st_mode) or observed.st_uid != os.getuid()
                or observed.st_nlink != 1 or observed.st_mode & 0o022 or observed.st_size > 65536):
            raise ValueError("boundary source owner/type/mode differs")
        data = os.pread(descriptor, 65537, 0)
        if hashlib.sha256(data).hexdigest() != BOUNDARY_SHA:
            raise ValueError("boundary source content differs")
        module = types.ModuleType("physical_bootstrap_boundary")
        exec(compile(data, str(path), "exec"), module.__dict__)
        held = module.HeldInput(path, BOUNDARY_SHA)
        if held.identity != held._identity(observed):
            held.close()
            raise ValueError("boundary source changed during held import")
        return module, held
    finally:
        os.close(descriptor)


def run_implementation(path, digest, boundary, boundary_input):
    implementation = boundary.HeldInput(path, digest, maximum=131072)
    try:
        scope = {"__name__": "__main__", "__file__": str(implementation.path),
                 "BOOTSTRAP_HELD_IMPLEMENTATION": implementation,
                 "BOOTSTRAP_HELD_BOUNDARY": boundary_input,
                 "BOOTSTRAP_LAUNCHER_SHA256": globals().get("BOOTSTRAP_LAUNCHER_SHA256"),
                 "BOOTSTRAP_SOURCE_COMMIT": globals().get("BOOTSTRAP_SOURCE_COMMIT")}
        # The literal digest and held bytes precede every implementation
        # effect, including imports and source-provenance capture.
        exec(compile(implementation.data, str(implementation.path), "exec"), scope)
        return scope
    finally:
        try:
            implementation.recheck()
            boundary_input.recheck()
        finally:
            implementation.close()
            boundary_input.close()


if __name__ == "__main__":
    choice = os.environ.get("CLI_216_RUNNER", "modern")
    if choice not in IMPLEMENTATIONS:
        raise SystemExit("CLI_216_RUNNER must be modern, legacy or physical")
    filename, expected = IMPLEMENTATIONS[choice]
    trusted_boundary, held_boundary = load_boundary()
    run_implementation(SOURCE / filename, expected, trusted_boundary, held_boundary)
