"""Regression checks for owned worker-process cleanup."""

import os
from pathlib import Path
import selectors
import subprocess
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
from run import terminate_group


class WorkerCleanupTest(unittest.TestCase):
    def test_termination_reaps_worker_and_stops_its_child(self):
        program = """
import signal, subprocess, sys, time
child = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(600)'])
def stop(signum, frame):
    child.wait(timeout=1)
    sys.exit(0)
signal.signal(signal.SIGTERM, stop)
print(child.pid, flush=True)
time.sleep(600)
"""
        worker = subprocess.Popen([sys.executable, "-c", program], stdout=subprocess.PIPE,
                                  stderr=subprocess.PIPE, text=True, start_new_session=True)
        try:
            with selectors.DefaultSelector() as selector:
                selector.register(worker.stdout, selectors.EVENT_READ)
                self.assertTrue(selector.select(timeout=5), "Worker did not publish its child PID")
                child_pid = int(worker.stdout.readline())
            terminate_group(worker)
            self.assertIsNotNone(worker.returncode)
            with self.assertRaises(ProcessLookupError):
                os.kill(child_pid, 0)
        finally:
            terminate_group(worker)
            worker.stdout.close()
            worker.stderr.close()


if __name__ == "__main__":
    unittest.main()
