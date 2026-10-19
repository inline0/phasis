import { createCookie } from 'remix/cookie';

export async function run() {
  const cookie = createCookie('prefs', { httpOnly: true, sameSite: 'lax', path: '/' });
  const serialized = await cookie.serialize(JSON.stringify({ theme: 'dark', label: '雪' }));
  return { serialized, parsed: JSON.parse(await cookie.parse(serialized)), absent: await cookie.parse(null), signed: cookie.signed };
}
