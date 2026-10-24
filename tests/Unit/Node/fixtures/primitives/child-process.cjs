'use strict';
const cp = require('child_process');
const { once } = require('events');
const { promisify } = require('util');
const { AsyncLocalStorage } = require('async_hooks');
const fs = require('fs');
const path = require('path');
require('./helpers.cjs').run(async () => {
  const output = [];
  const binary = Buffer.from([0, 255, 128, 10, 65]);
  const copied = cp.spawnSync('/bin/cat', [], { input: binary });
  output.push(['sync-binary', Buffer.isBuffer(copied.stdout), copied.stdout.toString('hex'), copied.status, copied.signal, copied.output[0], copied.output[1] === copied.stdout]);
  const status = cp.spawnSync('/bin/sh', ['-c', 'printf "%s" "$ONE"; printf error >&2; exit 7'], { env: { ONE: 'value' }, encoding: 'utf8' });
  output.push(['sync-status', status.stdout, status.stderr, status.status, status.signal]);
  output.push(['literal-args', cp.execFileSync('/bin/sh', ['-c', 'printf "%s|%s" "$1" "$2"', 'args', 'with spaces', '$(literal)'], { encoding: 'utf8' })]);
  output.push(['shell', cp.execSync('printf "%s" "shell result"', { encoding: 'utf8' })]);
  const directory = fs.mkdtempSync(path.join(require('os').tmpdir(), 'phasis-child-'));
  try {
    const cwd = cp.spawnSync('/bin/pwd', [], { cwd: directory, encoding: 'utf8' });
    output.push(['cwd', path.basename(cwd.stdout.trim()) === path.basename(directory)]);
  } finally { fs.rmSync(directory, { recursive: true, force: true }); }
  const missing = cp.spawnSync('/phasis-missing-child-executable', ['argument']);
  output.push(['missing', missing.error.code, missing.error.path, missing.error.spawnargs, missing.status, missing.signal, missing.stdout]);
  const missingCwd = cp.spawnSync('/bin/pwd', [], { cwd: '/phasis-missing-child-directory' });
  output.push(['missing-cwd', missingCwd.error.code]);
  const timeout = cp.spawnSync('/bin/sleep', ['2'], { timeout: 20 });
  output.push(['timeout', timeout.error.code, timeout.status, timeout.signal]);
  const limit = cp.spawnSync('/bin/cat', [], { input: Buffer.alloc(4096, 'x'), maxBuffer: 16 });
  output.push(['output-limit', limit.error.code, limit.stdout.length > 16]);
  try { cp.execFileSync('/bin/sh', ['-c', 'printf failed >&2; exit 4'], { stdio: ['ignore', 'pipe', 'pipe'] }); }
  catch (error) { output.push(['exec-error', error.status, error.signal, error.stderr.toString()]); }
  await new Promise((resolve, reject) => {
    const child = cp.spawn('/bin/cat');
    const events = [];
    const chunks = [];
    child.once('error', reject);
    child.once('spawn', () => events.push('spawn'));
    child.once('exit', (code, signal) => events.push(['exit', code, signal]));
    child.stdout.on('data', (chunk) => chunks.push(chunk));
    child.once('close', (code, signal) => {
      events.push(['close', code, signal]);
      output.push(['async-binary', Buffer.concat(chunks).toString('hex'), events, child.exitCode, child.signalCode, child.killed]);
      resolve();
    });
    child.stdin.write(binary.subarray(0, 2));
    child.stdin.write(Buffer.alloc(0));
    child.stdin.end(binary.subarray(2));
  });
  const executed = await promisify(cp.execFile)('/bin/sh', ['-c', 'printf async; printf warning >&2']);
  output.push(['promisify', executed.stdout, executed.stderr]);
  const payload = Buffer.alloc(300000, 'abcdef');
  const piped = cp.spawn('/bin/cat');
  const pipeChunks = [];
  let ended = false;
  piped.stdout.on('data', (chunk) => pipeChunks.push(chunk));
  piped.stdout.on('end', () => { ended = true; });
  const pipeClosed = once(piped, 'close');
  const accepted = piped.stdin.write(payload);
  if (!accepted) await once(piped.stdin, 'drain');
  piped.stdin.end();
  await pipeClosed;
  output.push(['pipe-backpressure', accepted, Buffer.concat(pipeChunks).toString() === payload.toString(), ended]);
  try { await promisify(cp.execFile)('/bin/sh', ['-c', 'printf abcdefghijklmnopqrstuvwxyz'], { maxBuffer: 4 }); }
  catch (error) { output.push(['async-output-limit', error.code, error.stdout]); }
  await new Promise((resolve) => cp.execFile('/bin/sh', ['-c', 'printf output; printf failed >&2; exit 3'], (error, stdout, stderr) => {
    output.push(['async-exec-error', error.code, error.signal, error.killed, stdout, stderr]);
    resolve();
  }));
  await new Promise((resolve, reject) => {
    const child = cp.spawn('/bin/sleep', ['2'], { stdio: 'ignore' });
    child.once('error', reject);
    child.once('spawn', () => child.kill('SIGTERM'));
    child.once('close', (code, signal) => { output.push(['kill', code, signal, child.killed, child.kill()]); resolve(); });
  });
  await new Promise((resolve) => {
    const child = cp.spawn('/phasis-missing-child-executable');
    const events = [];
    child.on('error', (error) => events.push(error.code));
    child.on('close', () => { events.push('close'); output.push(['async-missing', events]); resolve(); });
  });
  await new Promise((resolve, reject) => {
    const controller = new AbortController();
    const child = cp.spawn('/bin/sleep', ['2'], { signal: controller.signal, stdio: 'ignore' });
    let aborted;
    child.once('spawn', () => controller.abort('cancelled'));
    child.once('error', (error) => { aborted = [error.name, error.code, error.cause]; });
    child.once('close', (code, signal) => { output.push(['abort', aborted, code, signal]); resolve(); });
  });
  const storage = new AsyncLocalStorage();
  await storage.run('child-context', async () => {
    const child = cp.spawn('/bin/echo', ['context']);
    let context;
    child.on('spawn', () => { context = storage.getStore(); });
    await once(child, 'close');
    output.push(['async-context', context, storage.getStore()]);
  });
  const worker = cp.spawnSync(process.execPath, [path.join(__dirname, 'child-worker.cjs'), 'argument'], { input: binary, encoding: 'utf8' });
  if (worker.error || worker.status !== 0) throw worker.error || new Error(worker.stderr);
  output.push(['javascript-child', JSON.parse(worker.stdout), worker.status]);
  return output;
});
