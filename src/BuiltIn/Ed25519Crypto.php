<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Engine;
use Phasis\Exceptions\JsThrowable;
use Phasis\Exceptions\TypeError;
use Phasis\Interop\PhpToJs;
use Phasis\Value\JsObject;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/** Ed25519 key material and RFC 8410 encodings backed by libsodium. */
final class Ed25519Crypto
{
    private const SPKI_PREFIX = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";
    private const PKCS8_PREFIX = "\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20";

    /** @return array{string, string} Public bytes and private seed. */
    public static function generate(): array
    {
        self::available();
        $seed = random_bytes(32);
        return [self::publicKey($seed), $seed];
    }

    /** @param list<string> $usages */
    public static function validateUsages(array $usages, string $type, bool $generated = false): void
    {
        $allowed = $generated ? ['sign', 'verify'] : ($type === 'private' ? ['sign'] : ['verify']);
        foreach ($usages as $usage) {
            if (!in_array($usage, $allowed, true)) {
                self::error('SyntaxError', 'Unsupported key usage for an Ed25519 key.');
            }
        }
        if (($type === 'private' || $generated) && !in_array('sign', $usages, true)) {
            self::error('SyntaxError', 'Usages cannot be empty when creating a private key.');
        }
    }

    /**
     * @param list<string> $usages
     * @return array{type: 'public'|'private', material: string}
     */
    public static function import(string $format, string|JsObject $data, bool $extractable, array $usages): array
    {
        self::available();
        $type = 'public';
        if ($format === 'jwk' && $data instanceof JsObject) {
            if ($data->get('kty')->toJsString() !== 'OKP' || $data->get('crv')->toJsString() !== 'Ed25519') {
                self::error('DataError', 'Invalid Ed25519 JWK key type or curve.');
            }
            $public = self::decode($data->get('x')->toJsString());
            $material = $public;
            if (!$data->get('d') instanceof JsUndefined) {
                $material = self::decode($data->get('d')->toJsString());
                $type = 'private';
                if (strlen($material) !== 32 || strlen($public) !== 32 || !hash_equals(self::publicKey($material), $public)) {
                    self::error('DataError', 'Invalid Ed25519 private key material.');
                }
            }
            if ($extractable && !$data->get('ext') instanceof JsUndefined && !$data->get('ext')->toBoolean()) {
                self::error('DataError', 'JWK ext does not permit extraction.');
            }
            if (!$data->get('use') instanceof JsUndefined && $usages !== [] && $data->get('use')->toJsString() !== 'sig') {
                self::error('DataError', 'Invalid JWK use parameter.');
            }
            if (!$data->get('alg') instanceof JsUndefined && $data->get('alg')->toJsString() !== 'EdDSA') {
                self::error('DataError', 'Invalid JWK alg parameter.');
            }
            $operations = $data->get('key_ops');
            if (!$operations instanceof JsUndefined) {
                if (!$operations instanceof JsObject) {
                    self::error('DataError', 'JWK key_ops must be a sequence.');
                }
                $allowed = [];
                for ($i = 0, $length = (int) $operations->get('length')->toNumber(); $i < $length; $i++) {
                    $allowed[] = $operations->get((string) $i)->toJsString();
                }
                if (count(array_unique($allowed)) !== count($allowed) || array_diff($usages, $allowed) !== []) {
                    self::error('DataError', 'JWK key_ops does not permit the requested usages.');
                }
            }
        } elseif (is_string($data)) {
            $material = $data;
            if ($format === 'spki') {
                if (strlen($data) !== strlen(self::SPKI_PREFIX) + 32 || !str_starts_with($data, self::SPKI_PREFIX)) {
                    self::error('DataError', 'Invalid Ed25519 SubjectPublicKeyInfo.');
                }
                $material = substr($data, strlen(self::SPKI_PREFIX));
            } elseif ($format === 'pkcs8') {
                if (strlen($data) !== strlen(self::PKCS8_PREFIX) + 32 || !str_starts_with($data, self::PKCS8_PREFIX)) {
                    self::error('DataError', 'Invalid Ed25519 PrivateKeyInfo.');
                }
                $material = substr($data, strlen(self::PKCS8_PREFIX));
                $type = 'private';
            } elseif ($format !== 'raw') {
                self::error('NotSupportedError', 'Unsupported Ed25519 key format.');
            }
        } else {
            throw new TypeError('Invalid Ed25519 key data.');
        }
        if (strlen($material) !== 32) {
            self::error('DataError', 'Ed25519 keys must contain exactly 32 bytes.');
        }
        self::validateUsages($usages, $type);
        return ['type' => $type, 'material' => $material];
    }

    /** @param list<string> $usages */
    public static function export(string $format, string $type, string $material, array $usages): string|JsValue
    {
        if ($format === 'raw' && $type === 'public') {
            return $material;
        }
        if ($format === 'spki' && $type === 'public') {
            return self::SPKI_PREFIX . $material;
        }
        if ($format === 'pkcs8' && $type === 'private') {
            return self::PKCS8_PREFIX . $material;
        }
        if ($format === 'jwk') {
            $jwk = [
                'key_ops' => $usages,
                'ext' => true,
                'crv' => 'Ed25519',
                'x' => self::encode($type === 'private' ? self::publicKey($material) : $material),
            ];
            if ($type === 'private') {
                $jwk['d'] = self::encode($material);
            }
            $jwk['kty'] = 'OKP';
            $jwk['alg'] = 'EdDSA';
            return PhpToJs::convert($jwk);
        }
        self::error('InvalidAccessError', 'The requested format is not valid for this Ed25519 key type.');
    }

    /** Sign a message with a 32-byte Ed25519 private seed. */
    public static function sign(string $seed, string $message): string
    {
        self::available();
        return sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed)));
    }

    /** Verify a detached Ed25519 signature. */
    public static function verify(string $public, string $signature, string $message): bool
    {
        self::available();
        return strlen($signature) === 64 && sodium_crypto_sign_verify_detached($signature, $message, $public);
    }

    /** Raise a WebCrypto DOMException without converting its rejection type. */
    public static function error(string $name, string $message): never
    {
        $environment = Engine::getCurrentRealm()?->getGlobalEnv();
        if ($environment === null) {
            throw new TypeError($message);
        }
        throw new JsThrowable(DomExceptionConstructor::create($environment, $message, $name));
    }

    private static function publicKey(string $seed): string
    {
        return sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*={0,2}$/D', $value) !== 1) {
            self::error('DataError', 'Invalid base64url key material.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            self::error('DataError', 'Invalid base64url key material.');
        }
        return $decoded;
    }

    private static function available(): void
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) {
            self::error('NotSupportedError', 'Ed25519 requires the PHP sodium extension.');
        }
    }
}
