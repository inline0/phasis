import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { writeFile } from 'node:fs/promises';
import * as zlib from 'node:zlib';

const directory = resolve(dirname(fileURLToPath(import.meta.url)), '../../tests/Unit/Node/fixtures/primitives');
const compressionInput = Buffer.from('hello 🌍\0 binary\xff hello hello');
const compressed = {};
for (const name of ['gzip', 'deflate', 'deflateRaw', 'brotliCompress']) compressed[name] = zlib[name + 'Sync'](compressionInput).toString('base64');
await writeFile(resolve(directory, 'zlib-inputs.json'), `${JSON.stringify(compressed, null, 2)}\n`);
for (const name of ['buffer', 'events', 'stream', 'stream-web', 'fs', 'zlib', 'vm', 'child-process']) {
  const output = execFileSync(process.execPath, [resolve(directory, `${name}.cjs`)], { encoding: 'utf8', timeout: 15000 });
  const value = JSON.parse(output.trim());
  await writeFile(resolve(directory, `${name}.json`), `${JSON.stringify(value, null, 2)}\n`);
  await writeFile(resolve(directory, `${name}.mjs`), `import './${name}.cjs';\n`);
  console.log(`${name}: ${value.length} oracle observations`);
}
