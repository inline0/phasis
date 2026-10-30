// React's rendered DOM is the oracle for the opt-in HTML spelling choices.
// Regenerate: node tests/Jsx/oracle/text-attribute-escaping.mjs > tests/Jsx/oracle/text-attribute-escaping.expected.json
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { JSDOM } from 'jsdom';

const h = React.createElement;
const text = '"dynamic" & < > &quot;';
const attr = '[&>span>span] " < &quot;';
const node = h('main', null,
  h('section', null,
    h('p', { 'data-static': '[&>span>span] " <', 'data-dynamic': attr }, 'Static "quote" & < > &quot; ', text),
    h('pre', null, '"pre" & < >'), h('code', null, '"code" & < >')),
  h('svg', null, h('a', { 'data-svg': attr }, h('foreignObject', null,
    h('div', { 'data-foreign': attr }, '"foreign" ', text)))));
const document = new JSDOM(renderToStaticMarkup(node)).window.document;
const p = document.querySelector('p');
process.stdout.write(JSON.stringify({
  paragraph: p.textContent,
  staticAttribute: p.getAttribute('data-static'),
  dynamicAttribute: p.getAttribute('data-dynamic'),
  pre: document.querySelector('pre').textContent,
  code: document.querySelector('code').textContent,
  svgAttribute: document.querySelector('svg a').getAttribute('data-svg'),
  foreignAttribute: document.querySelector('[data-foreign]').getAttribute('data-foreign'),
  foreignText: document.querySelector('[data-foreign]').textContent,
}, null, 2) + '\n');
