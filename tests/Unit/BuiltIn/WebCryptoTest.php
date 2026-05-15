<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * WebCrypto surface: `crypto.getRandomValues`, `crypto.randomUUID`,
 * `crypto.subtle.{digest,sign,verify,encrypt,decrypt,generateKey,
 * importKey,exportKey}`.
 *
 * Randomness assertions use property checks (size, format, distinct
 * outputs) rather than exact-bytes comparisons. Deterministic vectors
 * (SHA-256 of empty / 'hello', HMAC RFC test vectors) are used where
 * available.
 */
class WebCryptoTest extends TestCase
{
    public function testRandomUuidV4Format(): void
    {
        $engine = new Engine();
        $uuid = $engine->eval('crypto.randomUUID()');
        $this->assertIsString($uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testRandomUuidsAreDistinct(): void
    {
        $engine = new Engine();
        $set = $engine->eval(
            'new Set([crypto.randomUUID(), crypto.randomUUID(), crypto.randomUUID(),'
            . ' crypto.randomUUID(), crypto.randomUUID()]).size'
        );
        $this->assertSame(5, $set);
    }

    public function testGetRandomValuesFillsAndReturnsSameArray(): void
    {
        $engine = new Engine();
        $isSame = $engine->eval(<<<'JS'
(function () {
    const buf = new Uint8Array(32);
    const ret = crypto.getRandomValues(buf);
    return ret === buf;
})()
JS);
        $this->assertTrue($isSame);
        $nonZero = $engine->eval(<<<'JS'
(function () {
    const buf = new Uint8Array(32);
    crypto.getRandomValues(buf);
    return buf.some(b => b !== 0);
})()
JS);
        $this->assertTrue($nonZero, 'Expected at least one non-zero byte in 32 random bytes');
    }

    public function testGetRandomValuesRejectsFloatArrays(): void
    {
        $engine = new Engine();
        $threw = $engine->eval(<<<'JS'
let threw = false;
try { crypto.getRandomValues(new Float32Array(8)); }
catch (e) { threw = e instanceof TypeError; }
threw
JS);
        $this->assertTrue($threw);
    }

    public function testGetRandomValuesRespectsQuotaCap(): void
    {
        // The 65,536-byte limit is documented and enforced by browsers.
        $engine = new Engine();
        $threw = $engine->eval(<<<'JS'
let threw = false;
try { crypto.getRandomValues(new Uint8Array(70000)); }
catch (e) { threw = e instanceof TypeError; }
threw
JS);
        $this->assertTrue($threw);
    }

    public function testSubtleDigestSha256OfHello(): void
    {
        // SHA-256("hello") — well-known vector.
        $engine = new Engine();
        $engine->eval(<<<'JS'
crypto.subtle.digest('SHA-256', new TextEncoder().encode('hello')).then(buf => {
    globalThis.__hex = Array.from(new Uint8Array(buf))
        .map(b => b.toString(16).padStart(2, '0')).join('');
});
JS);
        $this->assertSame(
            '2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824',
            $engine->eval('globalThis.__hex'),
        );
    }

    public function testSubtleDigestSha1Sha384Sha512(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
async function digestHex(algo, text) {
    const buf = await crypto.subtle.digest(algo, new TextEncoder().encode(text));
    return Array.from(new Uint8Array(buf))
        .map(b => b.toString(16).padStart(2, '0')).join('');
}
(async () => {
    globalThis.__sha1   = await digestHex('SHA-1',   'abc');
    globalThis.__sha384 = await digestHex('SHA-384', 'abc');
    globalThis.__sha512 = await digestHex('SHA-512', 'abc');
})();
JS);
        // NIST test vectors for "abc".
        $this->assertSame('a9993e364706816aba3e25717850c26c9cd0d89d', $engine->eval('globalThis.__sha1'));
        $this->assertSame(
            'cb00753f45a35e8bb5a03d699ac65007272c32ab0eded163'
            . '1a8b605a43ff5bed8086072ba1e7cc2358baeca134c825a7',
            $engine->eval('globalThis.__sha384'),
        );
        $this->assertSame(
            'ddaf35a193617abacc417349ae20413112e6fa4e89a97ea2'
            . '0a9eeee64b55d39a2192992a274fc1a836ba3c23a3feebbd'
            . '454d4423643ce80e2a9ac94fa54ca49f',
            $engine->eval('globalThis.__sha512'),
        );
    }

    public function testSubtleHmacSignVerifyRoundtrip(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const key = await crypto.subtle.importKey(
        'raw',
        new TextEncoder().encode('supersecret'),
        { name: 'HMAC', hash: 'SHA-256' },
        true,
        ['sign', 'verify'],
    );
    const data = new TextEncoder().encode('hello');
    const sig = await crypto.subtle.sign('HMAC', key, data);
    globalThis.__verified = await crypto.subtle.verify('HMAC', key, sig, data);
})();
JS);
        $this->assertTrue($engine->eval('globalThis.__verified'));
    }

    public function testSubtleHmacVerifyRejectsTamperedSignature(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const key = await crypto.subtle.importKey(
        'raw', new TextEncoder().encode('k'),
        { name: 'HMAC', hash: 'SHA-256' },
        true, ['sign', 'verify'],
    );
    const data = new TextEncoder().encode('hello');
    const sig = new Uint8Array(await crypto.subtle.sign('HMAC', key, data));
    sig[0] ^= 0xFF;
    globalThis.__verified = await crypto.subtle.verify('HMAC', key, sig, data);
})();
JS);
        $this->assertFalse($engine->eval('globalThis.__verified'));
    }

    public function testSubtleAesGcmRoundtrip(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('AES-GCM requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const key = await crypto.subtle.generateKey(
        { name: 'AES-GCM', length: 256 },
        true,
        ['encrypt', 'decrypt'],
    );
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const plaintext = new TextEncoder().encode('the answer is 42');
    const ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plaintext);
    const pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ct);
    globalThis.__rt = new TextDecoder().decode(pt);
})();
JS);
        $this->assertSame('the answer is 42', $engine->eval('globalThis.__rt'));
    }

    public function testSubtleAesCbcRoundtrip(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('AES-CBC requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const key = await crypto.subtle.generateKey(
        { name: 'AES-CBC', length: 128 },
        true,
        ['encrypt', 'decrypt'],
    );
    const iv = crypto.getRandomValues(new Uint8Array(16));
    const plaintext = new TextEncoder().encode('secret message');
    const ct = await crypto.subtle.encrypt({ name: 'AES-CBC', iv }, key, plaintext);
    const pt = await crypto.subtle.decrypt({ name: 'AES-CBC', iv }, key, ct);
    globalThis.__rt = new TextDecoder().decode(pt);
})();
JS);
        $this->assertSame('secret message', $engine->eval('globalThis.__rt'));
    }

    public function testExportImportJwkRoundtrip(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const k1 = await crypto.subtle.generateKey(
        { name: 'HMAC', hash: 'SHA-256' },
        true, ['sign', 'verify'],
    );
    const jwk = await crypto.subtle.exportKey('jwk', k1);
    globalThis.__kty = jwk.kty;
    globalThis.__alg = jwk.alg;
    globalThis.__ext = jwk.ext;

    const k2 = await crypto.subtle.importKey(
        'jwk', jwk,
        { name: 'HMAC', hash: 'SHA-256' },
        true, ['sign', 'verify'],
    );
    const data = new TextEncoder().encode('roundtrip');
    const sig = await crypto.subtle.sign('HMAC', k1, data);
    globalThis.__sameKey = await crypto.subtle.verify('HMAC', k2, sig, data);
})();
JS);
        $this->assertSame('oct', $engine->eval('globalThis.__kty'));
        $this->assertSame('HS256', $engine->eval('globalThis.__alg'));
        $this->assertTrue($engine->eval('globalThis.__ext'));
        $this->assertTrue($engine->eval('globalThis.__sameKey'));
    }

    public function testLazyCryptoIsExposed(): void
    {
        $engine = new Engine();
        $this->assertSame('object', $engine->eval('typeof crypto'));
        $this->assertSame('function', $engine->eval('typeof crypto.getRandomValues'));
        $this->assertSame('function', $engine->eval('typeof crypto.randomUUID'));
        $this->assertSame('object', $engine->eval('typeof crypto.subtle'));
        $this->assertSame('function', $engine->eval('typeof crypto.subtle.digest'));
        $this->assertSame('function', $engine->eval('typeof crypto.subtle.deriveBits'));
        $this->assertSame('function', $engine->eval('typeof crypto.subtle.deriveKey'));
    }

    // ---------------------------------------------------------------
    // RSA
    // ---------------------------------------------------------------

    public function testRsassaPkcs1V1_5SignVerify(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('RSA requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const pair = await crypto.subtle.generateKey(
        { name: 'RSASSA-PKCS1-v1_5', modulusLength: 2048,
          publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' },
        true, ['sign', 'verify'],
    );
    globalThis.__pubType = pair.publicKey.type;
    globalThis.__privType = pair.privateKey.type;
    const data = new TextEncoder().encode('rsa pkcs1 v1.5');
    const sig = await crypto.subtle.sign('RSASSA-PKCS1-v1_5', pair.privateKey, data);
    globalThis.__verify = await crypto.subtle.verify('RSASSA-PKCS1-v1_5', pair.publicKey, sig, data);
    // Tampered data should fail to verify.
    const bad = new TextEncoder().encode('rsa pkcs1 v1.5!');
    globalThis.__verifyBad = await crypto.subtle.verify('RSASSA-PKCS1-v1_5', pair.publicKey, sig, bad);
})();
JS);
        $this->assertSame('public', $engine->eval('globalThis.__pubType'));
        $this->assertSame('private', $engine->eval('globalThis.__privType'));
        $this->assertTrue($engine->eval('globalThis.__verify'));
        $this->assertFalse($engine->eval('globalThis.__verifyBad'));
    }

    public function testRsaPssSignVerify(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('RSA-PSS requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const pair = await crypto.subtle.generateKey(
        { name: 'RSA-PSS', modulusLength: 2048,
          publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' },
        true, ['sign', 'verify'],
    );
    const data = new TextEncoder().encode('rsa pss roundtrip');
    const sig = await crypto.subtle.sign({ name: 'RSA-PSS', saltLength: 32 }, pair.privateKey, data);
    globalThis.__verify = await crypto.subtle.verify({ name: 'RSA-PSS', saltLength: 32 }, pair.publicKey, sig, data);
    globalThis.__sigLen = sig.byteLength;
})();
JS);
        $this->assertTrue($engine->eval('globalThis.__verify'));
        $this->assertSame(256, $engine->eval('globalThis.__sigLen'));
    }

    public function testRsaOaepEncryptDecryptSha1(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('RSA-OAEP requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const pair = await crypto.subtle.generateKey(
        { name: 'RSA-OAEP', modulusLength: 2048,
          publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-1' },
        true, ['encrypt', 'decrypt'],
    );
    const plain = new TextEncoder().encode('secret payload');
    const ct = await crypto.subtle.encrypt({ name: 'RSA-OAEP' }, pair.publicKey, plain);
    globalThis.__ctLen = ct.byteLength;
    const pt = await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, pair.privateKey, ct);
    globalThis.__roundtrip = new TextDecoder().decode(pt);
})();
JS);
        $this->assertSame(256, $engine->eval('globalThis.__ctLen'));
        $this->assertSame('secret payload', $engine->eval('globalThis.__roundtrip'));
    }

    public function testRsaOaepEncryptDecryptSha256(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('RSA-OAEP requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const pair = await crypto.subtle.generateKey(
        { name: 'RSA-OAEP', modulusLength: 2048,
          publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' },
        true, ['encrypt', 'decrypt'],
    );
    const plain = new TextEncoder().encode('the OAEP SHA-256 payload');
    const ct = await crypto.subtle.encrypt({ name: 'RSA-OAEP', label: new Uint8Array([1,2,3]) }, pair.publicKey, plain);
    const pt = await crypto.subtle.decrypt({ name: 'RSA-OAEP', label: new Uint8Array([1,2,3]) }, pair.privateKey, ct);
    globalThis.__roundtrip = new TextDecoder().decode(pt);
})();
JS);
        $this->assertSame('the OAEP SHA-256 payload', $engine->eval('globalThis.__roundtrip'));
    }

    public function testRsaExportImportJwkRoundtrip(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('RSA requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const pair = await crypto.subtle.generateKey(
        { name: 'RSASSA-PKCS1-v1_5', modulusLength: 2048,
          publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' },
        true, ['sign', 'verify'],
    );
    const pubJwk = await crypto.subtle.exportKey('jwk', pair.publicKey);
    const privJwk = await crypto.subtle.exportKey('jwk', pair.privateKey);
    globalThis.__pubKty = pubJwk.kty;
    globalThis.__pubAlg = pubJwk.alg;
    globalThis.__privHasD = typeof privJwk.d === 'string';
    globalThis.__privHasP = typeof privJwk.p === 'string';
    globalThis.__privHasDp = typeof privJwk.dp === 'string';

    const pub2 = await crypto.subtle.importKey('jwk', pubJwk,
        { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, true, ['verify']);
    const priv2 = await crypto.subtle.importKey('jwk', privJwk,
        { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, true, ['sign']);
    const data = new TextEncoder().encode('jwk roundtrip');
    const sig = await crypto.subtle.sign('RSASSA-PKCS1-v1_5', priv2, data);
    globalThis.__rt = await crypto.subtle.verify('RSASSA-PKCS1-v1_5', pub2, sig, data);
})();
JS);
        $this->assertSame('RSA', $engine->eval('globalThis.__pubKty'));
        $this->assertSame('RS256', $engine->eval('globalThis.__pubAlg'));
        $this->assertTrue($engine->eval('globalThis.__privHasD'));
        $this->assertTrue($engine->eval('globalThis.__privHasP'));
        $this->assertTrue($engine->eval('globalThis.__privHasDp'));
        $this->assertTrue($engine->eval('globalThis.__rt'));
    }

    public function testRsaExportImportSpkiPkcs8Roundtrip(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('RSA requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const pair = await crypto.subtle.generateKey(
        { name: 'RSASSA-PKCS1-v1_5', modulusLength: 2048,
          publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' },
        true, ['sign', 'verify'],
    );
    const spki  = await crypto.subtle.exportKey('spki',  pair.publicKey);
    const pkcs8 = await crypto.subtle.exportKey('pkcs8', pair.privateKey);
    globalThis.__spkiBytes  = spki.byteLength;
    globalThis.__pkcs8Bytes = pkcs8.byteLength;

    const pub2 = await crypto.subtle.importKey('spki', spki,
        { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, true, ['verify']);
    const priv2 = await crypto.subtle.importKey('pkcs8', pkcs8,
        { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, true, ['sign']);
    const data = new TextEncoder().encode('binary roundtrip');
    const sig = await crypto.subtle.sign('RSASSA-PKCS1-v1_5', priv2, data);
    globalThis.__rt = await crypto.subtle.verify('RSASSA-PKCS1-v1_5', pub2, sig, data);
})();
JS);
        $this->assertGreaterThan(200, $engine->eval('globalThis.__spkiBytes'));
        $this->assertGreaterThan(1000, $engine->eval('globalThis.__pkcs8Bytes'));
        $this->assertTrue($engine->eval('globalThis.__rt'));
    }

    // ---------------------------------------------------------------
    // ECDSA
    // ---------------------------------------------------------------

    public function testEcdsaP256SignVerify(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('ECDSA requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const pair = await crypto.subtle.generateKey(
        { name: 'ECDSA', namedCurve: 'P-256' },
        true, ['sign', 'verify'],
    );
    const data = new TextEncoder().encode('ecdsa p-256');
    const sig = await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, pair.privateKey, data);
    globalThis.__sigLen = sig.byteLength;
    globalThis.__verify = await crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, pair.publicKey, sig, data);
    const tampered = new TextEncoder().encode('ecdsa p-257');
    globalThis.__verifyBad = await crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, pair.publicKey, sig, tampered);
})();
JS);
        $this->assertSame(64, $engine->eval('globalThis.__sigLen'));
        $this->assertTrue($engine->eval('globalThis.__verify'));
        $this->assertFalse($engine->eval('globalThis.__verifyBad'));
    }

    public function testEcdsaP384SignVerify(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('ECDSA requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const pair = await crypto.subtle.generateKey(
        { name: 'ECDSA', namedCurve: 'P-384' },
        true, ['sign', 'verify'],
    );
    const data = new TextEncoder().encode('ecdsa p-384');
    const sig = await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-384' }, pair.privateKey, data);
    globalThis.__sigLen = sig.byteLength;
    globalThis.__verify = await crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-384' }, pair.publicKey, sig, data);
})();
JS);
        $this->assertSame(96, $engine->eval('globalThis.__sigLen'));
        $this->assertTrue($engine->eval('globalThis.__verify'));
    }

    public function testEcdsaP521SignVerify(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('ECDSA requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const pair = await crypto.subtle.generateKey(
        { name: 'ECDSA', namedCurve: 'P-521' },
        true, ['sign', 'verify'],
    );
    const data = new TextEncoder().encode('ecdsa p-521');
    const sig = await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-512' }, pair.privateKey, data);
    globalThis.__sigLen = sig.byteLength;
    globalThis.__verify = await crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-512' }, pair.publicKey, sig, data);
})();
JS);
        $this->assertSame(132, $engine->eval('globalThis.__sigLen'));
        $this->assertTrue($engine->eval('globalThis.__verify'));
    }

    public function testEcdsaJwkRoundtrip(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('ECDSA requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const pair = await crypto.subtle.generateKey(
        { name: 'ECDSA', namedCurve: 'P-256' },
        true, ['sign', 'verify'],
    );
    const pubJwk  = await crypto.subtle.exportKey('jwk', pair.publicKey);
    const privJwk = await crypto.subtle.exportKey('jwk', pair.privateKey);
    globalThis.__crv = pubJwk.crv;
    globalThis.__kty = pubJwk.kty;
    globalThis.__hasD = typeof privJwk.d === 'string';

    const pub2 = await crypto.subtle.importKey('jwk', pubJwk,
        { name: 'ECDSA', namedCurve: 'P-256' }, true, ['verify']);
    const priv2 = await crypto.subtle.importKey('jwk', privJwk,
        { name: 'ECDSA', namedCurve: 'P-256' }, true, ['sign']);
    const data = new TextEncoder().encode('jwk ecdsa');
    const sig = await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, priv2, data);
    globalThis.__rt = await crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, pub2, sig, data);
})();
JS);
        $this->assertSame('EC', $engine->eval('globalThis.__kty'));
        $this->assertSame('P-256', $engine->eval('globalThis.__crv'));
        $this->assertTrue($engine->eval('globalThis.__hasD'));
        $this->assertTrue($engine->eval('globalThis.__rt'));
    }

    // ---------------------------------------------------------------
    // ECDH
    // ---------------------------------------------------------------

    public function testEcdhDeriveBitsMatchingPair(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('ECDH requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const alice = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits', 'deriveKey']);
    const bob   = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits', 'deriveKey']);
    const ab = new Uint8Array(await crypto.subtle.deriveBits(
        { name: 'ECDH', public: bob.publicKey }, alice.privateKey, 256));
    const ba = new Uint8Array(await crypto.subtle.deriveBits(
        { name: 'ECDH', public: alice.publicKey }, bob.privateKey, 256));
    globalThis.__abHex = Array.from(ab).map(b => b.toString(16).padStart(2, '0')).join('');
    globalThis.__baHex = Array.from(ba).map(b => b.toString(16).padStart(2, '0')).join('');
})();
JS);
        $ab = $engine->eval('globalThis.__abHex');
        $ba = $engine->eval('globalThis.__baHex');
        $this->assertSame(64, strlen($ab));
        $this->assertSame($ab, $ba);
    }

    public function testEcdhDeriveAesKey(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('ECDH requires ext-openssl');
        }
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const alice = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits', 'deriveKey']);
    const bob   = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits', 'deriveKey']);
    const k = await crypto.subtle.deriveKey(
        { name: 'ECDH', public: bob.publicKey },
        alice.privateKey,
        { name: 'AES-GCM', length: 256 },
        true, ['encrypt', 'decrypt'],
    );
    globalThis.__kType = k.type;
    globalThis.__kAlgName = k.algorithm.name;
    const raw = await crypto.subtle.exportKey('raw', k);
    globalThis.__kBytes = raw.byteLength;
})();
JS);
        $this->assertSame('secret', $engine->eval('globalThis.__kType'));
        $this->assertSame('AES-GCM', $engine->eval('globalThis.__kAlgName'));
        $this->assertSame(32, $engine->eval('globalThis.__kBytes'));
    }

    // ---------------------------------------------------------------
    // HKDF
    // ---------------------------------------------------------------

    public function testHkdfRfc5869TestCase1(): void
    {
        // RFC 5869 Appendix A.1: SHA-256, IKM = 0x0b * 22, salt = 0x00..0x0c,
        // info = 0xf0..0xf9, L = 42.
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const ikm  = new Uint8Array(22).fill(0x0b);
    const salt = new Uint8Array(13);
    for (let i = 0; i < 13; i++) salt[i] = i;
    const info = new Uint8Array(10);
    for (let i = 0; i < 10; i++) info[i] = 0xf0 | i;
    const key = await crypto.subtle.importKey('raw', ikm, { name: 'HKDF' }, false, ['deriveBits']);
    const out = new Uint8Array(await crypto.subtle.deriveBits(
        { name: 'HKDF', hash: 'SHA-256', salt, info }, key, 42 * 8));
    globalThis.__hex = Array.from(out).map(b => b.toString(16).padStart(2, '0')).join('');
})();
JS);
        $this->assertSame(
            '3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865',
            $engine->eval('globalThis.__hex'),
        );
    }

    public function testHkdfDeriveAesKey(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const ikm = new TextEncoder().encode('input keying material');
    const key = await crypto.subtle.importKey('raw', ikm, { name: 'HKDF' }, false, ['deriveKey']);
    const aes = await crypto.subtle.deriveKey(
        { name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array([1, 2, 3]), info: new Uint8Array([9, 9]) },
        key,
        { name: 'AES-GCM', length: 128 },
        true, ['encrypt', 'decrypt'],
    );
    globalThis.__aesLen = (await crypto.subtle.exportKey('raw', aes)).byteLength;
})();
JS);
        $this->assertSame(16, $engine->eval('globalThis.__aesLen'));
    }

    // ---------------------------------------------------------------
    // PBKDF2
    // ---------------------------------------------------------------

    public function testPbkdf2Rfc6070TestCase1(): void
    {
        // RFC 6070 PBKDF2-HMAC-SHA1 vector: password='password',
        // salt='salt', c=1, dkLen=20.
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const pw = await crypto.subtle.importKey(
        'raw', new TextEncoder().encode('password'),
        { name: 'PBKDF2' }, false, ['deriveBits']);
    const out = new Uint8Array(await crypto.subtle.deriveBits(
        { name: 'PBKDF2', hash: 'SHA-1', salt: new TextEncoder().encode('salt'), iterations: 1 },
        pw, 160));
    globalThis.__hex = Array.from(out).map(b => b.toString(16).padStart(2, '0')).join('');
})();
JS);
        $this->assertSame(
            '0c60c80f961f0e71f3a9b524af6012062fe037a6',
            $engine->eval('globalThis.__hex'),
        );
    }

    public function testPbkdf2DeriveAesKey(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
(async () => {
    const pw = await crypto.subtle.importKey(
        'raw', new TextEncoder().encode('correct horse battery staple'),
        { name: 'PBKDF2' }, false, ['deriveKey']);
    const k = await crypto.subtle.deriveKey(
        { name: 'PBKDF2', hash: 'SHA-256', salt: new TextEncoder().encode('NaCl salt'), iterations: 2000 },
        pw,
        { name: 'AES-CBC', length: 256 },
        true, ['encrypt', 'decrypt'],
    );
    const raw = await crypto.subtle.exportKey('raw', k);
    globalThis.__bytes = raw.byteLength;
    globalThis.__type = k.type;
})();
JS);
        $this->assertSame('secret', $engine->eval('globalThis.__type'));
        $this->assertSame(32, $engine->eval('globalThis.__bytes'));
    }
}
