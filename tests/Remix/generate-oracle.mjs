import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';

const root = import.meta.dirname;
const version = process.versions.node.split('.').map(Number);
if (version[0] < 24 || (version[0] === 24 && version[1] < 3)) {
  throw new Error('Remix oracle requires Node >=24.3.0');
}
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'node_modules/remix/package.json')));
const lock = JSON.parse(fs.readFileSync(path.join(root, 'package-lock.json')));
const runtime = [], typeOnly = [], metadata = [];
for (const [name, entry] of Object.entries(pkg.exports)) {
  const target = typeof entry === 'string' ? entry : entry.default;
  const specifier = name === '.' ? 'remix' : `remix${name.slice(1)}`;
  if (!target) typeOnly.push(specifier);
  else if (target.endsWith('.js')) runtime.push(specifier);
  else metadata.push(specifier);
}
const probes = runtime.sort().map(specifier => ({ id: `import:${specifier}`, kind: 'import', target: specifier }));
for (const file of fs.readdirSync(path.join(root, 'cases')).filter(x => x.endsWith('.mjs')).sort()) {
  const target = `cases/${file}`;
  probes.push({ id: `behavior:${file.slice(0, -4)}`, kind: 'behavior', target,
    sourceSha256: crypto.createHash('sha256').update(fs.readFileSync(path.join(root, target))).digest('hex') });
}
for (const probe of probes) {
  try {
    const output = execFileSync(process.execPath, ['--max-old-space-size=256', path.join(root, 'node-worker.mjs'), probe.kind, probe.target],
      { timeout: 10000, maxBuffer: 1024 * 1024, encoding: 'utf8', env: { ...process.env, TZ: 'UTC' }, stdio: ['ignore', 'pipe', 'pipe'] });
    const line = output.split('\n').findLast(x => x.startsWith('PHASIS_REMIX_RESULT:'));
    if (!line) throw new Error('Node worker did not return a result');
    probe.oracle = JSON.parse(line.slice('PHASIS_REMIX_RESULT:'.length));
    if (probe.kind === 'behavior' && probe.oracle.status !== 'ok') {
      throw new Error(`Behavior fixture must succeed in Node: ${probe.oracle.name}: ${probe.oracle.message}`);
    }
  } catch (error) {
    throw new Error(`Oracle worker failed for ${probe.id}: ${error.message}`);
  }
}
const oracle = {
  package: { name: pkg.name, version: pkg.version, repository: 'https://github.com/remix-run/remix',
    integrity: lock.packages['node_modules/remix'].integrity },
  nodeVersion: process.versions.node,
  inventory: { runtime: runtime.sort(), typeOnly: typeOnly.sort(), metadata: metadata.sort() },
  probes,
};
const destination = path.join(root, 'oracle.json');
if (process.argv.includes('--check')) {
  const expected = JSON.parse(fs.readFileSync(destination));
  delete expected.nodeVersion;
  const actual = { ...oracle }; delete actual.nodeVersion;
  if (JSON.stringify(expected) !== JSON.stringify(actual)) throw new Error('Remix Node oracle drift. Regenerate and review oracle.json.');
  console.log(`Remix ${pkg.version}: ${probes.length} pinned Node oracle probes verified.`);
} else {
  fs.writeFileSync(destination, JSON.stringify(oracle, null, 2) + '\n');
  console.log(`Remix ${pkg.version}: ${runtime.length} runtime imports, ${probes.length - runtime.length} behavior probes.`);
  for (const probe of probes.filter(x => x.oracle.status !== 'ok')) console.log(`Oracle unavailable: ${probe.id}: ${probe.oracle.name}: ${probe.oracle.message}`);
}
