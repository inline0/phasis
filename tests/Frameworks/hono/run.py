#!/usr/bin/env python3
"""Run unchanged transpiled Hono tests in bounded Node/Phasis subprocesses."""
import argparse
import json
import hashlib
import math
from datetime import datetime, timezone
from uuid import uuid4
from inventory import inventory as upstream_inventory
import os
import re
import signal
from pathlib import Path
import subprocess
import sys
import tempfile
import time


def stop_on_signal(signum, frame):
    raise SystemExit(128 + signum)


signal.signal(signal.SIGTERM, stop_on_signal)

DIRECTORY = Path(__file__).resolve().parent
REPOSITORY = DIRECTORY.parents[2]
UPSTREAM_METADATA = json.loads((DIRECTORY / 'upstream.json').read_text())
UPSTREAM = DIRECTORY / UPSTREAM_METADATA['directory']
PREFIX = '__PHASIS_HONO_REPORT__'


def terminate_group(process):
    # Successful workers can leave descendants with open listeners or pipes.
    # Each worker has a dedicated process group, so include its descendants.
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


def execute(command, timeout, project="main", log_file=None, timeout_scale=1):
    try:
        environment = dict(os.environ, XDEBUG_MODE='off', PHASIS_HONO_PROJECT=project,
                           PHASIS_FRAMEWORK_TIMEOUT_SCALE=str(timeout_scale))
        if project == 'node':
            environment['NAME'] = 'Node'
        environment.pop('NO_COLOR', None)
        with (log_file.open('w+') if log_file else tempfile.TemporaryFile(mode='w+')) as log:
            process = subprocess.Popen(command, cwd=REPOSITORY, env=environment,
                                       stdout=log, stderr=subprocess.STDOUT,
                                       text=True, start_new_session=True)
            timed_out = False
            try:
                try:
                    status = process.wait(timeout=timeout)
                except subprocess.TimeoutExpired:
                    timed_out = True
                    status = 124
            finally:
                terminate_group(process)
            log.seek(0)
            return status, log.read() + ('\nPROCESS TIMEOUT\n' if timed_out else '')
    except OSError as error:
        return 127, str(error) + '\n'


def source_hash(directory):
    digest = hashlib.sha256()
    for path in sorted(directory.rglob('*')):
        if path.is_file() and path.suffix in ['.php', '.cjs', '.mjs']:
            digest.update(str(path.relative_to(directory)).encode() + b'\0')
            digest.update(path.read_bytes())
    return digest.hexdigest()


def harness_hash():
    digest = hashlib.sha256()
    for name in ['build.mjs', 'harness.mjs', 'deadline.mjs', 'run.py', 'run-php.php', 'inventory.py', 'upstream.json']:
        digest.update(name.encode() + b'\0' + (DIRECTORY / name).read_bytes())
    return digest.hexdigest()


def dependency_hash():
    return hashlib.sha256((DIRECTORY.parent / 'package-lock.json').read_bytes()).hexdigest()


def php_memory_limit(value):
    match = re.fullmatch(r'([1-9][0-9]*)([KMG]?)', value, re.IGNORECASE)
    if match is None:
        raise argparse.ArgumentTypeError('Use a positive byte count or an integer with K, M or G, for example 4G')
    suffix = match[2].upper()
    multiplier = {'': 1, 'K': 1024, 'M': 1024 ** 2, 'G': 1024 ** 3}[suffix]
    if len(match[1]) > len(str(sys.maxsize)) or int(match[1]) * multiplier > sys.maxsize:
        raise argparse.ArgumentTypeError('The memory limit exceeds the platform integer range')
    return match[1] + suffix


def timeout_scale(value):
    source = str(value).strip()
    if re.fullmatch(r'[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?', source) is None:
        raise argparse.ArgumentTypeError('The timeout scale must be a finite positive number')
    scale = float(source)
    if not math.isfinite(scale) or scale <= 0 or scale > 100:
        raise argparse.ArgumentTypeError('The timeout scale must be finite, greater than 0 and at most 100')
    return scale


