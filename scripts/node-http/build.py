#!/usr/bin/env python3
"""Reproduce the unchanged, integrity-pinned HTTP parser and its license."""

import argparse
import base64
import hashlib
import io
import json
from pathlib import Path
import tarfile
import urllib.request


DIRECTORY = Path(__file__).resolve().parent
LIBRARY = DIRECTORY.parents[1] / 'src' / 'Node' / 'Lib'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Verify committed assets without writing them.')
    args = parser.parse_args()
    metadata = json.loads((DIRECTORY / 'upstream.json').read_text())['http-parser-js']
    payload = urllib.request.urlopen(metadata['url'], timeout=30).read()
    integrity = 'sha512-' + base64.b64encode(hashlib.sha512(payload).digest()).decode()
    if integrity != metadata['integrity']:
        raise RuntimeError('HTTP parser archive integrity mismatch')
    with tarfile.open(fileobj=io.BytesIO(payload), mode='r:gz') as archive:
        package = json.loads(archive.extractfile('package/package.json').read())
        if package['version'] != metadata['version']:
            raise RuntimeError('HTTP parser archive version mismatch')
        for source, target in [('http-parser.js', 'http-parser.cjs'), ('LICENSE.md', 'http-parser.LICENSE.md')]:
            contents = archive.extractfile('package/' + source).read()
            destination = LIBRARY / target
            if args.check:
                if not destination.exists() or destination.read_bytes() != contents:
                    raise RuntimeError('HTTP parser asset differs: ' + str(destination))
            else:
                destination.write_bytes(contents)
    print('Verified http-parser-js ' + metadata['version'] + ' and its license')


if __name__ == '__main__':
    main()
