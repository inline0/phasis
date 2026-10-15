// Renders a React fixture module with renderToString, renderToStaticMarkup
// and prerender. Shared by the Node oracle and the Phasis tests, so both
// sides run exactly the same calls in the same order.
import { createElement } from 'react';
import { renderToString, renderToStaticMarkup } from 'react-dom/server';
import { prerender } from 'react-dom/static';

// Reads a byte stream with a reader: React's edge build produces its
// prelude on pull, which Phasis's Response body does not wait for.
async function readAll(stream) {
  const reader = stream.getReader();
  const decoder = new TextDecoder();
  let text = '';
  for (;;) {
    const { done, value } = await reader.read();
    if (done) return text + decoder.decode();
    text += decoder.decode(value, { stream: true });
  }
}

export async function run(mod) {
  const props = mod.props ?? {};
  const options = mod.options ?? {};
  const element = () => createElement(mod.default, props);
  const out = {};
  for (const [name, fn] of [['string', renderToString], ['static', renderToStaticMarkup]]) {
    try {
      out[name] = fn(element(), options);
    } catch (e) {
      out[name] = 'ERROR: ' + (e && e.message);
    }
  }
  try {
    const { prelude } = await prerender(element(), { ...options, onError() {} });
    out.prerender = await readAll(prelude);
  } catch (e) {
    out.prerender = 'ERROR: ' + (e && e.message);
  }
  return out;
}
