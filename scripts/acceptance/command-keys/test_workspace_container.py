"""Opt-in local Docker boundary controls, not installed CLI conformance."""
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("container_boundaries", SOURCE / "boundaries.py")
boundary = importlib.util.module_from_spec(spec)
spec.loader.exec_module(boundary)
IMAGE = "ghcr.io/durable-workflow/server@sha256:8ffcdb2a36af3129a1987849aafe8715fe0d3bf9a9648467f0b5cb8eeef43ba9"


@unittest.skipUnless(os.environ.get("CLI_216_CONTAINER_CONTROLS") == "1", "explicit local Docker control required")
class ContainerWorkspaceTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name).resolve() / "fixture"
        self.root.mkdir(mode=0o700)
        (self.root / "data").mkdir(mode=0o700)
        self.workspace = boundary.Workspace(self.root)
        self.addCleanup(self.workspace.close)
        self.created = []
        self.addCleanup(self.cleanup)

    def docker(self, *arguments):
        result = subprocess.run(["docker", "--config", "/proc/self/fd/" + str(self.workspace.children["docker-empty"].fd), *arguments],
                                env={"PATH": os.defpath, "HOME": self.workspace.child_anchor("home")},
                                pass_fds=self.workspace.inherited(), capture_output=True, text=True, timeout=40)
        if result.returncode:
            raise RuntimeError("local Docker control failed: " + result.stderr[:1000])
        return (result.stdout + (result.stderr if arguments[0] == "logs" else "")).strip()

    def cleanup(self):
        for owned in self.created:
            owned.remove()
            self.assertFalse(owned.exists(), "exact created control ID remains")
            print(json.dumps({"control": self.id(), "created_id": owned.id, "absent": True}), flush=True)

    def run_container(self, *extra, command):
        name = "cli-152-boundary-" + secrets.token_hex(5)
        owned = boundary.OwnedContainer(self.docker, name)
        self.created.append(owned)
        owned.run("-d", "--name", name, "--platform=linux/amd64", "--init", "--cpus=1", "--memory=512m",
                  "--pids-limit=128", "--read-only", "--network=none", "--cap-drop=ALL",
                  "--security-opt=no-new-privileges", "--entrypoint=/bin/sh", "--env", "DB_CONNECTION=sqlite", *extra, IMAGE, *command)
        code = self.docker("wait", owned.id)
        state = owned.inspect()["State"]
        self.assertFalse(state["OOMKilled"])
        return code, self.docker("logs", owned.id)

    def test_native_exec_on_actual_guarded_canonical_mount(self):
        mounts = self.workspace.mount_arguments()
        native = self.workspace.native_arguments("schema-check")
        code, output = self.run_container(*mounts, "--tmpfs", "/tmp:rw,size=16m", command=native)
        # This finite native command intentionally refuses SQLite. Its exact
        # native diagnostic proves the mount preflight reached exec, without
        # starting a runtime or performing an installed upgrade cohort.
        self.assertEqual(code, "1", output)
        self.assertIn("these development schema commands require DB_CONNECTION=pgsql, mysql or mariadb", output)
        self.workspace.recheck()
        self.workspace.inspect_mounts(self.created[-1].inspect())
        self.assertEqual(os.fstat(self.workspace.database.fd).st_size, 0)

    def replacement_refused(self, target):
        mounts = self.workspace.mount_arguments()
        native = self.workspace.native_arguments("schema-check")
        path = self.root / "data" if target == "data" else self.root / "data/database.sqlite"
        original = path.with_name(path.name + "-original")
        path.rename(original)
        if target == "data":
            shutil.copytree(original, path)
        else:
            path.write_bytes(b"")
            path.chmod(0o600)
        code, output = self.run_container(*mounts, command=native)
        self.assertEqual(code, "78")
        self.assertEqual(output, "")
        self.assertEqual((self.root / "data/database.sqlite").stat().st_size, 0,
                         "native bootstrap executed against replacement")
        with self.assertRaises(ValueError):
            self.workspace.recheck()

    def test_actual_preflight_refuses_directory_replacement_before_native_exec(self):
        self.replacement_refused("data")

    def test_actual_preflight_refuses_db_replacement_before_native_exec(self):
        self.replacement_refused("database")

    def test_actual_docker_env_reader_uses_held_descriptor(self):
        self.workspace.write("runtime.env", b"CLI_FIXTURE_VALUE=held\n")
        descriptor = self.workspace.files["runtime.env"].fd
        path = self.root / "runtime.env"
        path.rename(self.root / "original-env")
        path.write_text("CLI_FIXTURE_VALUE=forged\n")
        path.chmod(0o600)
        # A pathname reader would consume the forged value. The actual Docker
        # client inherits the original FD, even after that pathname changes.
        self.assertIn("forged", path.read_text())
        code, output = self.run_container("--env-file", "/proc/self/fd/" + str(descriptor),
                                          command=("-c", 'printf %s "$CLI_FIXTURE_VALUE"'))
        self.assertEqual((code, output), ("0", "held"))
        with self.assertRaises(ValueError):
            self.workspace.recheck()

    def test_actual_docker_config_reader_and_cleanup_ignore_replaced_path(self):
        path = self.root / "docker-empty"
        path.rename(self.root / "original-docker")
        path.mkdir(mode=0o700)
        (path / "config.json").write_text('{"currentContext":"not-an-owned-context"}')
        old = subprocess.run(["docker", "--config", str(path), "info", "--format", "{{.Name}}"],
                             env={"PATH": os.defpath}, capture_output=True, text=True, timeout=10)
        self.assertNotEqual(old.returncode, 0)
        code, output = self.run_container(command=("-c", "printf held-config"))
        self.assertEqual((code, output), ("0", "held-config"))
        with self.assertRaises(ValueError):
            self.workspace.recheck()
        # addCleanup still uses the held config FD and captured container ID.


if __name__ == "__main__":
    unittest.main()
