#!/usr/bin/env python3
"""Fetch the exact Express source archive used by the compatibility suite."""

import hashlib
import io
import json
from pathlib import Path
import shutil
import tarfile
import tempfile
import urllib.request

BASE = Path(__file__).resolve().parent


def main():
    metadata = json.loads((BASE / 'upstream.json').read_text())
    archive = urllib.request.urlopen(metadata['archive'], timeout=60).read()
    actual = hashlib.sha256(archive).hexdigest()
    if actual != metadata['sha256']:
        raise RuntimeError(f'Express archive checksum mismatch: {actual}')
    staging = Path(tempfile.mkdtemp(prefix='.express-upstream-', dir=BASE))
    try:
        with tarfile.open(fileobj=io.BytesIO(archive), mode='r:gz') as package:
            for member in package.getmembers():
                parts = Path(member.name).parts
                if len(parts) == 1:
                    continue
                member.name = str(Path(*parts[1:]))
                package.extract(member, staging, filter='data')
        package_json = json.loads((staging / 'package.json').read_text())
        if package_json['version'] != metadata['version']:
            raise RuntimeError('Express source version does not match the manifest')
        target = BASE / 'upstream'
        if target.exists():
            shutil.rmtree(target)
        staging.rename(target)
    finally:
        if staging.exists():
            shutil.rmtree(staging)
    print(f'Prepared Express {metadata["version"]} at {metadata["revision"]}')


if __name__ == '__main__':
    main()
