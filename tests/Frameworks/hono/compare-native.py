#!/usr/bin/env python3
"""Compare every case with an independent, original-source Vitest JSON report."""
import argparse
from collections import Counter
import json
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('native', type=Path)
parser.add_argument('manifest', type=Path)
parser.add_argument('--runtime', choices=['node', 'phasis'], default='node')
args = parser.parse_args()
reference = json.loads(args.native.read_text())
manifest = json.loads(args.manifest.read_text())
marker = Path(manifest['upstream']['directory']).name + '/'
files = {row['name'].split(marker, 1)[-1]: row for row in reference['testResults']}
comparison = []
statuses = {'pass': 'passed', 'upstream-skip': 'skipped', 'suite-blocked': 'skipped', 'fail': 'failed', 'harness-blocked': 'harness-blocked'}
for name in manifest['selected']:
    row = {'file': name, 'status': 'match'}
    comparison.append(row)
    native = files.get(name)
    if native is None:
        row['status'] = 'native-file-missing'
        continue
    if not native['assertionResults']:
        row['status'] = 'native-collection-blocked'
        row['message'] = native.get('message', '')
        continue
    report_file = args.manifest.parent / (name.replace('/', '__').rsplit('.', 1)[0] + '-' + args.runtime + '.json')
    if not report_file.is_file():
        row['status'] = 'runtime-report-missing'
        continue
    report = json.loads(report_file.read_text())
    expected = Counter((' > '.join(case['ancestorTitles'] + [case['title']]), case['status'])
                       for case in native['assertionResults'])
    actual = Counter((case['name'], statuses[case['status']]) for case in report['results'])
    row['nativeCases'] = sum(expected.values())
    row['runtimeCases'] = sum(actual.values())
    if expected != actual:
        row['status'] = 'case-results-differ'
        row['missing'] = list((expected - actual).elements())
        row['unexpected'] = list((actual - expected).elements())
result = {'run': manifest['id'], 'runtime': args.runtime, 'files': comparison,
          'counts': dict(Counter(row['status'] for row in comparison))}
output = args.manifest.parent / ('native-comparison-' + args.runtime + '.json')
output.write_text(json.dumps(result, indent=2))
print(json.dumps({'report': str(output), 'counts': result['counts']}))
raise SystemExit(int(any(row['status'] != 'match' for row in comparison)))
