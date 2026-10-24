'use strict';

const crypto = require('node:crypto');
const { Readable } = require('node:stream');
const out = {};
out.hashes = [crypto.createHash('sha1').update('abc').digest('base64'),
  crypto.createHash('sha256').update(Buffer.from([0, 255, 128])).digest('hex'),
  crypto.createHash('sha512').update(new DataView(Uint8Array.from([1, 2, 3]).buffer, 1)).digest('hex'),
  crypto.hash('sha256', 'abc'), crypto.hash('sha1', 'abc', 'buffer').toString('hex')];
const hash = crypto.createHash('sha256').update('first');
out.copy = [hash.copy().update('copy').digest('hex'), hash.update('original').digest('hex')];
out.hmac = [crypto.createHmac('sha256', 'key').update('message').digest('hex'),
  crypto.createHmac('sha256', '').update('').digest('base64')];
const hmac = crypto.createHmac('sha1', 'a'); hmac.digest();
out.hmac.push(hmac.digest('hex'));
out.pbkdf2 = crypto.pbkdf2Sync('password', 'salt', 3, 32, 'sha256').toString('hex');
out.hkdf = [Buffer.from(crypto.hkdfSync('sha256', '', '', '', 8)).toString('hex'),
  Buffer.from(crypto.hkdfSync('sha256', 'key', 'salt', 'info', 42)).toString('hex')];
out.equal = [crypto.timingSafeEqual(Buffer.from([1, 2]), Uint8Array.from([1, 2])),
  crypto.timingSafeEqual(Buffer.from([1, 2]), Uint8Array.from([1, 3]))];
out.random = [Buffer.isBuffer(crypto.randomBytes(16)), crypto.randomBytes(0).length,
  /^[\da-f]{8}-[\da-f]{4}-4[\da-f]{3}-[89ab][\da-f]{3}-[\da-f]{12}$/.test(crypto.randomUUID()),
  crypto.randomInt(4, 5)];
const target = new Uint16Array([1234, 0, 4321]);
out.random.push(crypto.randomFillSync(target, 1, 1) === target, target[0], target[2]);
out.identity = [crypto.webcrypto === globalThis.crypto, crypto.subtle === globalThis.crypto.subtle];
out.errors = [];
for (const fn of [() => hash.update('x'), () => hash.digest(),
  () => crypto.randomBytes(-1), () => crypto.randomInt(2, 2),
  () => crypto.timingSafeEqual(Buffer.alloc(1), Buffer.alloc(2)),
  () => crypto.randomFillSync(new Uint16Array(2), 2, 1)]) {
  try { fn(); out.errors.push('unexpected pass'); } catch (error) { out.errors.push([error.name, error.code]); }
}
out.async = [];
crypto.randomBytes(0, (error, value) => out.async.push(['random', error, value.length]));
crypto.pbkdf2('p', 's', 1, 8, 'sha256', (error, value) => out.async.push(['pbkdf2', error, value.toString('hex')]));
const stream = crypto.createHash('sha256');
stream.on('data', value => { out.stream = value.toString('hex'); });
Readable.from(['a', 'b', 'c']).pipe(stream);
globalThis.cryptoResult = out;
