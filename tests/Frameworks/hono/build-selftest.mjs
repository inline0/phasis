import { build } from 'esbuild';
import { mkdir, writeFile } from 'node:fs/promises';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const directory = dirname(fileURLToPath(import.meta.url));
const output = resolve(directory, '.generated/selftest');
await mkdir(output, { recursive: true });
await build({
  entryPoints: [resolve(directory, 'fixtures/timeouts-entry.mjs')],
  outfile: resolve(output, 'timeouts.cjs'), bundle: true, platform: 'node',
  format: 'cjs', target: 'es2022', banner: { js: '"use strict";' }, logLevel: 'warning',
});
await writeFile(resolve(output, 'timeouts.mjs'),
  "import {createRequire} from 'node:module';\nconst require = createRequire(import.meta.url);\nrequire('./timeouts.cjs');\n");
