"""Discover PHAR launcher controls in the existing host-Python test lane."""
import importlib.util
from pathlib import Path


def load_tests(loader, _tests, _pattern):
    source = Path(__file__).resolve().parents[2]/'scripts/ci/test-php-phar-container.py'
    spec = importlib.util.spec_from_file_location('php_phar_controls', source)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return loader.loadTestsFromModule(module)
