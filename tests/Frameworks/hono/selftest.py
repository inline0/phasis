#!/usr/bin/env python3
"""Verify harness deadlines and job scheduling against the actual pinned Vitest runner."""
from datetime import datetime, timezone
import json
from uuid import uuid4

from run import DIRECTORY, PREFIX, REPOSITORY, execute, harness_hash, source_hash


def main():
    run_id = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ-') + uuid4().hex[:8] + '-selftest'
    archive = DIRECTORY / 'results/runs' / run_id
    archive.mkdir(parents=True)
    status, output = execute(['node', str(DIRECTORY / 'build-selftest.mjs')], 60)
    (archive / 'build.log').write_text(output)
    if status:
        raise RuntimeError('Harness self-test build failed')
    script = DIRECTORY / '.generated/selftest/timeouts.mjs'
    commands = {
        'native': ['node', str(DIRECTORY.parent / 'node_modules/vitest/vitest.mjs'), 'run',
                   '--config', str(DIRECTORY / 'selftest.config.mjs'), '--reporter=json',
                   '--outputFile=' + str(archive / 'native.json')],
        'node': ['node', str(script)],
        'phasis': ['php', '-d', 'memory_limit=1G', str(DIRECTORY / 'run-php.php'), str(script)],
    }
    manifest = {'id': run_id, 'kind': 'harness-deadline-selftest', 'results': {},
                'runtimeSource': source_hash(REPOSITORY / 'src'), 'harnessSource': harness_hash()}
    for runtime, command in commands.items():
        status, output = execute(command, 60, log_file=archive / (runtime + '.log'))
        if runtime == 'native':
            if status != 1:
                raise RuntimeError('Native Vitest must report the intentional deadline failures')
        else:
            reports = [line[len(PREFIX):] for line in output.splitlines() if line.startswith(PREFIX)]
            if status != 0 or len(reports) != 1:
                raise RuntimeError(runtime + ' did not finish the deadline fixtures')
            (archive / (runtime + '.json')).write_text(reports[0])
    native = json.loads((archive / 'native.json').read_text())
    cases = {(' > '.join(case['ancestorTitles'] + [case['title']])): case
             for file in native['testResults'] for case in file['assertionResults']}
    if len(cases) != 15 or native['numPassedTests'] != 4 or native['numFailedTests'] != 10:
        raise RuntimeError('Native deadline fixture inventory changed unexpectedly')
    for runtime in ['node', 'phasis']:
        report = json.loads((archive / (runtime + '.json')).read_text())
        failures = []
        if len(report['results']) != len(cases):
            failures.append('case inventory differs')
        statuses = {'pass': 'passed', 'fail': 'failed', 'suite-blocked': 'skipped'}
        for case in report['results']:
            reference = cases.get(case['name'])
            if reference is None or statuses.get(case['status']) != reference['status']:
                failures.append(case['name'] + ': outcome differs')
                continue
            if reference['failureMessages']:
                expected = reference['failureMessages'][0].splitlines()[0].removeprefix('Error: ')
                actual = case.get('error', {}).get('message', '').splitlines()[0]
                if expected != actual:
                    failures.append(case['name'] + ': error differs')
        if len(report['suiteErrors']) != 1 or not report['suiteErrors'][0]['error']['message'].startswith('Hook timed out in 30ms.'):
            failures.append('suite setup failure was not preserved')
        manifest['results'][runtime] = {'cases': len(report['results']), 'differences': failures}
    schedules = {}
    for runtime in ['native', 'node', 'phasis']:
        script = DIRECTORY / '.generated/selftest/scheduling.mjs'
        if runtime == 'native':
            command = ['node', str(DIRECTORY.parent / 'node_modules/vitest/vitest.mjs'), 'run',
                       '--config', str(DIRECTORY / 'scheduling.config.mjs'), '--reporter=json',
                       '--outputFile=' + str(archive / 'scheduling-native.json')]
        elif runtime == 'node':
            command = ['node', str(script)]
        else:
            command = ['php', '-d', 'memory_limit=1G', str(DIRECTORY / 'run-php.php'), str(script)]
        status, output = execute(command, 60, log_file=archive / ('scheduling-' + runtime + '.log'))
        prefix = '__PHASIS_HONO_SCHEDULE__'
        traces = [json.loads(line[len(prefix):]) for line in output.splitlines() if line.startswith(prefix)]
        if status != 0 or len(traces) != 1:
            raise RuntimeError(runtime + ' did not finish the scheduling fixtures')
        schedules[runtime] = traces[0]
        if runtime == 'native':
            report = json.loads((archive / 'scheduling-native.json').read_text())
            if report['numPassedTests'] != 3 or report['numFailedTests'] != 0:
                raise RuntimeError('Native scheduling fixture inventory changed unexpectedly')
        else:
            reports = [json.loads(line[len(PREFIX):]) for line in output.splitlines() if line.startswith(PREFIX)]
            if len(reports) != 1 or reports[0]['counts']['pass'] != 3 or reports[0]['counts']['fail']:
                raise RuntimeError(runtime + ' failed the scheduling fixtures')
            if schedules[runtime] != schedules['native']:
                manifest['results'][runtime]['differences'].append('lifecycle job scheduling differs')
    manifest['scheduling'] = schedules
    (archive / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    print(json.dumps(manifest['results']))
    print('Run manifest:', archive / 'manifest.json')
    return int(any(result['differences'] for result in manifest['results'].values()))


if __name__ == '__main__':
    raise SystemExit(main())
