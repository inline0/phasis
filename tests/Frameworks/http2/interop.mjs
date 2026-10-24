import { spawn, execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { createHash } from 'node:crypto';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';

const directory = dirname(fileURLToPath(import.meta.url));
const running = new Set();
const materialDirectory = mkdtempSync(resolve(tmpdir(), 'phasis-http2-interop-'));
const material = JSON.parse(execFileSync('php', [resolve(directory, 'certificate.php')], { env: { ...process.env, XDEBUG_MODE: 'off' }, encoding: 'utf8' }));
writeFileSync(resolve(materialDirectory, 'key.pem'), material.key, { mode: 0o600 });
writeFileSync(resolve(materialDirectory, 'cert.pem'), material.cert, { mode: 0o600 });
function launch(runtime, file, args = [], secure = false) {
  const command = runtime === 'node' ? ['node', resolve(directory, file), ...args]
    : ['php', '-d', 'memory_limit=1G', resolve(directory, 'run-php.php'), resolve(directory, file), ...args];
  const env = { ...process.env, XDEBUG_MODE: 'off' };
  if (secure) { env.PHASIS_HTTP2_TLS_KEY = resolve(materialDirectory, 'key.pem'); env.PHASIS_HTTP2_TLS_CERT = resolve(materialDirectory, 'cert.pem'); }
  const child = spawn(command[0], command.slice(1), { env, stdio: ['ignore', 'pipe', 'pipe'] });
  running.add(child);
  let output = '', errors = '';
  const lines = [], waiters = [];
  child.stdout.on('data', data => {
    output += data;
    while (output.includes('\n')) {
      const end = output.indexOf('\n'), line = output.slice(0, end); output = output.slice(end + 1);
      let decoded; try { decoded = JSON.parse(line); } catch { errors += line + '\n'; continue; }
      if (waiters.length) waiters.shift().resolve(decoded); else lines.push(decoded);
    }
  });
  child.stderr.on('data', data => { errors += data; });
  const completion = new Promise((resolve, reject) => {
    child.once('error', reject);
    child.once('exit', code => {
      running.delete(child);
      if (code === 0) resolve(); else reject(new Error(`${runtime} ${file} exited ${code}: ${errors}`));
      for (const waiter of waiters) waiter.reject(new Error(`${runtime} exited before producing a report: ${errors}`));
    });
  });
  completion.catch(() => {});
  return { completion, next: () => lines.length ? Promise.resolve(lines.shift()) : new Promise((resolve, reject) => waiters.push({ resolve, reject })) };
}
const timeout = setTimeout(() => {
  for (const child of running) child.kill('SIGKILL');
  process.exitCode = 1;
}, 60000);
try {
  for (const [serverRuntime, clientRuntime, secure] of [['node', 'phasis', false], ['phasis', 'node', false], ['node', 'phasis', true], ['phasis', 'node', true]]) {
    const server = launch(serverRuntime, 'server.cjs', [], secure);
    const { ready } = await server.next();
    const client = launch(clientRuntime, 'client.cjs', [String(ready)], secure);
    const { results } = await client.next();
    assert.equal(results.length, 3);
    for (const result of results) {
      assert.deepEqual(result, {
        id: result.id, status: 201, path: '/peer/' + result.id,
        header: 'shared header value across streams', trailer: 'yes', bytes: 70000 + result.id,
        sha256: createHash('sha256').update(Buffer.alloc(70000 + result.id, 65 + result.id)).digest('hex'),
      });
    }
    await Promise.all([client.completion, server.completion]);
    console.log(JSON.stringify({ server: serverRuntime, client: clientRuntime, tls: secure, streams: results.length, passed: true }));
  }
} finally {
  clearTimeout(timeout);
  for (const child of running) child.kill('SIGKILL');
  rmSync(materialDirectory, { recursive: true, force: true });
}
