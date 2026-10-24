<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class Ed25519CryptoTest extends TestCase
{
    public function testCryptoKeyUsesTheRealmConstructor(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
var keyShape;
crypto.subtle.importKey('raw', new Uint8Array([1, 2]), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']).then(key => {
  keyShape = [key instanceof CryptoKey, Object.getPrototypeOf(key) === CryptoKey.prototype, Object.prototype.toString.call(key)];
});
var illegal;
try { new CryptoKey(); } catch (error) { illegal = error instanceof TypeError; }
JS);
        self::assertSame('[true,true,"[object CryptoKey]"]', $engine->eval('JSON.stringify(keyShape)'));
        self::assertTrue($engine->eval('illegal'));
    }

    public function testEd25519MatchesRfc8032AndRoundTripsEveryKeyFormat(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
var edResult, edFailure;
const fromHex = text => Uint8Array.from(text.match(/../g), byte => parseInt(byte, 16));
const hex = value => Array.from(new Uint8Array(value), byte => byte.toString(16).padStart(2, '0')).join('');
(async () => {
  const seed = '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60';
  const publicHex = 'd75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a';
  const key = await crypto.subtle.importKey('pkcs8', fromHex('302e020100300506032b657004220420' + seed), 'Ed25519', true, ['sign']);
  const publicKey = await crypto.subtle.importKey('raw', fromHex(publicHex), 'Ed25519', true, ['verify']);
  const signature = await crypto.subtle.sign('Ed25519', key, new Uint8Array());
  const jwk = await crypto.subtle.exportKey('jwk', key);
  const importedJwk = await crypto.subtle.importKey('jwk', jwk, 'Ed25519', true, ['sign']);
  const spki = await crypto.subtle.exportKey('spki', publicKey);
  const importedSpki = await crypto.subtle.importKey('spki', spki, 'Ed25519', true, ['verify']);
  const pair = await crypto.subtle.generateKey('Ed25519', false, ['sign', 'verify']);
  const generatedSignature = await crypto.subtle.sign('Ed25519', pair.privateKey, fromHex('1234'));
  edResult = {
    signature: hex(signature),
    public: hex(await crypto.subtle.exportKey('raw', importedSpki)),
    seed: hex(await crypto.subtle.exportKey('pkcs8', importedJwk)).slice(-64),
    verify: await crypto.subtle.verify('Ed25519', publicKey, signature, new Uint8Array()),
    tamper: await crypto.subtle.verify('Ed25519', publicKey, signature, new Uint8Array([1])),
    short: await crypto.subtle.verify('Ed25519', publicKey, new Uint8Array([1]), new Uint8Array()),
    generated: await crypto.subtle.verify('Ed25519', pair.publicKey, generatedSignature, fromHex('1234')),
    properties: [key.type, key.algorithm.name, key instanceof CryptoKey, pair.publicKey.extractable, pair.privateKey.extractable],
  };
})().catch(error => { edFailure = error.name + ': ' + error.message; });
JS);
        if (!extension_loaded('sodium')) {
            self::assertSame('NotSupportedError: Ed25519 requires the PHP sodium extension.', $engine->eval('edFailure'));
            return;
        }
        self::assertSame('undefined', $engine->eval('typeof edFailure'));
        $actual = json_decode((string) $engine->eval('JSON.stringify(edResult)'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            'e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555'
            . 'fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b',
            $actual['signature'],
        );
        self::assertSame('d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a', $actual['public']);
        self::assertSame('9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60', $actual['seed']);
        self::assertTrue($actual['verify']);
        self::assertFalse($actual['tamper']);
        self::assertFalse($actual['short']);
        self::assertTrue($actual['generated']);
        self::assertSame(['private', 'Ed25519', true, true, false], $actual['properties']);
    }
}
