// Refresh native JavaScript/JSON/CSS formatter fixtures with the pinned Prettier.
// npm ci --prefix tests/Jsx/oracle
// node tests/Jsx/oracle/formatter-oracle.mjs
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import prettier from 'prettier';

const fixtures = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../Unit/Formatter/fixtures');
const cases = [];
for (const file of fs.readdirSync(fixtures).filter(name => name.endsWith('.in.js')).sort()) {
    const source = fs.readFileSync(path.join(fixtures, file), 'utf8');
    cases.push({ name: file, source, type: /^\s*(import|export)\b/m.test(source) ? 'module' : 'script', options: { useTabs: true, singleQuote: true } });
}
for (const file of fs.readdirSync(path.join(fixtures, 'css')).filter(name => name.endsWith('.in.css')).sort()) {
    cases.push({ name: `css/${file}`, source: fs.readFileSync(path.join(fixtures, 'css', file), 'utf8'), type: 'css', options: {} });
}
cases.push(...JSON.parse(fs.readFileSync(path.join(fixtures, 'prettier-input.json'), 'utf8')));
for (const item of cases) {
    item.expected = await prettier.format(item.source, { ...item.options, parser: item.type === 'css' ? 'css' : item.type === 'json' ? 'json' : 'babel' });
}
fs.writeFileSync(path.join(fixtures, 'prettier-expected.json'), JSON.stringify({ version: prettier.version, cases }, null, 2) + '\n');
console.log(`Prettier ${prettier.version}: ${cases.length} formatter fixtures`);
