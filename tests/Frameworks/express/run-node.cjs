'use strict';

const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const root = path.join(__dirname, 'upstream');
const args = process.argv.slice(2);
const selectedFile = args.find((arg) => arg.startsWith('--file='))?.slice(7);
const selectedGrep = args.find((arg) => arg.startsWith('--grep='))?.slice(7);
const output = args.find((arg) => arg.startsWith('--output='))?.slice(9);
const scaleIndex = args.findIndex(arg => arg === '--timeout-scale' || arg.startsWith('--timeout-scale='));
const rawScale = scaleIndex < 0 ? (process.env.PHASIS_FRAMEWORK_TIMEOUT_SCALE ?? '1')
  : args[scaleIndex] === '--timeout-scale' ? args[scaleIndex + 1] : args[scaleIndex].slice(16);
if (typeof rawScale !== 'string' || !/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(rawScale.trim())
    || !Number.isFinite(Number(rawScale)) || Number(rawScale) <= 0 || Number(rawScale) > 100) {
  throw new Error('--timeout-scale must be a finite positive number at most 100.');
}
const timeoutScale = Number(rawScale);
const pinned = JSON.parse(fs.readFileSync(path.join(__dirname, 'manifest.json'), 'utf8'));
const manifest = pinned.files.filter(file => !selectedFile || file.path === selectedFile);
if (!manifest.length) throw new Error('The requested file is not part of the pinned Express suite.');
const files = manifest.map(file => {
  const name = path.join(root, file.path);
  if (crypto.createHash('sha256').update(fs.readFileSync(name)).digest('hex') !== file.sha256) throw new Error('Upstream test checksum changed: ' + file.path);
  return name;
});
const metadata = JSON.parse(fs.readFileSync(path.join(__dirname, 'upstream.json'), 'utf8'));
process.chdir(root);
globalThis.__expressFinished = function finished(serialized) {
  const result = { runtime: 'node', node: process.version, source: metadata, filtered: !!(selectedFile || selectedGrep), manifest, ...JSON.parse(serialized) };
  const contents = JSON.stringify(result, null, 2) + '\n';
  if (output) fs.writeFileSync(output, contents);
  else console.log(contents);
  process.exitCode = result.fail || result.pending || result.loadErrors.length || result.asynchronousErrors.length ? 1 : 0;
};
require('./runner.cjs')(files, {
  timeoutScale,
  grep: selectedGrep,
  expectedTests: manifest.reduce((sum, file) => sum + file.tests, 0),
  expectedCases: manifest.flatMap(file => file.cases),
  fileNames: Object.fromEntries(manifest.map(file => [path.join(root, file.path), file.path])),
});
