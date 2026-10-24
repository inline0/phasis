#!/usr/bin/env python3
"""Fetch and verify the exact Hono source archive used by the compatibility suite."""
import hashlib
import io
import json
from pathlib import Path
import shutil
import tarfile
import tempfile
import urllib.request

DIRECTORY = Path(__file__).resolve().parent


def main():
    metadata = json.loads((DIRECTORY / 'upstream.json').read_text())
    archive = urllib.request.urlopen(metadata['source'], timeout=60).read()
    checksum = hashlib.sha256(archive).hexdigest()
    if checksum != metadata['sha256']:
        raise RuntimeError('Hono archive checksum mismatch: ' + checksum)
    target = DIRECTORY / metadata['directory']
    target.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='.hono-upstream-', dir=target.parent) as temporary:
        staging = Path(temporary)
        with tarfile.open(fileobj=io.BytesIO(archive), mode='r:gz') as package:
            for member in package.getmembers():
                parts = Path(member.name).parts
                if len(parts) == 1:
                    continue
                member.name = str(Path(*parts[1:]))
                package.extract(member, staging, filter='data')
        package_json = json.loads((staging / 'package.json').read_text())
        if package_json['version'] != metadata['version']:
            raise RuntimeError('Hono source version does not match the manifest')
        if target.exists():
            # Preserve existing build artifacts, but reject edited upstream files.
            for source in staging.rglob('*'):
                destination = target / source.relative_to(staging)
                if source.is_file() and (not destination.is_file() or source.read_bytes() != destination.read_bytes()):
                    raise RuntimeError('Existing Hono source differs: ' + str(destination))
            for area in ['src', 'scripts', 'build', 'runtime-tests']:
                for source in (target / area).rglob('*'):
                    if source.is_file() and not (staging / source.relative_to(target)).is_file():
                        raise RuntimeError('Unexpected file in Hono source: ' + str(source))
        else:
            shutil.copytree(staging, target)
    print(f'Prepared Hono {metadata["version"]} at {metadata["commit"]} ({checksum})')


if __name__ == '__main__':
    main()
