import { createCookieSessionStorage } from 'remix/session-storage/cookie';

export async function run() {
  const storage = createCookieSessionStorage();
  const first = await storage.read(null);
  first.set('count', 1);
  first.flash('notice', 'saved');
  const cookie = await storage.save(first);
  const second = await storage.read(cookie);
  const notice = second.get('notice');
  const third = await storage.read(await storage.save(second));
  return { count: second.get('count'), notice, consumed: third.get('notice') ?? null };
}
