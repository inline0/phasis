#!/usr/bin/env python3
"""Regenerate the portable Node modules from pinned upstream sources."""

from pathlib import Path
import hashlib
import json
import re
import subprocess
import urllib.request

ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
LIB = ROOT / 'src/Node/Lib'
PINNED = json.loads((HERE / 'node-sources.json').read_text())
VERSION = PINNED['node']
SOURCES = {
    'path': 'path.cjs',
    'querystring': 'querystring.cjs',
    'url': 'url.cjs',
    'internal/querystring': 'portable/internal-querystring.cjs',
    'internal/constants': 'portable/constants.cjs',
}


def main():
    LIB.mkdir(parents=True, exist_ok=True)
    (LIB / 'portable').mkdir(exist_ok=True)
    hashes = {}
    primordial_names = set()
    for source, target in SOURCES.items():
        url = PINNED['sources'][source]['url']
        original = urllib.request.urlopen(url, timeout=30).read()
        if hashlib.sha256(original).hexdigest() != PINNED['sources'][source]['sha256']:
            raise RuntimeError(f'Node source checksum mismatch: {source}')
        hashes[source] = {'url': url, 'sha256': hashlib.sha256(original).hexdigest()}
        text = original.decode()
        for match in re.finditer(r'const \{([^}]+)\} = primordials;', text):
            primordial_names.update(re.findall(r'\b\w+\b', match.group(1)))
        prefix = './portable/' if '/' not in target else './'
        text = text.replace('} = primordials;', "} = require('" + prefix + "primordials.cjs');")
        text = text.replace("require('internal/constants')", "require('" + prefix + "constants.cjs')")
        text = text.replace("require('internal/validators')", "require('" + prefix + "validators.cjs')")
        text = text.replace("require('internal/errors')", "require('" + prefix + "errors.cjs')")
        text = text.replace("require('internal/querystring')", "require('" + prefix + "internal-querystring.cjs')")
        text = text.replace("require('internal/util')", "require('" + prefix + "helpers.cjs')")
        text = text.replace("require('internal/fs/glob')", "require('./portable/glob.cjs')")
        text = text.replace("require('internal/url')", "require('./portable/whatwg-url.cjs')")
        text = text.replace("internalBinding('url_pattern')", 'globalThis')
        text = text.replace("internalBinding('encoding_binding')", "{ toASCII: require('./portable/whatwg-url.cjs').domainToASCII }")
        text = text.replace("internalBinding('util')", "require('./portable/helpers.cjs')")
        text = text.replace("internalBinding('url')", "require('./portable/whatwg-url.cjs')")
        if source == 'path':
            signature = 'function normalizeString(path, allowAboveRoot, separator, isPathSeparator) {'
            if text.count(signature) != 1:
                raise RuntimeError('Pinned Node path normalizer signature changed')
            text = text.replace(signature, signature + "\n  if (globalThis.__phasisNodeHost && __phasisNodeHost.path)\n    return __phasisNodeHost.path.normalizeString(path, allowAboveRoot, separator);")
        (LIB / target).write_text(text)

    license_source = PINNED['sources']['LICENSE']
    license_text = urllib.request.urlopen(license_source['url'], timeout=30).read()
    if hashlib.sha256(license_text).hexdigest() != license_source['sha256']:
        raise RuntimeError('Node license checksum mismatch')
    (LIB / 'portable/NODE_LICENSE.txt').write_bytes(license_text)
    hashes['LICENSE'] = license_source

    entries = []
    for name in sorted(primordial_names):
        match = re.fullmatch(r'(\w+)Prototype(\w+)', name)
        if match:
            kind, method = match.groups()
            method = method[0].lower() + method[1:]
            value = f'Function.call.bind({kind}.prototype.{method})'
        elif name == 'ArrayIsArray':
            value = 'Array.isArray'
        elif name == 'MathAbs':
            value = 'Math.abs'
        elif name == 'NumberIsFinite':
            value = 'Number.isFinite'
        elif name == 'ObjectKeys':
            value = 'Object.keys'
        elif name == 'ObjectAssign':
            value = 'Object.assign'
        else:
            value = name
        entries.append(f'  {name}: {value},')
    (LIB / 'portable/primordials.cjs').write_text(
        "'use strict';\n\n// Capture intrinsics before user code can replace their prototype methods.\nmodule.exports = {\n"
        + '\n'.join(entries) + '\n};\n'
    )

    esbuild = HERE / 'node_modules/esbuild/bin/esbuild'
    for name, entry in [('util-base', 'util/util.js'), ('assert', 'assert/build/assert.js'), ('minimatch', 'minimatch'), ('punycode', 'punycode/punycode.js'), ('tr46', 'tr46'), ('hpack', 'hpack.js')]:
        source = HERE / f'.entry-{name}.cjs'
        source.write_text(f'module.exports = require({json.dumps(entry)});\n')
        output = LIB / ('assert.cjs' if name == 'assert' else f'portable/{name}.cjs')
        try:
            arguments = [str(esbuild), str(source), '--bundle', '--platform=node', '--format=cjs', '--target=es2022', '--legal-comments=inline', f'--outfile={output}']
            if name == 'hpack':
                arguments.append('--alias:readable-stream=stream')
            subprocess.run(arguments, check=True)
        finally:
            source.unlink(missing_ok=True)
    lock = json.loads((HERE / 'package-lock.json').read_text())
    licenses = {}
    for package, details in lock['packages'].items():
        if not package:
            continue
        path = HERE / package
        for candidate in ['LICENSE', 'LICENSE.md', 'LICENSE.txt', 'license', 'COPYING']:
            if (path / candidate).is_file():
                licenses[f'{package.removeprefix("node_modules/")}@{details["version"]}'] = (path / candidate).read_text(errors='replace')
                break
        else:
            readme = path / 'README.md'
            if readme.is_file():
                content = readme.read_text(errors='replace')
                match = re.search(r'^#+.*LICENSE.*$', content, re.MULTILINE | re.IGNORECASE)
                if match:
                    licenses[f'{package.removeprefix("node_modules/")}@{details["version"]}'] = content[match.start():]
    (LIB / 'portable/LICENSES.json').write_text(json.dumps(licenses, indent=2) + '\n')
    (LIB / 'portable/http2-constants.json').write_bytes((HERE / 'http2-constants.json').read_bytes())
    (LIB / 'portable/sources.json').write_text(json.dumps({'node': VERSION, 'sources': hashes, 'npmLockSha256': hashlib.sha256((HERE / 'package-lock.json').read_bytes()).hexdigest()}, indent=2) + '\n')


if __name__ == '__main__':
    main()
