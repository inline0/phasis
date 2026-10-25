#!/usr/bin/env python3
"""Local-only Express compatibility. Never run in CI; see ../README.md."""

import argparse
import concurrent.futures
import datetime
import hashlib
import json
import math
import os
import re
from pathlib import Path
import signal
import subprocess
import sys
import threading
import time


HERE = Path(__file__).resolve().parent
REPOSITORY = HERE.parents[2]


def tree_hash(directory):
    digest = hashlib.sha256()
    for path in sorted(directory.rglob("*")):
        if path.is_file():
            digest.update(path.relative_to(directory).as_posix().encode() + b"\0")
            digest.update(hashlib.sha256(path.read_bytes()).digest())
    return digest.hexdigest()


def timeout_scale(value):
    if not re.fullmatch(r"[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?", str(value).strip()):
        raise argparse.ArgumentTypeError("timeout scale must be a finite positive number")
    number = float(value)
    if not math.isfinite(number) or number <= 0 or number > 100:
        raise argparse.ArgumentTypeError("timeout scale must be a finite positive number at most 100")
    return number


def source_identity(scale=1):
    harness = hashlib.sha256()
    for name in ("run.py", "runner.cjs", "run-node.cjs", "run-phasis.php", "setup.py", "upstream.json"):
        harness.update(name.encode() + b"\0" + (HERE / name).read_bytes())
    return {
        "timeoutScale": scale,
        "runtimeSha256": tree_hash(REPOSITORY / "src"),
        "upstreamSha256": tree_hash(HERE / "upstream"),
        "manifestSha256": hashlib.sha256((HERE / "manifest.json").read_bytes()).hexdigest(),
        "packageLockSha256": hashlib.sha256((HERE.parent / "package-lock.json").read_bytes()).hexdigest(),
        "harnessSha256": harness.hexdigest(),
    }


