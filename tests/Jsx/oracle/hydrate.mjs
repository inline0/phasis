// Hydration check: React 19.3's client (react-dom/client in jsdom) hydrates
// the HTML Phasis renders for every React fixture, in the production and the
// development build. Passing means zero recoverable errors, zero hydration
// warnings, and every server element and text node reused.
//
//   node tests/Jsx/oracle/hydrate.mjs [fixture.jsx ...]
//
// Phasis's output comes from `php tests/Jsx/react-run.php <fixture>` (the
// prerender field, which Engine::render() returns). Fixtures can export
// `hydrate = false` when they deliberately throw while rendering, and
// `hydrateWarnings` with messages the development build may log.
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import esbuild from 'esbuild';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../../..');
const reactDir = path.resolve(here, '../react');
const outDir = path.join(here, '.out', 'hydrate');
fs.mkdirSync(outDir, { recursive: true });

const fixtures = process.argv.length > 2
    ? process.argv.slice(2).map((f) => path.resolve(f))
    : fs.readdirSync(reactDir).filter((f) => /\.(?:jsx|tsx)$/.test(f)).sort().map((f) => path.join(reactDir, f));

let failed = 0;
let checked = 0;
const skipped = [];
for (const fixture of fixtures) {
    const name = path.basename(fixture, '.jsx');
    const json = execFileSync('php', ['-d', 'memory_limit=1G', path.join(root, 'tests/Jsx/react-run.php'), fixture], { encoding: 'utf8', cwd: root });
    const { prerender } = JSON.parse(json);
    const bundle = path.join(outDir, name + '.mjs');
    await esbuild.build({
        entryPoints: [fixture],
        outfile: bundle,
        bundle: true,
        format: 'esm',
        platform: 'node',
        jsx: 'automatic',
        external: ['react', 'react/*', 'react-dom', 'react-dom/*'],
        logLevel: 'silent',
    });
    const mod = await import(bundle);
    if (mod.hydrate === false || prerender.startsWith('ERROR: ')) {
        skipped.push(`${name} (${mod.hydrate === false ? 'not hydratable by React itself' : 'render error'})`);
        continue;
    }
    const htmlFile = path.join(outDir, name + '.html');
    fs.writeFileSync(htmlFile, prerender);
    for (const env of ['production', 'development']) {
        checked++;
        let result;
        try {
            result = execFileSync(process.execPath, [path.join(here, 'hydrate-one.mjs'), bundle, htmlFile], {
                encoding: 'utf8',
                cwd: here,
                env: { ...process.env, NODE_ENV: env, TZ: 'UTC' },
                timeout: 30000,
            });
        } catch (error) {
            result = JSON.stringify({ ok: false, problems: ['crashed: ' + (error.stderr || error.message)] });
        }
        const report = JSON.parse(result.trim().split('\n').pop());
        if (!report.ok) {
            failed++;
            console.log(`FAIL ${name} (${env})`);
            for (const p of report.problems) console.log('  ' + p);
        }
    }
}
console.log(`hydration: ${checked - failed}/${checked} passed`);
console.log(`skipped: ${skipped.join(', ')}`);
process.exit(failed === 0 ? 0 : 1);
