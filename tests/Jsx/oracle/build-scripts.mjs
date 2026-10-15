// Regenerates src/BuiltIn/Jsx/React/Fizz/Scripts.php: React's inline
// instruction scripts ($RC, $RX, $RS, ...), copied byte for byte from the
// pinned react-dom server bundle.
//
//   node tests/Jsx/oracle/build-scripts.mjs
import { readFileSync, writeFileSync } from 'fs';
const version = JSON.parse(readFileSync(new URL('node_modules/react-dom/package.json', import.meta.url), 'utf8')).version;
const src = readFileSync(new URL('node_modules/react-dom/cjs/react-dom-server.node.production.js', import.meta.url), 'utf8');
const names = ['formReplayingRuntimeScript', 'shellTimeRuntimeScript', 'completeSegmentScript1Full', 'completeBoundaryScriptFunctionOnly', 'completeBoundaryWithStylesScript1FullPartial', 'clientRenderScriptFunctionOnly', 'clientRenderScript1Full'];
const out = {};
const outFile = new URL('../../../src/BuiltIn/Jsx/React/Fizz/Scripts.php', import.meta.url);
for (const name of names) {
  const m = new RegExp(name + ' = stringToPrecomputedChunk\\(\\s*((?:\'(?:[^\'\\\\]|\\\\.)*\')|(?:"(?:[^"\\\\]|\\\\.)*"))\\s*\\)').exec(src);
  if (!m) throw new Error('not found ' + name);
  out[name] = eval(m[1]);
}
const vt = /writeChunk\(\s*destination,\s*('\$RV=function\(B,g\)(?:[^'\\]|\\.)*')\s*\)/.exec(src);
out.viewTransitionScript = eval(vt[1]);
const php = ['<?php', '', '/**', ` * React ${version} inline instruction scripts, copied byte for byte from`, ' * react-dom-server.node.production.js.', ' *', ' * Copyright (c) Meta Platforms, Inc. and affiliates.', ' *', ' * This source code is licensed under the MIT license found in the', ' * LICENSE file in the root directory of this source tree', ' * (third-party/react/LICENSE in Phasis).', ' */', '', 'declare(strict_types=1);', '', 'namespace Phasis\\BuiltIn\\Jsx\\React\\Fizz;', '', 'final class Scripts', '{'];
const constName = (n) => n.replace(/([A-Z0-9]+)/g, '_$1').toUpperCase();
for (const [k, v] of Object.entries(out)) {
  php.push(`    // ${k}`);
  php.push(`    public const ${constName(k)} = ${JSON.stringify(v).replace(/\$/g, '\\$')};`);
  php.push('');
}
php.pop();
php.push('}', '');
writeFileSync(outFile, php.join('\n'));
