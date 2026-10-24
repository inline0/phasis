#!/usr/bin/env python3
"""Inventory all original Hono test projects, including host-specific suites."""
import hashlib
import json
from pathlib import Path

DIRECTORY = Path(__file__).resolve().parent
METADATA = json.loads((DIRECTORY / 'upstream.json').read_text())
UPSTREAM = DIRECTORY / METADATA['directory']


def test_files(area):
    return sorted(str(path.relative_to(UPSTREAM)) for path in (UPSTREAM / area).rglob('*')
                  if path.is_file() and ('.test.' in path.name or '.spec.' in path.name)
                  and path.suffix in ['.ts', '.tsx', '.js'] and 'sandbox' not in path.parts)


def inventory():
    source = test_files('src')
    runtime = [name for name in source if '.case.test.' not in name] + test_files('scripts')
    toolchain = test_files('build')
    dom = test_files('src/jsx/dom') + ['src/jsx/hooks/dom.test.tsx']
    projects = {
        'main': {'host': 'Node and web APIs, plus native TypeScript build checks',
                 'files': sorted(runtime + toolchain), 'runtimeFiles': sorted(runtime),
                 'toolchainFiles': sorted(toolchain)},
        'jsx-runtime-default': {'host': 'Node with jsdom', 'files': sorted(dom)},
        'jsx-runtime-dom': {'host': 'Node with jsdom', 'files': sorted(dom)},
    }
    for host in ['node', 'fastly', 'workerd', 'bun', 'deno', 'deno-jsx']:
        projects[host] = {'host': host, 'files': test_files('runtime-tests/' + host)}
    files = sorted(set(name for project in projects.values() for name in project['files']))
    return {'upstream': METADATA, 'projects': projects,
            'fixtures': [name for name in source if '.case.test.' in name],
            'sha256': {name: hashlib.sha256((UPSTREAM / name).read_bytes()).hexdigest() for name in files}}


if __name__ == '__main__':
    print(json.dumps(inventory(), indent=2))
