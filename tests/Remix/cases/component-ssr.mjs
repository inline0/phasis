import { jsx, jsxs } from 'remix/component/jsx-runtime';
import { renderToStream } from 'remix/component/server';

export async function run() {
  const tree = jsxs('article', { class: 'card', children: [jsx('h1', { children: '雪 & <Remix>' }),
    jsx('input', { disabled: true, value: 'hello' }), jsx('a', { href: '/next', children: 'Next' })] });
  return { html: await new Response(renderToStream(tree)).text() };
}