def terminate_group(process):
    # A worker may exit while its descendants retain sockets or pipe handles.
    # Every worker owns a fresh process group, so cleanup includes descendants.
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    if process.poll() is None:
        try:
            process.wait(timeout=2)
        except subprocess.TimeoutExpired:
            pass
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    process.wait()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runtime", choices=("node", "phasis", "both"), default="both")
    parser.add_argument("--jobs", type=int, choices=(1, 2), default=1)
    parser.add_argument("--timeout", type=float, default=1800)
    parser.add_argument("--timeout-scale", type=timeout_scale,
                        default=os.environ.get("PHASIS_FRAMEWORK_TIMEOUT_SCALE", "1"))
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if args.timeout <= 0:
        parser.error("--timeout must be positive")
    args.output = args.output.resolve()
    if args.output.exists():
        parser.error("--output must name a new directory")
    args.output.mkdir(parents=True)
    manifest = json.loads((HERE / "manifest.json").read_text())
    source = json.loads((HERE / "upstream.json").read_text())
    identity_before = source_identity(args.timeout_scale)
    runtimes = ["node", "phasis"] if args.runtime == "both" else [args.runtime]
    tasks = [(runtime, entry) for runtime in runtimes for entry in manifest["files"]]
    stopped = threading.Event()
    lock = threading.Lock()
    active = {}

    def interrupt(signum, frame):
        stopped.set()
        raise KeyboardInterrupt

    previous_handlers = {name: signal.signal(name, interrupt) for name in (signal.SIGINT, signal.SIGTERM)}

    def execute(task):
        runtime, entry = task
        if stopped.is_set():
            return None
        stem = entry["path"].replace("/", "__").removesuffix(".js")
        output = args.output / (stem + "-" + runtime + ".json")
        log = output.with_suffix(".log")
        command = (["node", str(HERE / "run-node.cjs")] if runtime == "node" else
                   ["php", "-d", "memory_limit=1G", str(HERE / "run-phasis.php")])
        command += ["--file=" + entry["path"], "--output=" + str(output), "--timeout-scale=" + str(args.timeout_scale)]
        timed_out = False
        start = time.monotonic()
        with log.open("w") as handle:
            with lock:
                if stopped.is_set():
                    return None
                process = subprocess.Popen(command, cwd=REPOSITORY,
                                           env=dict(os.environ, XDEBUG_MODE="off"),
                                           stdout=handle, stderr=subprocess.STDOUT,
                                           start_new_session=True)
                active[process.pid] = process
            try:
                try:
                    exit_code = process.wait(timeout=args.timeout)
                except subprocess.TimeoutExpired:
                    timed_out = True
                    exit_code = None
            finally:
                terminate_group(process)
                with lock:
                    active.pop(process.pid, None)
        partial = Path(str(output) + ".partial.json")
        report_file = output if output.exists() else partial
        try:
            report = json.loads(report_file.read_text()) if report_file.exists() else {}
        except json.JSONDecodeError:
            report = {"complete": False, "completionError": "The worker was interrupted while writing its report."}
        expected = entry["cases"]
        actual = report.get("caseManifest", [])
        outcomes = {test.get("id"): test for test in report.get("tests", [])}
        missing = [case["id"] for case in expected if case["id"] not in outcomes]
        case_identity_matches = actual == expected
        result = {
            "runtime": runtime, "file": entry["path"], "expectedTests": entry["tests"],
            "exitCode": exit_code, "timedOut": timed_out, "log": log.name,
            "elapsedSeconds": time.monotonic() - start,
            "caseIdentityMatches": case_identity_matches, "unaccountedCases": missing,
            "timeoutScaleMatches": report.get("timeoutScale") == args.timeout_scale,
            "report": report,
        }
        print(json.dumps({key: value for key, value in result.items() if key not in ("report", "unaccountedCases")}
                         | {"complete": report.get("complete", False), "pass": report.get("pass", 0),
                            "fail": report.get("fail", 0)}), flush=True)
        return result

    results = []
    completed = set()
    pool = concurrent.futures.ThreadPoolExecutor(max_workers=args.jobs)
    futures = [pool.submit(execute, task) for task in tasks]
    try:
        for future in concurrent.futures.as_completed(futures):
            result = future.result()
            completed.add(future)
            if result is not None:
                results.append(result)
                (args.output / "progress.json").write_text(json.dumps(results, indent=2) + "\n")
    except KeyboardInterrupt:
        stopped.set()
    finally:
        if stopped.is_set():
            for future in futures:
                future.cancel()
            with lock:
                processes = list(active.values())
            for process in processes:
                terminate_group(process)
        pool.shutdown(wait=True, cancel_futures=True)
        for future in futures:
            if future not in completed and not future.cancelled():
                result = future.result()
                if result is not None:
                    results.append(result)
        for name, handler in previous_handlers.items():
            signal.signal(name, handler)
    identity_after = source_identity(args.timeout_scale)
    stable_source = identity_before == identity_after
    summary = {
        "generatedAt": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "source": source, "manifest": manifest, "filtered": False,
        "identityBefore": identity_before, "identityAfter": identity_after,
        "stableSource": stable_source, "interrupted": stopped.is_set(),
        "execution": {"isolation": "file", "workers": args.jobs, "timeoutSeconds": args.timeout,
                      "timeoutScale": args.timeout_scale},
        "runtimes": {}, "files": results,
    }
    successful = stable_source and not stopped.is_set()
    for runtime in runtimes:
        selected = {result["file"]: result for result in results if result["runtime"] == runtime}
        incomplete = [entry["path"] for entry in manifest["files"]
                      if entry["path"] not in selected or
                      not selected[entry["path"]]["report"].get("complete") or
                      selected[entry["path"]]["timedOut"]]
        unaccounted = [case["id"] for entry in manifest["files"] for case in entry["cases"]
                       if entry["path"] not in selected or case["id"] in selected[entry["path"]]["unaccountedCases"]]
        values = {
            "complete": not incomplete,
            "expectedTests": manifest["tests"],
            "discovered": sum(result["report"].get("discovered", 0) for result in selected.values()),
            "incompleteFiles": incomplete,
            "unaccountedTests": len(unaccounted), "unaccountedCases": unaccounted,
            "caseIdentityMatches": all(result["caseIdentityMatches"] for result in selected.values()),
        }
        for name in ("pass", "fail", "pending"):
            values[name] = sum(result["report"].get(name, 0) for result in selected.values())
        for name in ("loadErrors", "asynchronousErrors"):
            values[name] = sum(len(result["report"].get(name, [])) for result in selected.values())
        values["success"] = (stable_source and values["complete"] and values["caseIdentityMatches"]
                             and not unaccounted and values["pass"] == values["expectedTests"]
                             and values["fail"] == values["pending"] == values["loadErrors"] == values["asynchronousErrors"] == 0
                             and all(result["exitCode"] == 0 for result in selected.values()))
        values["success"] = values["success"] and all(result["timeoutScaleMatches"] for result in selected.values())
        successful = successful and values["success"]
        summary["runtimes"][runtime] = values
    (args.output / "summary.json").write_text(json.dumps(summary, indent=2) + "\n")
    print(json.dumps({runtime: {key: value for key, value in values.items() if key != "unaccountedCases"}
                      for runtime, values in summary["runtimes"].items()}, indent=2))
    return 0 if successful else 1


if __name__ == "__main__":
    sys.exit(main())
