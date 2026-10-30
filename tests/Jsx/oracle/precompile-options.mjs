// React's server output, normalized as HTML, for the precompiled options test.
import React from 'react';
import { renderToReadableStream } from 'react-dom/server';
import { JSDOM } from 'jsdom';

const h = React.createElement;
const text = 'a & b < c';
const script = 'if (a < b) { x = "</script>"; }';
async function Child() {
  await Promise.resolve();
  return h('div', null, h('p', { value: '', 'data-x': '' }, 'Static "quoted" > ', text));
}
const element = h('main', null,
  h('section', null, h('p', { value: '', 'data-x': '' }, 'Outside ', text)),
  h('noscript', null, h('div', null,
    h('p', { value: '', 'data-x': '' }, 'Static "quoted" > ', text), h(Child))),
  h('script', null, script),
  h('section', null, h('p', null, 'After')),
);
const stream = await renderToReadableStream(element);
const html = await new Response(stream).text();
const main = new JSDOM(html).window.document.querySelector('main');
// The HTML profile intentionally omits React's text-node hydration markers.
const comments = main.ownerDocument.createTreeWalker(main, 128);
const markers = [];
while (comments.nextNode()) markers.push(comments.currentNode);
for (const marker of markers) marker.remove();
process.stdout.write(JSON.stringify({ html: main.outerHTML }, null, 2) + '\n');
