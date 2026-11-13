"""Run legacy builder trust controls in the existing host-Python parity lane.

The qualified PHP test images intentionally do not supply host Python. Keep
these Python/descriptor controls in the lane that already owns that runtime.
"""
import importlib.util
from pathlib import Path


def load_tests(loader, _tests, _pattern):
    source = Path(__file__).resolve().parents[2] / 'tests/test_spc_artifact.py'
    spec = importlib.util.spec_from_file_location('legacy_spc_cases', source)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return loader.loadTestsFromModule(module)
