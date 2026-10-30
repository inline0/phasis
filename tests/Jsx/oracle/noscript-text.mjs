// Node oracle: React escaping followed by an HTML parser and serializer.
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { JSDOM } from 'jsdom';

const inputs = [
  `Software's "tools" <UI> & more © &`,
  'Literal &amp; and <tag> plus > and "quotes"',
  'plain 雪',
];
const cases = inputs.map((input) => {
  const html = renderToStaticMarkup(React.createElement('p', null, input));
  const paragraph = new JSDOM(html).window.document.querySelector('p');
  return { input, normalized: paragraph.innerHTML };
});
process.stdout.write(JSON.stringify(cases, null, 2) + '\n');
