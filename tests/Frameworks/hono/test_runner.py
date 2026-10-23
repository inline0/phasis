"""Verify that Hono workers cannot leave owned processes behind."""
import os
import sys
import time
import unittest

from run import execute


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
