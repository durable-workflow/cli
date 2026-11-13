"""Verify the selected command-key implementation before executing its bytes."""
import hashlib
import os
from pathlib import Path
import stat
import types

if __name__ == "__main__" and "BOOTSTRAP_LAUNCHER_SHA256" not in globals():
    raise SystemExit("Use the reviewed literal -c Git bootstrap before the dispatcher")

SOURCE = Path(__file__).resolve().parent
BOUNDARY_SHA = "49f44b870296b27955f116295f4e177a1fd285cc976809c13e5f57dc3cb1ebbf"
IMPLEMENTATIONS = {
    'modern': ('cli_216.py', '507ff78a1a25bca43f4b5ce84abd395d59d24641bbac6db8c6ee86a665113417'),
    'legacy': ('legacy_cli.py', 'cc4018a807ff01e557676fa7ff4cb73f30872b004d04b42338fe96f65e18aec2'),
    'physical': ('physical_impl.py', '640a3a8b9e3c802e965971ca1e8980200493513a81249a9265817b9a9bce3ba4'),
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
