"""Verify the selected command-key implementation before executing its bytes."""
import hashlib
import os
from pathlib import Path
import stat
import types

SOURCE = Path(__file__).resolve().parent
BOUNDARY_SHA = "8e1374d497741cf1668c43bd884af69f72282448bc7151d00f6ff4718c4546b8"
IMPLEMENTATIONS = {
    'modern': ('cli_216.py', 'cd8638f7594b8fd72ac0e72dd44d38f6be27167430518b4bebd6c5c244c7c0cd'),
    'legacy': ('legacy_cli.py', '7324676373db9d44c63423fc50cb995a9040a791fa16a26a04690f00d5b0259e'),
    'physical': ('physical_impl.py', 'f1babc7103039e18e3d88a9d780926e5b73a0447ba17b56173d9dffd06c1c353'),
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
                 "BOOTSTRAP_HELD_BOUNDARY": boundary_input}
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
