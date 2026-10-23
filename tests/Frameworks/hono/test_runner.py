"""Verify bounded Hono worker options and process cleanup."""
import contextlib
import io
import os
import sys
import time
import unittest

from run import execute, parse_arguments


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
