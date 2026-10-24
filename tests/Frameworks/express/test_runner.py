"""Regression checks for timeout settings and owned worker-process cleanup."""

import argparse
import json
import os
from pathlib import Path
import re
import selectors
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parent))
from run import source_identity, terminate_group, timeout_scale

HERE = Path(__file__).resolve().parent


class TimeoutScaleParsingTest(unittest.TestCase):
    def test_finite_positive_bounded_decimal_scales(self):
        for value in ("1", "10", "100", ".5", "1e1"):
            self.assertGreater(timeout_scale(value), 0)
        for value in ("", "NaN", "Infinity", "-Infinity", "0", "-1", "101", "1e400", "0x10", "1_0"):
            with self.subTest(value=value), self.assertRaises(argparse.ArgumentTypeError):
                timeout_scale(value)

    def test_source_identity_records_the_timeout_setting(self):
        with mock.patch("run.tree_hash", return_value="unchanged"):
            first = source_identity(1)
            scaled = source_identity(10)
        self.assertEqual(first["runtimeSha256"], scaled["runtimeSha256"])
        self.assertEqual(first["timeoutScale"], 1)
        self.assertEqual(scaled["timeoutScale"], 10)


@unittest.skipUnless(shutil.which("node") and (HERE.parent / "node_modules/mocha/mocha.js").is_file(),
                     "requires the prepared Mocha dependency")
