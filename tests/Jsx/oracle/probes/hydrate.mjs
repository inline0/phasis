// Hydrates one fixture's server HTML in jsdom and prints a JSON report.
// Usage (NODE_ENV selects the React build): node probes/hydrate.mjs <bundle.mjs> <server.html>
import fs from 'node:fs';
import { JSDOM } from 'jsdom';

const [bundle, htmlFile] = process.argv.slice(2);
const html = fs.readFileSync(htmlFile, 'utf8');
const isDocument = html.startsWith('<!DOCTYPE');
const dom = new JSDOM(
    isDocument ? html : `<!DOCTYPE html><html><head></head><body><div id="root">${html}</div></body></html>`,
    { runScripts: 'dangerously', pretendToBeVisual: true, url: 'https://example.test/' },
);
const { window } = dom;
for (const key of ['window', 'document', 'navigator', 'HTMLElement', 'HTMLIFrameElement', 'Node', 'Element', 'Event', 'requestAnimationFrame', 'cancelAnimationFrame', 'location', 'getComputedStyle']) {
    Object.defineProperty(globalThis, key, { value: key === 'window' ? window : window[key], configurable: true, writable: true });
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const problems = [];
const logged = [];
const originalError = console.error;
const originalWarn = console.warn;
console.error = (...args) => logged.push(args.map(String).join(' '));
console.warn = (...args) => logged.push(args.map(String).join(' '));

// Let inline instruction scripts ($RC, $RX) settle before hydrating.
await sleep(400);

const container = isDocument ? window.document : window.document.getElementById('root');
const exempt = new Set();
const before = [];
const walker = window.document.createTreeWalker(container, window.NodeFilter.SHOW_ELEMENT | window.NodeFilter.SHOW_TEXT | window.NodeFilter.SHOW_COMMENT);
let inClientRendered = 0;
for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    if (node.nodeType === 8) {
        if (node.data === '$!') inClientRendered++;
        else if (node.data === '/$' && inClientRendered > 0) inClientRendered--;
        continue;
    }
    // Text inside <textarea> is its value, which React manages itself.
    if (inClientRendered > 0 || (node.nodeType === 3 && node.parentNode && node.parentNode.nodeName === 'TEXTAREA')) exempt.add(node);
    before.push(node);
}

const { createElement } = await import('react');
const { hydrateRoot } = await import('react-dom/client');
const mod = await import(bundle);
const recoverable = [];
hydrateRoot(container, createElement(mod.default, mod.props ?? {}), {
    identifierPrefix: mod.options?.identifierPrefix,
    onRecoverableError: (error) => recoverable.push(String(error && error.message)),
    onUncaughtError: (error) => recoverable.push('uncaught: ' + String(error && error.message)),
});
await sleep(300);

for (const message of recoverable) problems.push('recoverable error: ' + message);
// Hydration warnings fail the check; React's other development warnings
// (deprecated lifecycles, iterator children, controlled fields) are about the
// component, not the server HTML, and are only listed.
const allowed = mod.hydrateWarnings ?? [];
const notes = [];
for (const message of logged) {
    if (allowed.some((a) => message.includes(a))) continue;
    if (/hydrat|did not match|didn't match|server rendered|server HTML/i.test(message)) {
        problems.push('logged: ' + message.slice(0, process.env.HYDRATE_VERBOSE ? 5000 : 300));
    } else {
        notes.push(message.slice(0, 120));
    }
}
const replaced = before.filter((node) => !exempt.has(node) && !node.isConnected);
if (replaced.length > 0) {
    problems.push(`${replaced.length} server node(s) not reused, first: ${replaced[0].nodeName} ${replaced[0].textContent.slice(0, 60)}`);
}
console.error = originalError;
console.warn = originalWarn;
process.stdout.write(JSON.stringify({ ok: problems.length === 0, problems, notes }) + '\n');
process.exit(0);
