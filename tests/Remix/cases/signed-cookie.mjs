import { createCookie } from 'remix/cookie';

export async function run() {
  const old = createCookie('session', { secrets: ['old-key'] });
  const rotated = createCookie('session', { secrets: ['new-key', 'old-key'] });
  const serialized = await old.serialize(JSON.stringify({ user: 7 }));
  return { signed: rotated.signed, value: JSON.parse(await rotated.parse(serialized)), tampered: await rotated.parse('session=invalid') };
}
