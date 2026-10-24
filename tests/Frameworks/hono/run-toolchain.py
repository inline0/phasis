#!/usr/bin/env python3
"""Validate unchanged Hono declaration fixtures with the pinned native TypeScript compiler."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from uuid import uuid4

from run import DIRECTORY, REPOSITORY, execute, upstream_inventory


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--timeout', type=float, default=180)
    args = parser.parse_args()
    run_id = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ-') + uuid4().hex[:8]
    archive = DIRECTORY / 'results' / 'runs' / run_id
    archive.mkdir(parents=True)
    inventory = upstream_inventory()
    dependencies = json.loads((DIRECTORY.parent / 'package.json').read_text())['devDependencies']
    manifest = {'id': run_id, 'project': 'toolchain', 'host': 'native TypeScript compiler',
                'upstreamProjects': inventory,
                'selected': inventory['projects']['main']['toolchainFiles'],
                'dependencies': {name: dependencies[name] for name in ['hono', 'typescript', 'vitest']},
                'lockSha256': hashlib.sha256((DIRECTORY.parent / 'package-lock.json').read_bytes()).hexdigest()}
    status, output = execute(['node', str(DIRECTORY / 'prepare-toolchain.mjs')], args.timeout, 'toolchain')
    (archive / 'prepare.log').write_text(output)
    if status:
        manifest['status'] = 'preparation-blocked'
    else:
        report_file = archive / 'native.json'
        status, output = execute([
            'node', str(DIRECTORY.parent / 'node_modules/vitest/vitest.mjs'), 'run',
            '--config', str(DIRECTORY / 'vitest.config.mjs'), '--reporter=json',
            '--outputFile=' + str(report_file),
        ], args.timeout, 'toolchain')
        (archive / 'native.log').write_text(output)
        if report_file.exists():
            report = json.loads(report_file.read_text())
            manifest['counts'] = {key: report[key] for key in
                                  ['numTotalTests', 'numPassedTests', 'numFailedTests', 'numPendingTests']}
            manifest['status'] = 'pass' if status == 0 and report['success'] and report['numTotalTests'] else 'fail'
        else:
            manifest['status'] = 'process-blocked'
    manifest['exitCode'] = status
    (archive / 'manifest.json').write_text(json.dumps(manifest, indent=2))
    print(manifest['status'], manifest.get('counts', {}))
    print('Run manifest:', archive / 'manifest.json')
    return int(manifest['status'] != 'pass')


if __name__ == '__main__':
    raise SystemExit(main())
