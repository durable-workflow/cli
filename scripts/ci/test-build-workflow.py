#!/usr/bin/env python3
"""Keep PHP support coverage and the required build gate intact."""

import os
from pathlib import Path
import re
import subprocess
import unittest

import yaml


ROOT = Path(__file__).resolve().parents[2]


class BuildWorkflowTest(unittest.TestCase):
    def setUp(self):
        self.jobs = yaml.safe_load((ROOT / '.github/workflows/build.yml').read_text())['jobs']
        self.gate = self.jobs['target-branch-qualification']

    def test_all_supported_php_versions_still_run_once(self):
        versions = self.jobs['test']['strategy']['matrix']['php'][:]
        steps = self.jobs['test-php83']['steps']
        setup = next(step for step in steps if step.get('uses', '').startswith(
            'durable-workflow/.github/.github/actions/setup-php-linux@'))
        versions.append(setup['with']['php-version'])
        self.assertCountEqual(['8.2', '8.3', '8.4', '8.5'], versions)

    def test_required_gate_includes_every_job(self):
        self.assertEqual(set(self.jobs) - {'target-branch-qualification'}, set(self.gate['needs']))
        guarded = set()
        for step in self.gate['steps']:
            for value in step.get('env', {}).values():
                match = re.fullmatch(r'\$\{\{ needs\.([a-z0-9-]+)\.result \}\}', value)
                if match:
                    guarded.add(match[1])
        self.assertEqual(set(self.gate['needs']), guarded)

    def test_gate_rejects_each_failed_cancelled_or_skipped_result(self):
        for step in self.gate['steps']:
            names = list(step.get('env', {}))
            baseline = dict.fromkeys(names, 'success')
            cases = [(None, None)] + [(name, value) for name in names
                                      for value in ('failure', 'cancelled', 'skipped')]
            for name, value in cases:
                environment = {'PATH': os.defpath, **baseline}
                if name is not None:
                    environment[name] = value
                result = subprocess.run(['bash', '-e', '-c', step['run']], env=environment,
                                        capture_output=True, text=True)
                self.assertEqual(name is None, result.returncode == 0, (name, value))


if __name__ == '__main__':
    unittest.main()