class TimeoutRunnerTest(unittest.TestCase):
    def run_control(self, body, titles, *, scale=1, timeout=30):
        with tempfile.TemporaryDirectory(prefix="phasis-timeout-control-") as temporary:
            directory = Path(temporary)
            source = directory / "control.cjs"
            output = directory / "result.json"
            source.write_text(body)
            options = {"timeout": timeout, "expectedTests": len(titles),
                       "expectedCases": [{"id": f"control.js:{index + 1}", "title": title}
                                         for index, title in enumerate(titles)],
                       "fileNames": {str(source): "control.js"}}
            if scale is not None:
                options["timeoutScale"] = scale
            program = ("globalThis.__expressFinished=s=>require('node:fs').writeFileSync("
                       + json.dumps(str(output)) + ",s);require(" + json.dumps(str(HERE / "runner.cjs"))
                       + ")(" + json.dumps([str(source)]) + "," + json.dumps(options) + ");")
            result = subprocess.run(["node", "-e", program], cwd=HERE,
                                    capture_output=True, text=True, timeout=5)
            self.assertEqual(result.returncode, 0, result.stderr)
            report = json.loads(output.read_text())
            self.assertTrue(report["complete"])
            self.assertEqual(report["caseManifest"], options["expectedCases"])
            self.assertEqual(report["loadErrors"], [])
            self.assertEqual(report["asynchronousErrors"], [])
            return report

    def test_default_one_and_scaled_async_deadline(self):
        body = "it('async', function(done) { setTimeout(done, 60); });"
        normal = self.run_control(body, ["async"], scale=None, timeout=30)
        scaled = self.run_control(body, ["async"], scale=4, timeout=30)
        self.assertEqual((normal["timeoutScale"], normal["fail"]), (1, 1))
        self.assertEqual((scaled["timeoutScale"], scaled["pass"]), (4, 1))
        self.assertEqual(scaled["tests"][0]["id"], "control.js:1")
        self.assertEqual(scaled["tests"][0]["originalTimeout"], 30)
        self.assertEqual(scaled["tests"][0]["effectiveTimeout"], 120)

    def test_dynamic_explicit_async_and_synchronous_timeouts_still_fail(self):
        for body in (
            "it('deadline', function(done) { this.timeout(10); setTimeout(done, 45); });",
            "it('deadline', function() { this.timeout('10ms'); const end=Date.now()+35; while(Date.now()<end) {} });",
        ):
            report = self.run_control(body, ["deadline"], scale=2)
            self.assertEqual(report["fail"], 1)
            case = report["tests"][0]
            self.assertEqual(case["error"]["code"], "ERR_MOCHA_TIMEOUT")
            self.assertEqual((case["originalTimeout"], case["effectiveTimeout"]), (10, 20))

    def test_hooks_are_scaled_once_on_each_run(self):
        report = self.run_control("""
const seen=[];
beforeEach(function(done) { this.timeout(20); seen.push(this.timeout()); setTimeout(done, 30); });
it('first', function() {});
it('second', function() { require('node:assert').deepStrictEqual(seen, [60,60]); });
""", ["first", "second"], scale=3)
        self.assertEqual(report["pass"], 2)

    def test_scaled_hook_deadline_still_fails(self):
        report = self.run_control("beforeEach(function(done) { this.timeout(10); setTimeout(done, 45); }); it('body', function() {});",
                                  ["body"], scale=2)
        self.assertEqual(report["fail"], 1)
        self.assertEqual(report["tests"][0]["error"]["code"], "ERR_MOCHA_TIMEOUT")
        self.assertEqual((report["tests"][0]["originalTimeout"], report["tests"][0]["effectiveTimeout"]), (10, 20))

    def test_guest_timer_and_clock_functions_are_unchanged(self):
        report = self.run_control("""
const timer=setTimeout, clock=performance.now;
it('clocks', function() {
  require('node:assert').strictEqual(setTimeout, timer);
  require('node:assert').strictEqual(performance.now, clock);
});
""", ["clocks"], scale=10)
        self.assertEqual(report["pass"], 1)

    def test_disabled_deadlines_are_preserved(self):
        report = self.run_control("it('disabled', function(done) { this.timeout(0); setTimeout(done, 30); });",
                                  ["disabled"], scale=2, timeout=5)
        self.assertEqual(report["pass"], 1)
        self.assertEqual((report["tests"][0]["originalTimeout"], report["tests"][0]["effectiveTimeout"]), (0, 0))

    def test_cli_environment_precedence_and_validation(self):
        pinned = json.loads((HERE / "manifest.json").read_text())
        entry = next(row for row in pinned["files"] if row["path"] == "test/Route.js")
        title = entry["cases"][0]["title"]
        with tempfile.TemporaryDirectory(prefix="phasis-timeout-cli-") as temporary:
            for label, extra, environment, expected in (
                ("environment", [], "2", 2),
                ("override", ["--timeout-scale", "3"], "NaN", 3),
            ):
                output = Path(temporary) / (label + ".json")
                result = subprocess.run(["node", str(HERE / "run-node.cjs"), "--file=test/Route.js",
                                         "--grep=^" + re.escape(title) + "$", "--output=" + str(output), *extra],
                                        env=dict(os.environ, PHASIS_FRAMEWORK_TIMEOUT_SCALE=environment),
                                        capture_output=True, text=True, timeout=5)
                self.assertEqual(result.returncode, 0, result.stderr)
                report = json.loads(output.read_text())
                self.assertEqual(report["timeoutScale"], expected)
                self.assertEqual(report["caseManifest"], entry["cases"])
            for value in ("NaN", "Infinity", "0", "-1", "101", "not-a-number"):
                result = subprocess.run(["node", str(HERE / "run-node.cjs"), "--timeout-scale=" + value],
                                        capture_output=True, text=True, timeout=5)
                self.assertNotEqual(result.returncode, 0, value)

    def test_direct_runner_rejects_invalid_scales(self):
        for value in ("NaN", "Infinity", "0", "-1", "101", "0x10", "", True):
            program = "require(" + json.dumps(str(HERE / "runner.cjs")) + ")([], {timeoutScale:" + json.dumps(value) + "});"
            result = subprocess.run(["node", "-e", program], capture_output=True, text=True, timeout=5)
            self.assertNotEqual(result.returncode, 0, str(value))

    @unittest.skipUnless(shutil.which("php"), "requires PHP")
    def test_php_cli_scale_precedence_and_invalid_values(self):
        entry = next(row for row in json.loads((HERE / "manifest.json").read_text())["files"]
                     if row["path"] == "test/Route.js")
        with tempfile.TemporaryDirectory(prefix="phasis-timeout-php-") as temporary:
            output = Path(temporary) / "result.json"
            result = subprocess.run(["php", "-d", "memory_limit=1G", str(HERE / "run-phasis.php"),
                                     "--file=test/Route.js", "--grep=^" + re.escape(entry["cases"][0]["title"]) + "$",
                                     "--timeout-scale=3", "--output=" + str(output)],
                                    env=dict(os.environ, XDEBUG_MODE="off", PHASIS_FRAMEWORK_TIMEOUT_SCALE="NaN"),
                                    capture_output=True, text=True, timeout=20)
            self.assertEqual(result.returncode, 0, result.stderr)
            report = json.loads(output.read_text())
            self.assertEqual(report["timeoutScale"], 3)
            self.assertEqual(report["caseManifest"], entry["cases"])
            self.assertEqual(report["tests"][0]["effectiveTimeout"], report["tests"][0]["originalTimeout"] * 3)
            for value in ("NaN", "Infinity", "0", "-1", "101", "not-a-number"):
                result = subprocess.run(["php", str(HERE / "run-phasis.php"), "--timeout-scale=" + value],
                                        env=dict(os.environ, XDEBUG_MODE="off"),
                                        capture_output=True, text=True, timeout=5)
                self.assertNotEqual(result.returncode, 0, value)


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
