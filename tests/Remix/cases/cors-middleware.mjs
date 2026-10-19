import { createRouter } from 'remix/router';
import { route } from 'remix/routes';
import { cors } from 'remix/middleware/cors';

export async function run() {
  const router = createRouter({ middleware: [cors()] });
  router.map(route({ home: '/' }), { actions: { home: () => new Response('ok') } });
  const response = await router.fetch(new Request('https://example.com/', { headers: { Origin: 'https://client.example' } }));
  const preflight = await router.fetch(new Request('https://example.com/', { method: 'OPTIONS',
    headers: { Origin: 'https://client.example', 'Access-Control-Request-Method': 'POST' } }));
  return { body: await response.text(), origin: response.headers.get('access-control-allow-origin'),
    preflight: preflight.status, methods: preflight.headers.get('access-control-allow-methods') };
}
