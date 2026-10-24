"""Verify bounded Hono worker options and process cleanup."""
import contextlib
import io
import os
import sys
import time
import unittest
from unittest.mock import patch

from run import execute, parse_arguments


class TimeoutScaleTest(unittest.TestCase):
    def test_default_environment_and_cli_precedence(self):
        with patch.dict(os.environ, {}, clear=True):
            self.assertEqual(parse_arguments([])[1].timeout_scale, 1)
        with patch.dict(os.environ, {'PHASIS_FRAMEWORK_TIMEOUT_SCALE': '2.5'}):
            self.assertEqual(parse_arguments([])[1].timeout_scale, 2.5)
            self.assertEqual(parse_arguments(['--timeout-scale', '10'])[1].timeout_scale, 10)
        with patch.dict(os.environ, {'PHASIS_FRAMEWORK_TIMEOUT_SCALE': 'invalid'}):
            self.assertEqual(parse_arguments(['--timeout-scale', '3'])[1].timeout_scale, 3)
            with contextlib.redirect_stderr(io.StringIO()), self.assertRaises(SystemExit):
                parse_arguments([])

    def test_valid_scales(self):
        for source, expected in [('1', 1), ('10', 10), ('100', 100), ('0.5', .5), ('2e1', 20), ('.25', .25)]:
            with self.subTest(source=source):
                self.assertEqual(parse_arguments(['--timeout-scale', source])[1].timeout_scale, expected)

    def test_invalid_and_overflowing_scales_are_rejected(self):
        for value in ['', '0', '-1', 'NaN', 'Infinity', '1e500', '1e-500', '1_0', '0x10', '101', '1e6']:
            with self.subTest(value=value), contextlib.redirect_stderr(io.StringIO()):
                with self.assertRaises(SystemExit) as error:
                    parse_arguments(['--timeout-scale', value])
                self.assertEqual(error.exception.code, 2)

    def test_worker_scale_is_explicit_and_does_not_change_watchdog(self):
        program = "import os; print(os.environ['PHASIS_FRAMEWORK_TIMEOUT_SCALE'])"
        with patch.dict(os.environ, {'PHASIS_FRAMEWORK_TIMEOUT_SCALE': '99'}):
            self.assertEqual(execute([sys.executable, '-c', program], 5)[1].strip(), '1')
            self.assertEqual(execute([sys.executable, '-c', program], 5, timeout_scale=10)[1].strip(), '10')
        status, output = execute([sys.executable, '-c', 'import time; time.sleep(60)'], .05, timeout_scale=10)
        self.assertEqual(status, 124)
        self.assertIn('PROCESS TIMEOUT', output)


class WorkerMemoryTest(unittest.TestCase):
    def test_default_and_explicit_memory_limits(self):
        _, default = parse_arguments([])
        self.assertEqual(default.php_memory_limit, '4G')
        for source, expected in [('512m', '512M'), ('2G', '2G'), ('1024K', '1024K'), ('4096', '4096')]:
            with self.subTest(source=source):
                _, selected = parse_arguments(['--php-memory-limit', source])
                self.assertEqual(selected.php_memory_limit, expected)

    def test_unbounded_invalid_and_overflowing_limits_are_rejected(self):
        for value in ['-1', '0', '4GB', '4.5G', 'unlimited', '2G ', str(sys.maxsize) + 'G', '9' * 5000]:
            with self.subTest(value=value), contextlib.redirect_stderr(io.StringIO()):
                with self.assertRaises(SystemExit) as error:
                    parse_arguments(['--php-memory-limit', value])
                self.assertEqual(error.exception.code, 2)


class WorkerCleanupTest(unittest.TestCase):
    def test_successful_worker_stops_its_surviving_child(self):
        program = """
import subprocess, sys
child = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(60)'])
print(child.pid, flush=True)
"""
        status, output = execute([sys.executable, '-c', program], 5)
        self.assertEqual(status, 0)
        child_pid = int(output.strip())
        for _ in range(100):
            try:
                os.kill(child_pid, 0)
            except ProcessLookupError:
                return
            time.sleep(.01)
        self.fail('Worker left its child running')

    def test_timed_out_worker_reports_the_original_timeout(self):
        status, output = execute([sys.executable, '-c', 'import time; time.sleep(60)'], .05)
        self.assertEqual(status, 124)
        self.assertIn('PROCESS TIMEOUT', output)


if __name__ == '__main__':
    unittest.main()
