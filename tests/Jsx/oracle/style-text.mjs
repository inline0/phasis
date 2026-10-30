// Node oracle: React's static serialization of a style's single string child.
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const inputs = [
  'a > b { content: "&"; }',
  '</style>',
  '</STYLE > and <style',
  '</sTyLe/x and </stylesheet>',
  'text &amp; more \u96ea',
  '<!-- <style> then </style> -->',
];
const cases = inputs.map((input) => ({
  input,
  html: renderToStaticMarkup(React.createElement('style', null, input)),
}));
process.stdout.write(JSON.stringify(cases, null, 2) + '\n');
