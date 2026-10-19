import { RoutePattern } from 'remix/route-pattern';
import { createHref } from 'remix/route-pattern/href';
import { createMultiMatcher } from 'remix/route-pattern/match';

export function run() {
  const matcher = createMultiMatcher();
  matcher.add('/posts/:slug', { name: 'post' });
  matcher.add('/posts/new', { name: 'new' });
  matcher.add('/files/*path', { name: 'files' });
  return {
    pattern: RoutePattern.parse('/posts/:slug').toString(),
    href: createHref('/posts/:slug', { slug: 'hello world' }),
    optional: createHref('/api(/v:version)/*path', { version: '2', path: 'users/profile' }),
    static: matcher.match('https://example.com/posts/new')?.data,
    parameter: matcher.match('https://example.com/posts/hello')?.params,
    wildcard: matcher.match('https://example.com/files/a/b')?.params,
    missing: matcher.match('https://example.com/missing') === null,
  };
}
