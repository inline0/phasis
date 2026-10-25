// Native browser-reference fixture. Host I/O and process ownership live in hydration-contract.ts.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { JSDOM } from 'jsdom';
const [htmlFile, codeFile, initial] = process.argv.slice(2);
const html = fs.readFileSync(htmlFile, 'utf8');
const code = fs.readFileSync(codeFile, 'utf8');
let checks = Number(initial);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const until = async (test) => {
    const deadline = Date.now() + 5000;
    while (!test()) {
        if (Date.now() >= deadline) throw new Error('Timed out waiting for React.');
        await sleep(10);
    }
};
        const dom = new JSDOM(`<!DOCTYPE html><html><body>${html}</body></html>`, {
            runScripts: 'outside-only', pretendToBeVisual: true, url: 'https://consumer.test/',
        });
        const { window } = dom;
        const logs = [];
        window.console.error = (...args) => logs.push(args.map(String).join(' '));
        window.console.warn = (...args) => logs.push(args.map(String).join(' '));
        const roots = [];
        try {
            window.eval(code);
            const { hydrate, HydrationError, components } = window.contract;
            const errors = [];
            const options = (name = 'default') => ({
                entryId: 'contract', buildId: 'build-123', export: name,
                onRecoverableError: (error) => errors.push(error),
                onUncaughtError: (error) => errors.push(error),
                onCaughtError: (error) => errors.push(error),
            });
            const before = Array.from(window.document.querySelectorAll('div[id]')).flatMap((root) => {
                const nodes = [];
                const walker = window.document.createTreeWalker(root, 1 | 4 | 128);
                for (let node = walker.nextNode(); node; node = walker.nextNode()) nodes.push(node);
                return nodes;
            });
            for (const [id, name] of [['alpha', 'default'], ['beta', 'default'], ['named', 'Defaults'], ['empty', 'Defaults'],
                ['shapes', 'Shapes'], ['keys', 'Keys']]) {
                roots.push(hydrate(window.document.getElementById(id), components, options(name)));
            }
            await sleep(100);
            assert.deepEqual(errors, []);
            assert.deepEqual(logs, [], 'Hydration must produce no React warnings.');
            assert.ok(before.every((node) => node.isConnected), 'All server elements, text and comments must be reused.');
            assert.equal(window.document.querySelector('#alpha output').textContent, '11:0');
            const alphaState = JSON.parse(window.document.getElementById('alpha').nextElementSibling.textContent);
            assert.equal(alphaState.props.data.value, 10, 'Payload must precede render mutation.');
            assert.equal(window.document.querySelector('#named p').textContent, 'fallback:label');
            assert.equal(window.document.querySelector('#empty p').textContent, 'fallback:label');
            const a = window.document.querySelector('#alpha button');
            const b = window.document.querySelector('#beta button');
            assert.ok(a.id.startsWith('_alpha-'));
            assert.ok(b.id.startsWith('_beta-'));
            assert.notEqual(a.id, b.id);
            assert.equal(window.exploit, undefined);
            assert.equal(window.document.querySelectorAll('script').length, 6, 'Captured text must not escape an inert state tag.');
            assert.equal(window.Object.prototype.polluted, undefined);
            a.click();
            await until(() => window.document.querySelector('#alpha output').textContent.endsWith(':1'));
            assert.equal(window.document.querySelector('#beta output').textContent, '20:0');
            checks += 15;

            // Mismatched metadata fails synchronously, preserving the exact nodes.
            const base = window.document.getElementById('beta');
            const baseState = JSON.parse(base.nextElementSibling.textContent);
            const make = (change = () => {}) => {
                const root = window.document.createElement('div');
                const id = `failure-${checks}`;
                root.id = id;
                root.innerHTML = base.innerHTML;
                const tag = window.document.createElement('script');
                tag.type = 'application/json';
                tag.setAttribute('data-phasis-state', id);
                const state = { ...baseState, rootId: id };
                change(state);
                tag.textContent = JSON.stringify(state);
                window.document.body.append(root, tag);
                return { root, tag };
            };
            const cases = [
                ['protocol', (state) => { state.protocol = 2; }],
                ['entry', (state) => { state.entryId = 'other'; }],
                ['build', (state) => { state.buildId = 'old'; }],
                ['export', (state) => { state.export = 'Defaults'; }],
                ['React', (state) => { state.reactVersion = '0.0.0'; }],
                ['root', (state) => { state.rootId = 'other'; }],
                ['extra executable URL', (state) => { state.url = 'https://untrusted.test/run.js'; }],
                ['array props', (state) => { state.props = []; }],
                ['null props', (state) => { state.props = null; }],
                ['missing key', (state) => { delete state.identifierPrefix; }],
            ];
            for (const [name, change] of cases) {
                const { root, tag } = make(change);
                const node = root.firstChild;
                const original = root.innerHTML;
                const reports = [];
                assert.throws(() => hydrate(root, components, { ...options(), onError: (error) => reports.push(error) }), HydrationError, name);
                assert.equal(reports.length, 1);
                assert.equal(root.innerHTML, original);
                assert.equal(root.firstChild, node);
                root.remove(); tag.remove();
                checks++;
            }
            for (const text of ['{', '{"protocol":1}', base.nextElementSibling.textContent.replace('20', '1e400')]) {
                const { root, tag } = make();
                tag.textContent = text;
                assert.throws(() => hydrate(root, components, options()), HydrationError);
                root.remove(); tag.remove();
                checks++;
            }
            const inherited = make();
            assert.throws(() => hydrate(inherited.root, Object.create({ default: components.default }), options()), HydrationError);
            inherited.root.remove(); inherited.tag.remove();
            checks++;
            assert.throws(() => hydrate(base, components, options()), HydrationError, 'duplicate initialization');
            window.eval(code);
            assert.throws(() => window.contract.hydrate(base, components, options()), /already been initialized/, 'duplicate helper bundle');
            checks += 2;

            // Real markup mismatches go to the caller's React diagnostics.
            const mismatch = make();
            mismatch.root.querySelector('output').textContent = 'stale';
            roots.push(hydrate(mismatch.root, components, options()));
            await until(() => errors.length > 0);
            assert.ok(errors.some((error) => /hydrat|match|#418/i.test(String(error))));
            checks++;
        } finally {
            for (const root of roots) root.unmount();
            window.close();
        }

console.log(JSON.stringify({ checks }));
