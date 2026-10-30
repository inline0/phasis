// Node oracle: React's static serialization of a script's single string child.
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const inputs = [
  'if (a < b && x > 1) { console.log("ok") }',
  '</script>',
  '</SCRIPT > and <script',
  '</sCrIpT/x and </scripture>',
  'text &amp; more \u96ea',
  '<!-- <script> then </script> -->',
];
const cases = inputs.map((input) => ({
  input,
  html: renderToStaticMarkup(React.createElement('script', null, input)),
}));
process.stdout.write(JSON.stringify(cases, null, 2) + '\n');
