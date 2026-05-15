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
    }
}
