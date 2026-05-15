<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WebCrypto `crypto.subtle` — the SubtleCrypto interface.
 *
 * Implemented operations:
 *   - `digest(algorithm, data)` — SHA-1/256/384/512 via PHP `hash()`.
 *   - `sign(algorithm, key, data)` / `verify(algorithm, key, sig, data)` — HMAC.
 *   - `encrypt(algorithm, key, data)` / `decrypt(algorithm, key, data)` —
 *     AES-CBC, AES-CTR, AES-GCM via PHP `openssl_*` family.
 *   - `generateKey(algorithm, extractable, usages)` — HMAC + AES.
 *   - `importKey(format, keyData, algorithm, extractable, usages)` — raw + JWK.
 *   - `exportKey(format, key)` — raw + JWK.
 *
 * Not yet shipped: RSA, ECDSA, ECDH, deriveKey, deriveBits, wrapKey, unwrapKey.
 *
 * A CryptoKey is represented as a `JsObject` carrying internal slots:
 *   `[[KeyType]]`      — 'secret' | 'public' | 'private'
 *   `[[KeyAlgorithm]]` — normalized algorithm object (name + params)
 *   `[[KeyExtractable]]` — bool
 *   `[[KeyUsages]]`    — frozen array of strings
 *   `[[KeyMaterial]]`  — raw PHP string holding the key bytes
 *
 * Returned ArrayBuffers wrap fresh bytes (no aliasing with caller buffers).
 *
 * Requires `ext-openssl` for AES; `digest` and HMAC work without it via
 * `hash()` and `hash_hmac()`.
 */
final class SubtleCryptoObject
{
    public static function create(): JsObject
    {
        $subtle = new JsObject();

        self::defineMethod($subtle, 'digest', 2, self::digestImpl());
        self::defineMethod($subtle, 'sign', 3, self::signImpl());
        self::defineMethod($subtle, 'verify', 4, self::verifyImpl());
        self::defineMethod($subtle, 'encrypt', 3, self::encryptImpl());
        self::defineMethod($subtle, 'decrypt', 3, self::decryptImpl());
        self::defineMethod($subtle, 'generateKey', 3, self::generateKeyImpl());
        self::defineMethod($subtle, 'importKey', 5, self::importKeyImpl());
        self::defineMethod($subtle, 'exportKey', 2, self::exportKeyImpl());

        return $subtle;
    }

    private static function defineMethod(JsObject $obj, string $name, int $length, \Closure $impl): void
    {
        $obj->defineOwnProperty(
            $name,
            PropertyDescriptor::data(
                JsFunction::fromCallable($name, $impl, $length),
                true,
                false,
                true,
            ),
        );
    }

    // ---------------------------------------------------------------
    // digest
    // ---------------------------------------------------------------

