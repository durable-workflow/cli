"""Verify the selected command-key implementation before executing its bytes."""
import hashlib
import os
from pathlib import Path
import stat
import types

SOURCE = Path(__file__).resolve().parent
BOUNDARY_SHA = "d377e9191ebf79dc990db159629c303c10531a2f27d3ae20933fabc87e3b3525"
IMPLEMENTATIONS = {
    'modern': ('cli_216.py', 'e26565125bb122e6d84cd6a6e230c7d47a56ca043eda7bffa7025b9c19fdbbbe'),
    'legacy': ('legacy_cli.py', '0f0ceb09676d38109bbd57ed9c4d22e0c448a78c65999fe82a5feb3b3ce1062b'),
    'physical': ('physical_impl.py', '81ff88b11a7c402d2206c2856b08080eb149528b31cd0f616ccd676e459fcd3f'),
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
