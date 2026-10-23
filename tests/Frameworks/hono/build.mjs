import { build } from 'esbuild';
import { resolve, dirname, relative } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { mkdir, readFile, writeFile, readdir, stat, rename } from 'node:fs/promises';
import { builtinModules } from 'node:module';
async function writeGenerated(path, contents) {
  const temporary = `${path}.${process.pid}.tmp`;
  await writeFile(temporary, contents);
  await rename(temporary, path);
}
const directory = dirname(fileURLToPath(import.meta.url));
const metadata = JSON.parse(await readFile(resolve(directory, 'upstream.json'), 'utf8'));
const upstream = resolve(directory, metadata.directory);
const project = process.env.PHASIS_HONO_PROJECT || 'main';
const output = resolve(directory, '.generated', ...(project === 'main' ? [] : [project]));
const modules = resolve(output, 'modules');
await mkdir(modules, { recursive: true });
const stamp = `${metadata.commit}:cjs-graph-1:${(await stat(fileURLToPath(import.meta.url))).mtimeMs}`;
let previous;
try { previous = await readFile(resolve(output, 'stamp'), 'utf8'); } catch {}
if (previous !== stamp) {
  const files = [];
  const assets = [];
  async function visit(path) {
    for (const entry of await readdir(path, { withFileTypes: true })) {
      const file = resolve(path, entry.name);
      if (entry.isDirectory()) await visit(file);
      else if (/\.(?:tsx?|js)$/.test(file) && !file.endsWith('.d.ts')) files.push(file);
      else assets.push(file);
    }
  }
  for (const area of ['src', 'scripts', 'build', 'runtime-tests']) {
    try { await visit(resolve(upstream, area)); } catch (error) { if (error.code !== 'ENOENT') throw error; }
  }
  const result = await build({
    entryPoints: files, outbase: upstream, outdir: modules, bundle: false,
    format: 'cjs', platform: 'node', target: 'es2022', write: false,
    jsx: 'automatic', jsxImportSource: resolve(modules, project === 'jsx-runtime-dom' ? 'src/jsx/dom' : 'src/jsx'),
    supported: { 'dynamic-import': false },
    define: {
      'import.meta.url': '__honoSourceUrl', 'import.meta.dirname': '__honoSourceDirectory',
      'import.meta.filename': '__honoSourceFile', '__filename': '__honoSourceFile', '__dirname': '__honoSourceDirectory',
    },
    logLevel: 'warning',
  });
  const originalFiles = new Map(files.map(file => [resolve(modules, relative(upstream, file).replace(/\.tsx?$/, '.js')), file]));
  for (const file of result.outputFiles) {
    const original = originalFiles.get(file.path);
    if (!original) throw new Error('Unknown transpiled source: ' + file.path);
    const sourceMetadata = `var __honoSourceUrl = ${JSON.stringify(pathToFileURL(original).href)}, __honoSourceFile = ${JSON.stringify(original)}, __honoSourceDirectory = ${JSON.stringify(dirname(original))};\n`;
    await mkdir(dirname(file.path), { recursive: true });
    // Vitest exposes mutable import namespaces so spies can replace exports.
    // CJS imports retain property reads, preserving live spy observations.
    const contents = file.text
      .replace('get: all[name], enumerable: true', 'get: all[name], enumerable: true, configurable: true')
      .replace('get: () => from[key], enumerable:', 'get: () => from[key], configurable: true, enumerable:')
      .replace('__toESM = (mod, isNodeMode, target) => (target =', '__toESM = (mod, isNodeMode, target) => mod?.__esModule ? mod : (target =');
    await writeGenerated(file.path, '"use strict";\n' + sourceMetadata + contents);
  }
  for (const file of assets) {
    const target = resolve(modules, relative(upstream, file));
    await mkdir(dirname(target), { recursive: true });
    await writeGenerated(target, await readFile(file));
  }
  await writeGenerated(resolve(modules, 'package.json'), JSON.stringify({ type: 'commonjs' }));
  const vitest = resolve(modules, 'node_modules/vitest');
  await mkdir(vitest, { recursive: true });
  await writeGenerated(resolve(vitest, 'package.json'), JSON.stringify({ name: 'vitest', main: 'index.cjs' }));
  await writeGenerated(resolve(vitest, 'index.cjs'), `module.exports = require(${JSON.stringify(resolve(output, 'harness.cjs'))});\n`);
  await writeGenerated(resolve(output, 'stamp'), stamp);
}
const harnessBuild = await build({
  entryPoints: [resolve(directory, 'harness.mjs')], outfile: resolve(output, 'harness.cjs'),
  bundle: true, format: 'cjs', platform: 'node', target: 'es2022', write: false,
  external: ['node:*', ...builtinModules], logLevel: 'warning',
});
for (const file of harnessBuild.outputFiles) await writeGenerated(file.path, '"use strict";\n' + file.text);
for (const name of process.argv.slice(2)) {
  const file = resolve(modules, name.replace(/\.tsx?$/, '.js'));
  await stat(file);
  const target = resolve(output, name.replace(/[\\/]/g, '__').replace(/\.(?:tsx?|js)$/, '.mjs'));
  const entry = `import {createRequire} from 'node:module';
const require = createRequire(import.meta.url);
const harness = require(${JSON.stringify(resolve(output, 'harness.cjs'))});
globalThis.__honoCase ||= value => process.stdout.write('__PHASIS_HONO_CASE__' + value + '\\n');
globalThis.__honoResetModules = () => {
  for (const key of Object.keys(require.cache)) {
    if (key.startsWith(${JSON.stringify(modules + '/')})) delete require.cache[key];
  }
};
require(${JSON.stringify(file)});
harness.run().then(report => {
  globalThis.__honoReport = report;
  if (globalThis.__honoComplete) globalThis.__honoComplete();
  else process.stdout.write('__PHASIS_HONO_REPORT__' + JSON.stringify(report) + '\\n', () => process.exit(0));
});
`;
  await writeGenerated(target, entry);
  console.log(target);
}