def parse_arguments(arguments=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('files', nargs='*')
    parser.add_argument('--timeout', type=float, default=1800)
    parser.add_argument('--timeout-scale', type=timeout_scale,
                        default=os.environ.get('PHASIS_FRAMEWORK_TIMEOUT_SCALE', '1'),
                        help='Multiply framework test/hook deadlines by >0 and <=100 (default: environment or 1); not the process watchdog')
    parser.add_argument('--project', choices=['main', 'jsx-runtime-default', 'jsx-runtime-dom', 'node'], default='main')
    parser.add_argument('--runtime', choices=['node', 'phasis', 'both'], default='both')
    parser.add_argument('--no-build', action='store_true')
    parser.add_argument('--php-memory-limit', type=php_memory_limit, default='4G',
                        help='Bounded PHP worker memory, in bytes or K/M/G notation (default: 4G)')
    return parser, parser.parse_args(arguments)


def main():
    parser, args = parse_arguments()
    all_projects = upstream_inventory()
    project = all_projects['projects'][args.project]
    inventory = project.get('runtimeFiles', project['files'])
    files = args.files or inventory
    if len(files) != len(set(files)):
        parser.error('Each upstream file may appear only once in a run')
    for name in files:
        if name in all_projects['projects']['main']['toolchainFiles']:
            parser.error(name + ' is a native TypeScript check; use run-toolchain.py')
        if name not in inventory:
            parser.error(name + ' is not in the selected upstream project')
    run_id = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ-') + uuid4().hex[:8]
    results = DIRECTORY / 'results'
    archive = results / 'runs' / run_id
    archive.mkdir(parents=True)
    manifest = []
    metadata = {'id': run_id, 'upstream': UPSTREAM_METADATA, 'project': args.project,
                'inventory': inventory, 'upstreamProjects': all_projects,
                'selected': files, 'runtimes': args.runtime,
                'timeoutSeconds': args.timeout, 'phpMemoryLimit': args.php_memory_limit,
                'timeoutScale': args.timeout_scale,
                'timeouts': {'test': {'originalTimeout': 5000, 'effectiveTimeout': 5000 * args.timeout_scale},
                             'hook': {'originalTimeout': 10000, 'effectiveTimeout': 10000 * args.timeout_scale}},
                'nativeVitestTimeouts': 'unchanged; configure the independent native runner separately',
                'runtimeSourceAtStart': source_hash(REPOSITORY / 'src'),
                'harnessSourceAtStart': harness_hash(), 'dependenciesAtStart': dependency_hash(),
                'complete': False, 'files': manifest}

    def save(name, contents):
        (archive / name).write_text(contents)
        (results / name).write_text(contents)

    def save_manifest():
        save('manifest.json', json.dumps(metadata, indent=2))
    save_manifest()
    for name in files:
        identifier = name.replace('/', '__').rsplit('.', 1)[0]
        row = {'file': name, 'sha256': hashlib.sha256((UPSTREAM / name).read_bytes()).hexdigest(), 'runtimes': {}}
        case_reports = {}
        manifest.append(row)
        save_manifest()
        if not args.no_build:
            status, output = execute(['node', str(DIRECTORY / 'build.mjs'), name], args.timeout, args.project,
                                     timeout_scale=args.timeout_scale)
            save(identifier + '-build.log', output)
            if status:
                row['build'] = {'status': 'build-blocked', 'exitCode': status}
                print(name, 'BUILD BLOCKED', flush=True)
                save_manifest()
                continue
        for runtime in (['node', 'phasis'] if args.runtime == 'both' else [args.runtime]):
            row['runtimes'][runtime] = {'status': 'running'}
            save_manifest()
            generated = DIRECTORY / '.generated'
            if args.project != 'main':
                generated /= args.project
            script = str(generated / (identifier + '.mjs'))
            command = ['node', script] if runtime == 'node' else [
                'php', '-d', 'memory_limit=' + args.php_memory_limit, str(DIRECTORY / 'run-php.php'), script]
            started = time.monotonic()
            status, output = execute(command, args.timeout, args.project,
                                     archive / (identifier + '-' + runtime + '.log'), timeout_scale=args.timeout_scale)
            row.setdefault('elapsedSeconds', {})[runtime] = round(time.monotonic() - started, 3)
            save(identifier + '-' + runtime + '.log', output)
            reports = [line[len(PREFIX):] for line in output.splitlines() if line.startswith(PREFIX)]
            if status or len(reports) != 1:
                row['runtimes'][runtime] = {'status': 'process-blocked', 'exitCode': status}
            else:
                report = json.loads(reports[0])
                if report.get('timeoutScale') != args.timeout_scale:
                    row['runtimes'][runtime] = {'status': 'timeout-configuration-mismatch', 'exitCode': status}
                elif not report['results']:
                    row['runtimes'][runtime] = {'status': 'empty-discovery', 'exitCode': status}
                else:
                    save(identifier + '-' + runtime + '.json', json.dumps(report, indent=2))
                    case_reports[runtime] = [(case['id'], case['name'], case['status']) for case in report['results']]
                    row['runtimes'][runtime] = report['counts']
                    if report.get('suiteErrors'):
                        row['runtimes'][runtime]['suite-failures'] = len(report['suiteErrors'])
            print(name, runtime, row['runtimes'][runtime], flush=True)
        if len(case_reports) == 2 and case_reports['node'] != case_reports['phasis']:
            row['comparison'] = 'case-results-differ'
        save_manifest()
    metadata['runtimeSourceAtEnd'] = source_hash(REPOSITORY / 'src')
    metadata['runtimeChangedDuringRun'] = metadata['runtimeSourceAtStart'] != metadata['runtimeSourceAtEnd']
    metadata['harnessSourceAtEnd'] = harness_hash()
    metadata['harnessChangedDuringRun'] = metadata['harnessSourceAtStart'] != metadata['harnessSourceAtEnd']
    metadata['dependenciesAtEnd'] = dependency_hash()
    metadata['dependenciesChangedDuringRun'] = metadata['dependenciesAtStart'] != metadata['dependenciesAtEnd']
    metadata['complete'] = True
    save_manifest()
    print('Run manifest:', archive / 'manifest.json', flush=True)
    changed = any(metadata[key] for key in ['runtimeChangedDuringRun', 'harnessChangedDuringRun', 'dependenciesChangedDuringRun'])
    return int(changed or any(row.get('build') or row.get('comparison') or any(report.get('status') or report.get('fail') or report.get('harness-blocked') or report.get('suite-blocked') or report.get('suite-failures')
                for report in row['runtimes'].values()) for row in manifest))


if __name__ == '__main__':
    sys.exit(main())
