// Compare unmodified WordPress helpers with Phasis through Node's HTML parser.
// php tests/Jsx/wordpress-boolean.php /path/to/wp-includes/general-template.php | node tests/Jsx/oracle/named-boolean.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const cases = JSON.parse(readFileSync(0, 'utf8'));
function shape(html) {
  const main = new JSDOM(html).window.document.querySelector('main');
  assert.ok(main);
  function nodeShape(node) {
    if (node.nodeType === 3) return ['text', node.textContent];
    return [node.nodeName, [...node.attributes].map((attr) => [attr.name, attr.value]).sort((a, b) => a[0].localeCompare(b[0])), [...node.childNodes].map(nodeShape)];
  }
  return nodeShape(main);
}
assert.equal(cases.length, 4);
for (const { wordpress, phasis, mode, flag } of cases) {
  assert.deepEqual(shape(phasis), shape(wordpress), `${mode}, flag=${flag}`);
}
process.stdout.write('WordPress boolean attributes match in both modes.\n');
