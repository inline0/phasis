import { route } from 'remix/routes';

export function run() {
  const routes = route({ home: '/', posts: { index: '/posts', show: '/posts/:slug' } });
  return { home: routes.home.href(), post: routes.posts.show.href({ slug: 'remix' }) };
}
