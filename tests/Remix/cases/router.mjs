import { createRouter } from 'remix/router';
import { route } from 'remix/routes';

export async function run() {
  const routes = route({ home: '/', post: '/posts/:slug' });
  const router = createRouter();
  router.map(routes, { actions: { home: () => new Response('Home'),
    post: ({ params, url }) => new Response(params.slug + ':' + url.searchParams.get('q')) } });
  const found = await router.fetch(new Request('https://example.com/posts/hello?q=remix'));
  const missing = await router.fetch(new Request('https://example.com/missing'));
  return { status: found.status, body: await found.text(), missing: missing.status };
}
