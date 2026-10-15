// Builds the vendored real React 19.3 (edge build) used by the exact-React
// fallback and the three-way oracle: one ES module per public entry point,
// sharing one copy of React through esbuild's code splitting.
//
//   node build-vendor.mjs   (writes ../vendor/react-19.3.0/)
import { build } from 'esbuild';
import { createRequire } from 'node:module';
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

process.env.NODE_ENV = 'production';
const here = dirname(fileURLToPath(import.meta.url));
const require = createRequire(join(here, 'x.js'));
const out = join(here, '..', 'vendor', 'react-19.3.0');
const entries = {
  'react': 'react',
  'jsx-runtime': 'react/jsx-runtime',
  'react-dom': 'react-dom',
  'server': 'react-dom/server.edge',
  'static': 'react-dom/static.edge',
};
const tmp = join(here, '.vendor-entries');
rmSync(tmp, { recursive: true, force: true });
rmSync(out, { recursive: true, force: true });
mkdirSync(tmp, { recursive: true });
const entryPoints = {};
for (const [name, spec] of Object.entries(entries)) {
  const keys = Object.keys(require(spec)).filter((k) => /^[A-Za-z_$][\w$]*$/.test(k));
  const file = join(tmp, name + '.js');
  writeFileSync(file, `import M from '${spec}';\nexport const { ${keys.join(', ')} } = M;\nexport default M;\n`);
  entryPoints[name] = file;
}
await build({
  entryPoints,
  outdir: out,
  outExtension: { '.js': '.mjs' },
  bundle: true,
  splitting: true,
  format: 'esm',
  platform: 'neutral',
  mainFields: ['main'],
  conditions: ['edge-light', 'worker', 'browser', 'production', 'default'],
  define: { 'process.env.NODE_ENV': '"production"' },
  chunkNames: 'chunk-[hash]',
  legalComments: 'inline',
  logLevel: 'warning',
});
rmSync(tmp, { recursive: true, force: true });
writeFileSync(join(out, 'LICENSE'), require('node:fs').readFileSync(join(here, 'node_modules/react/LICENSE'), 'utf8'));
console.log('wrote', out);
