import { SuperHeaders as Headers } from 'remix/headers';

export function run() {
  const headers = new Headers({ 'Content-Type': 'application/json; charset=utf-8' });
  const before = headers.contentType.charset;
  headers.contentType.charset = 'iso-8859-1';
  headers.cacheControl = { public: true, maxAge: 60 };
  headers.setCookie = { name: 'session', value: 'abc', httpOnly: true };
  headers.setCookie.push({ name: 'theme', value: 'dark', path: '/' });
  return { before, type: headers.get('content-type'), cache: headers.get('cache-control'),
    cookies: headers.getSetCookie(), native: headers instanceof globalThis.Headers };
}