    private static function digestImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($this_);
            try {
                $algName = self::normalizeAlgorithmName($args[0] ?? JsUndefined::instance());
                $data = self::bufferSourceBytes($args[1] ?? JsUndefined::instance());
                $php = self::digestPhpAlgo($algName);
                if ($php === null) {
                    throw new TypeError("Unrecognized digest algorithm: {$algName}");
                }
                $hash = hash($php, $data, true);
                return JsPromise::resolved(self::bufferFromBytes($hash));
            } catch (\Throwable $e) {
                return self::rejectFromThrow($e);
            }
        };
    }

    private static function digestPhpAlgo(string $name): ?string
    {
        return match (strtoupper($name)) {
            'SHA-1' => 'sha1',
            'SHA-256' => 'sha256',
            'SHA-384' => 'sha384',
            'SHA-512' => 'sha512',
            default => null,
        };
    }

    // ---------------------------------------------------------------
    // sign / verify (HMAC)
    // ---------------------------------------------------------------

    private static function signImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($this_);
            try {
                $alg = $args[0] ?? JsUndefined::instance();
                $key = $args[1] ?? JsUndefined::instance();
                $data = self::bufferSourceBytes($args[2] ?? JsUndefined::instance());
                $algName = self::normalizeAlgorithmName($alg);
                if (strtoupper($algName) === 'HMAC') {
                    $cryptoKey = self::requireCryptoKey($key);
                    self::assertUsage($cryptoKey, 'sign');
                    $hash = self::cryptoKeyHmacHash($cryptoKey);
                    $material = self::cryptoKeyMaterial($cryptoKey);
                    $mac = hash_hmac($hash, $data, $material, true);
                    return JsPromise::resolved(self::bufferFromBytes($mac));
                }
                throw new TypeError("Unsupported sign algorithm: {$algName}");
            } catch (\Throwable $e) {
                return self::rejectFromThrow($e);
            }
        };
    }

    private static function verifyImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($this_);
            try {
                $alg = $args[0] ?? JsUndefined::instance();
                $key = $args[1] ?? JsUndefined::instance();
                $signature = self::bufferSourceBytes($args[2] ?? JsUndefined::instance());
                $data = self::bufferSourceBytes($args[3] ?? JsUndefined::instance());
                $algName = self::normalizeAlgorithmName($alg);
                if (strtoupper($algName) === 'HMAC') {
                    $cryptoKey = self::requireCryptoKey($key);
                    self::assertUsage($cryptoKey, 'verify');
                    $hash = self::cryptoKeyHmacHash($cryptoKey);
                    $material = self::cryptoKeyMaterial($cryptoKey);
                    $expected = hash_hmac($hash, $data, $material, true);
                    return JsPromise::resolved(JsBoolean::of(hash_equals($expected, $signature)));
                }
                throw new TypeError("Unsupported verify algorithm: {$algName}");
            } catch (\Throwable $e) {
                return self::rejectFromThrow($e);
            }
        };
    }

    // ---------------------------------------------------------------
    // encrypt / decrypt (AES-CBC / AES-CTR / AES-GCM)
    // ---------------------------------------------------------------

    private static function encryptImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($this_);
            try {
                $alg = self::requireObject($args[0] ?? JsUndefined::instance(), 'encrypt');
                $cryptoKey = self::requireCryptoKey($args[1] ?? JsUndefined::instance());
                self::assertUsage($cryptoKey, 'encrypt');
                $plain = self::bufferSourceBytes($args[2] ?? JsUndefined::instance());
                $algName = strtoupper(self::stringPropOrThrow($alg, 'name', 'encrypt'));
                $key = self::cryptoKeyMaterial($cryptoKey);
                $cipher = self::aesCipherName($algName, strlen($key));
                if ($cipher === null) {
                    throw new TypeError("Unsupported encrypt algorithm: {$algName}");
                }

                if ($algName === 'AES-GCM') {
                    $iv = self::bufferSourceBytes($alg->get('iv'));
                    if ($iv === '') {
                        throw new TypeError('AES-GCM requires a non-empty iv');
                    }
                    $additional = $alg->has('additionalData')
                        ? self::bufferSourceBytes($alg->get('additionalData'))
                        : '';
                    $tagLengthBits = $alg->has('tagLength')
                        ? (int) TypeConversion::toNumber($alg->get('tagLength'))
                        : 128;
                    $tagLength = (int) ($tagLengthBits / 8);
                    $tag = '';
                    $ct = openssl_encrypt(
                        $plain,
                        $cipher,
                        $key,
                        OPENSSL_RAW_DATA,
                        $iv,
                        $tag,
                        $additional,
                        $tagLength,
                    );
                    if ($ct === false) {
                        throw new \RuntimeException('openssl_encrypt failed');
                    }
                    return JsPromise::resolved(self::bufferFromBytes($ct . $tag));
                }

                $iv = self::bufferSourceBytes($alg->get($algName === 'AES-CTR' ? 'counter' : 'iv'));
                if ($iv === '') {
                    throw new TypeError("{$algName} requires an iv / counter");
                }
                $ct = openssl_encrypt($plain, $cipher, $key, OPENSSL_RAW_DATA, $iv);
                if ($ct === false) {
                    throw new \RuntimeException('openssl_encrypt failed');
                }
                return JsPromise::resolved(self::bufferFromBytes($ct));
            } catch (\Throwable $e) {
                return self::rejectFromThrow($e);
            }
        };
    }

    private static function decryptImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($this_);
            try {
                $alg = self::requireObject($args[0] ?? JsUndefined::instance(), 'decrypt');
                $cryptoKey = self::requireCryptoKey($args[1] ?? JsUndefined::instance());
                self::assertUsage($cryptoKey, 'decrypt');
                $cipherText = self::bufferSourceBytes($args[2] ?? JsUndefined::instance());
                $algName = strtoupper(self::stringPropOrThrow($alg, 'name', 'decrypt'));
                $key = self::cryptoKeyMaterial($cryptoKey);
                $cipher = self::aesCipherName($algName, strlen($key));
                if ($cipher === null) {
                    throw new TypeError("Unsupported decrypt algorithm: {$algName}");
                }

                if ($algName === 'AES-GCM') {
                    $iv = self::bufferSourceBytes($alg->get('iv'));
                    $additional = $alg->has('additionalData')
                        ? self::bufferSourceBytes($alg->get('additionalData'))
                        : '';
                    $tagLengthBits = $alg->has('tagLength')
                        ? (int) TypeConversion::toNumber($alg->get('tagLength'))
                        : 128;
                    $tagLength = (int) ($tagLengthBits / 8);
                    if (strlen($cipherText) < $tagLength) {
                        throw new \RuntimeException('AES-GCM ciphertext shorter than tag');
                    }
                    $ct = substr($cipherText, 0, -$tagLength);
                    $tag = substr($cipherText, -$tagLength);
                    $pt = openssl_decrypt(
                        $ct,
                        $cipher,
                        $key,
                        OPENSSL_RAW_DATA,
                        $iv,
                        $tag,
                        $additional,
                    );
                    if ($pt === false) {
                        throw new \RuntimeException('openssl_decrypt failed (auth tag mismatch?)');
                    }
                    return JsPromise::resolved(self::bufferFromBytes($pt));
                }

                $iv = self::bufferSourceBytes($alg->get($algName === 'AES-CTR' ? 'counter' : 'iv'));
                $pt = openssl_decrypt($cipherText, $cipher, $key, OPENSSL_RAW_DATA, $iv);
                if ($pt === false) {
                    throw new \RuntimeException('openssl_decrypt failed');
                }
                return JsPromise::resolved(self::bufferFromBytes($pt));
            } catch (\Throwable $e) {
                return self::rejectFromThrow($e);
            }
        };
    }

    private static function aesCipherName(string $algName, int $keyLen): ?string
    {
        $bits = $keyLen * 8;
        return match ($algName) {
            'AES-CBC' => match ($bits) { 128 => 'aes-128-cbc', 192 => 'aes-192-cbc', 256 => 'aes-256-cbc', default => null },
            'AES-CTR' => match ($bits) { 128 => 'aes-128-ctr', 192 => 'aes-192-ctr', 256 => 'aes-256-ctr', default => null },
            'AES-GCM' => match ($bits) { 128 => 'aes-128-gcm', 192 => 'aes-192-gcm', 256 => 'aes-256-gcm', default => null },
            default => null,
        };
    }

    // ---------------------------------------------------------------
    // generateKey / importKey / exportKey
    // ---------------------------------------------------------------

    private static function generateKeyImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($this_);
            try {
                $alg = self::requireObject($args[0] ?? JsUndefined::instance(), 'generateKey');
                $extractable = TypeConversion::toBoolean($args[1] ?? JsUndefined::instance());
                $usages = self::collectUsages($args[2] ?? JsUndefined::instance());
                $algName = strtoupper(self::stringPropOrThrow($alg, 'name', 'generateKey'));

                if ($algName === 'HMAC') {
                    // Per spec, hash can be a string ('SHA-256') or an
                    // object ({ name: 'SHA-256' }).
                    $hash = strtoupper(self::stringFromAlgRef($alg->get('hash')));
                    $defaultLen = match ($hash) {
                        'SHA-1' => 64,
                        'SHA-256' => 64,
                        'SHA-384' => 128,
                        'SHA-512' => 128,
                        default => null,
                    };
                    if ($defaultLen === null) {
                        throw new TypeError("Unsupported HMAC hash: {$hash}");
                    }
                    $length = $alg->has('length')
                        ? (int) (TypeConversion::toNumber($alg->get('length')) / 8)
                        : $defaultLen;
                    $material = random_bytes($length);
                    return JsPromise::resolved(self::makeCryptoKey(
                        'secret',
                        ['name' => 'HMAC', 'hash' => ['name' => $hash], 'length' => $length * 8],
                        $extractable,
                        $usages,
                        $material,
                    ));
                }

                if (in_array($algName, ['AES-CBC', 'AES-CTR', 'AES-GCM'], true)) {
                    $bits = (int) TypeConversion::toNumber($alg->get('length'));
                    if (!in_array($bits, [128, 192, 256], true)) {
                        throw new TypeError(
                            "AES key length must be 128, 192, or 256 (got {$bits})",
                        );
                    }
                    $material = random_bytes($bits / 8);
                    return JsPromise::resolved(self::makeCryptoKey(
                        'secret',
                        ['name' => $algName, 'length' => $bits],
                        $extractable,
                        $usages,
                        $material,
                    ));
                }

                throw new TypeError("Unsupported generateKey algorithm: {$algName}");
            } catch (\Throwable $e) {
                return self::rejectFromThrow($e);
            }
        };
    }

    private static function importKeyImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($this_);
            try {
                $format = self::requireString($args[0] ?? JsUndefined::instance(), 'importKey');
                $keyData = $args[1] ?? JsUndefined::instance();
                $algRaw = $args[2] ?? JsUndefined::instance();
                $extractable = TypeConversion::toBoolean($args[3] ?? JsUndefined::instance());
                $usages = self::collectUsages($args[4] ?? JsUndefined::instance());
                $algName = self::normalizeAlgorithmName($algRaw);

                if ($format === 'raw') {
                    $bytes = self::bufferSourceBytes($keyData);
                    if (strtoupper($algName) === 'HMAC') {
                        $hashAlg = $algRaw instanceof JsObject ? $algRaw->get('hash') : JsUndefined::instance();
                        $hash = strtoupper(self::stringFromAlgRef($hashAlg));
                        return JsPromise::resolved(self::makeCryptoKey(
                            'secret',
                            ['name' => 'HMAC', 'hash' => ['name' => $hash], 'length' => strlen($bytes) * 8],
                            $extractable,
                            $usages,
                            $bytes,
                        ));
                    }
                    if (in_array(strtoupper($algName), ['AES-CBC', 'AES-CTR', 'AES-GCM'], true)) {
                        $bits = strlen($bytes) * 8;
                        return JsPromise::resolved(self::makeCryptoKey(
                            'secret',
                            ['name' => strtoupper($algName), 'length' => $bits],
                            $extractable,
                            $usages,
                            $bytes,
                        ));
                    }
                }
                if ($format === 'jwk') {
                    if (!$keyData instanceof JsObject) {
                        throw new TypeError('jwk import expects an object key');
                    }
                    $kty = self::stringPropOrThrow($keyData, 'kty', 'jwk');
                    if ($kty !== 'oct') {
                        throw new TypeError("jwk kty '{$kty}' not supported");
                    }
                    $k = self::stringPropOrThrow($keyData, 'k', 'jwk');
                    $bytes = self::base64UrlDecode($k);
                    if (strtoupper($algName) === 'HMAC') {
                        $hashAlg = $algRaw instanceof JsObject ? $algRaw->get('hash') : JsUndefined::instance();
                        $hash = strtoupper(self::stringFromAlgRef($hashAlg));
                        return JsPromise::resolved(self::makeCryptoKey(
                            'secret',
                            ['name' => 'HMAC', 'hash' => ['name' => $hash], 'length' => strlen($bytes) * 8],
                            $extractable,
                            $usages,
                            $bytes,
                        ));
                    }
                    if (in_array(strtoupper($algName), ['AES-CBC', 'AES-CTR', 'AES-GCM'], true)) {
                        $bits = strlen($bytes) * 8;
                        return JsPromise::resolved(self::makeCryptoKey(
                            'secret',
                            ['name' => strtoupper($algName), 'length' => $bits],
                            $extractable,
                            $usages,
                            $bytes,
                        ));
                    }
                }
                throw new TypeError("Unsupported importKey format '{$format}' / algorithm '{$algName}'");
            } catch (\Throwable $e) {
                return self::rejectFromThrow($e);
            }
        };
    }

    private static function exportKeyImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($this_);
            try {
                $format = self::requireString($args[0] ?? JsUndefined::instance(), 'exportKey');
                $key = self::requireCryptoKey($args[1] ?? JsUndefined::instance());
                if ($key->getInternalProperty('[[KeyExtractable]]') !== true) {
                    throw new TypeError('Key is not extractable');
                }
                $material = self::cryptoKeyMaterial($key);
                if ($format === 'raw') {
                    return JsPromise::resolved(self::bufferFromBytes($material));
                }
                if ($format === 'jwk') {
                    $alg = $key->getInternalProperty('[[KeyAlgorithm]]');
                    $jwk = new JsObject();
                    $jwk->defineOwnProperty('kty', PropertyDescriptor::data(new JsString('oct'), true, true, true));
                    $jwk->defineOwnProperty('k', PropertyDescriptor::data(
                        new JsString(self::base64UrlEncode($material)),
                        true,
                        true,
                        true,
                    ));
                    if (is_array($alg) && isset($alg['name'])) {
                        $jwkAlg = self::jwkAlgString(
                            $alg['name'],
                            $alg['hash']['name'] ?? null,
                            $alg['length'] ?? null,
                        );
                        if ($jwkAlg !== null) {
                            $jwk->defineOwnProperty('alg', PropertyDescriptor::data(
                                new JsString($jwkAlg),
                                true,
                                true,
                                true,
                            ));
                        }
                    }
                    $jwk->defineOwnProperty('ext', PropertyDescriptor::data(
                        JsBoolean::of(true),
                        true,
                        true,
                        true,
                    ));
                    return JsPromise::resolved($jwk);
                }
                throw new TypeError("Unsupported exportKey format '{$format}'");
            } catch (\Throwable $e) {
                return self::rejectFromThrow($e);
            }
        };
    }

    private static function jwkAlgString(string $name, ?string $hash, ?int $length): ?string
    {
        return match ($name) {
            'HMAC' => match ($hash) {
                'SHA-1' => 'HS1',
                'SHA-256' => 'HS256',
                'SHA-384' => 'HS384',
                'SHA-512' => 'HS512',
                default => null,
            },
            'AES-CBC' => match ($length) {
                128 => 'A128CBC',
                192 => 'A192CBC',
                256 => 'A256CBC',
                default => null,
            },
            'AES-GCM' => match ($length) {
                128 => 'A128GCM',
                192 => 'A192GCM',
                256 => 'A256GCM',
                default => null,
            },
            default => null,
        };
    }

    // ---------------------------------------------------------------
    // CryptoKey helpers
    // ---------------------------------------------------------------

    /**
     * @param 'secret'|'public'|'private' $type
     * @param array<string, mixed>        $algorithm
     * @param list<string>                $usages
     */
    private static function makeCryptoKey(
        string $type,
        array $algorithm,
        bool $extractable,
        array $usages,
        string $material,
    ): JsObject {
        $key = new JsObject();
        $key->setInternalProperty('[[IsCryptoKey]]', true);
        $key->setInternalProperty('[[KeyType]]', $type);
        $key->setInternalProperty('[[KeyAlgorithm]]', $algorithm);
        $key->setInternalProperty('[[KeyExtractable]]', $extractable);
        $key->setInternalProperty('[[KeyUsages]]', $usages);
        $key->setInternalProperty('[[KeyMaterial]]', $material);

        // Public-facing accessor properties: type, extractable, algorithm, usages.
        $key->defineOwnProperty('type', PropertyDescriptor::data(
            new JsString($type),
            false,
            true,
            true,
        ));
        $key->defineOwnProperty('extractable', PropertyDescriptor::data(
            JsBoolean::of($extractable),
            false,
            true,
            true,
        ));
        $algObj = new JsObject();
        $algObj->defineOwnProperty('name', PropertyDescriptor::data(
            new JsString((string) $algorithm['name']),
            true,
            true,
            true,
        ));
        if (isset($algorithm['hash']['name'])) {
            $hashObj = new JsObject();
            $hashObj->defineOwnProperty('name', PropertyDescriptor::data(
                new JsString((string) $algorithm['hash']['name']),
                true,
                true,
                true,
            ));
            $algObj->defineOwnProperty('hash', PropertyDescriptor::data($hashObj, true, true, true));
        }
        if (isset($algorithm['length'])) {
            $algObj->defineOwnProperty('length', PropertyDescriptor::data(
                JsNumber::of((float) $algorithm['length']),
                true,
                true,
                true,
            ));
        }
        $key->defineOwnProperty('algorithm', PropertyDescriptor::data($algObj, false, true, true));
        // Build a frozen Array of usages. Use defineOwnProperty for the
        // indexed slots so we don't depend on a JsArray helper.
        $usagesArr = new \Phasis\Value\JsArray();
        $i = 0;
        foreach ($usages as $u) {
            $usagesArr->defineOwnProperty((string) $i, PropertyDescriptor::data(
                new JsString($u),
                true,
                true,
                true,
            ));
            $i++;
        }
        $usagesArr->setLength($i);
        $key->defineOwnProperty('usages', PropertyDescriptor::data($usagesArr, false, true, true));

        return $key;
    }

    private static function requireCryptoKey(JsValue $val): JsObject
    {
        if (!$val instanceof JsObject || $val->getInternalProperty('[[IsCryptoKey]]') !== true) {
            throw new TypeError('Expected CryptoKey');
        }
        return $val;
    }

    private static function cryptoKeyMaterial(JsObject $key): string
    {
        $m = $key->getInternalProperty('[[KeyMaterial]]');
        return is_string($m) ? $m : '';
    }

    private static function cryptoKeyHmacHash(JsObject $key): string
    {
        $alg = $key->getInternalProperty('[[KeyAlgorithm]]');
        $hash = is_array($alg) ? ($alg['hash']['name'] ?? null) : null;
        return match ((string) $hash) {
            'SHA-1' => 'sha1',
            'SHA-256' => 'sha256',
            'SHA-384' => 'sha384',
            'SHA-512' => 'sha512',
            default => 'sha256',
        };
    }

    private static function assertUsage(JsObject $key, string $usage): void
    {
        $usages = $key->getInternalProperty('[[KeyUsages]]');
        if (!is_array($usages) || !in_array($usage, $usages, true)) {
            throw new TypeError("Key does not allow '{$usage}'");
        }
    }

    /** @return list<string> */
    private static function collectUsages(JsValue $val): array
    {
        $out = [];
        if ($val instanceof JsObject) {
            $len = $val->get('length');
            if (!$len instanceof JsUndefined) {
                $n = (int) TypeConversion::toNumber($len);
                for ($i = 0; $i < $n; $i++) {
                    $el = $val->get((string) $i);
                    $s = TypeConversion::toString($el);
                    if ($s !== '') {
                        $out[] = $s;
                    }
                }
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------
    // Generic helpers
    // ---------------------------------------------------------------

    private static function normalizeAlgorithmName(JsValue $val): string
    {
        if ($val instanceof JsString) {
            return $val->value;
        }
        if ($val instanceof JsObject) {
            return self::stringPropOrThrow($val, 'name', 'algorithm');
        }
        throw new TypeError('Algorithm must be a string or { name: ... } object');
    }

    private static function stringFromAlgRef(JsValue $val): string
    {
        if ($val instanceof JsString) {
            return $val->value;
        }
        if ($val instanceof JsObject) {
            return self::stringPropOrThrow($val, 'name', 'algorithm');
        }
        throw new TypeError('Hash algorithm must be a string or { name: ... } object');
    }

    private static function requireObject(JsValue $val, string $op): JsObject
    {
        if (!$val instanceof JsObject) {
            throw new TypeError("{$op} requires an algorithm object");
        }
        return $val;
    }

    private static function requireString(JsValue $val, string $op): string
    {
        if (!$val instanceof JsString) {
            throw new TypeError("{$op} expects a string");
        }
        return $val->value;
    }

    private static function stringPropOrThrow(JsObject $obj, string $key, string $context): string
    {
        $val = $obj->get($key);
        if (!$val instanceof JsString) {
            throw new TypeError("{$context}: '{$key}' must be a string");
        }
        return $val->value;
    }

    private static function bufferSourceBytes(JsValue $val): string
    {
        if ($val instanceof JsString) {
            // UTF-8 encode — common pattern in MDN examples.
            return $val->value;
        }
        if ($val instanceof JsArrayBuffer) {
            return $val->readBytes(0, $val->getByteLength());
        }
        if ($val instanceof JsTypedArray) {
            $buf = $val->getBuffer();
            return $buf->readBytes(
                $val->getByteOffset(),
                $val->getLength() * $val->getBytesPerElement(),
            );
        }
        if ($val instanceof JsObject && $val->get('buffer') instanceof JsArrayBuffer) {
            // DataView-ish wrapper.
            $buf = $val->get('buffer');
            $offset = (int) TypeConversion::toNumber($val->get('byteOffset'));
            $length = (int) TypeConversion::toNumber($val->get('byteLength'));
            return $buf->readBytes($offset, $length);
        }
        throw new TypeError('Expected BufferSource (ArrayBuffer / TypedArray / DataView / string)');
    }

    private static function bufferFromBytes(string $bytes): JsArrayBuffer
    {
        $proto = JsArrayBuffer::getDefaultPrototype();
        $buf = new JsArrayBuffer(strlen($bytes), $proto);
        if ($bytes !== '') {
            $buf->writeBytes(0, $bytes);
        }
        return $buf;
    }

    private static function rejectFromThrow(\Throwable $e): JsValue
    {
        $err = new JsObject();
        $err->defineOwnProperty('name', PropertyDescriptor::data(
            new JsString('OperationError'),
            true,
            false,
            true,
        ));
        $err->defineOwnProperty('message', PropertyDescriptor::data(
            new JsString($e->getMessage()),
            true,
            false,
            true,
        ));
        return JsPromise::rejected($err);
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $text): string
    {
        $padded = strtr($text, '-_', '+/');
        $padLen = (4 - strlen($padded) % 4) % 4;
        $decoded = base64_decode($padded . str_repeat('=', $padLen), true);
        if ($decoded === false) {
            throw new TypeError('Invalid base64url encoding');
        }
        return $decoded;
    }
}
