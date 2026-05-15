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
 *   - `sign(algorithm, key, data)` / `verify(algorithm, key, sig, data)` —
 *     HMAC, RSASSA-PKCS1-v1_5, RSA-PSS, ECDSA.
 *   - `encrypt(algorithm, key, data)` / `decrypt(algorithm, key, data)` —
 *     AES-CBC, AES-CTR, AES-GCM, RSA-OAEP via PHP `openssl_*` family.
 *   - `generateKey(algorithm, extractable, usages)` — HMAC, AES, RSA-*, ECDSA,
 *     ECDH. RSA / EC returns a `{ publicKey, privateKey }` CryptoKeyPair.
 *   - `importKey(format, keyData, algorithm, extractable, usages)` — raw
 *     (HMAC/AES), spki (public), pkcs8 (private), jwk (oct/RSA/EC).
 *   - `exportKey(format, key)` — raw / spki / pkcs8 / jwk.
 *   - `deriveBits(algorithm, baseKey, length)` /
 *     `deriveKey(algorithm, baseKey, derivedAlg, extractable, usages)` —
 *     ECDH, HKDF, PBKDF2.
 *   - `wrapKey` / `unwrapKey` are not yet exposed; users wrap manually with
 *     `encrypt` + `exportKey` for now.
 *
 * A CryptoKey is represented as a `JsObject` carrying internal slots:
 *   `[[KeyType]]`      — 'secret' | 'public' | 'private'
 *   `[[KeyAlgorithm]]` — normalized algorithm object (name + params)
 *   `[[KeyExtractable]]` — bool
 *   `[[KeyUsages]]`    — frozen array of strings
 *   `[[KeyMaterial]]`  — raw PHP string for symmetric keys, PEM string for
 *     RSA/EC keys; ECDH/HKDF/PBKDF2 raw inputs are also stored here.
 *
 * Returned ArrayBuffers wrap fresh bytes (no aliasing with caller buffers).
 *
 * Requires `ext-openssl` for AES, RSA, ECDSA, ECDH; `digest`, HMAC, HKDF,
 * PBKDF2 work without it via `hash()`, `hash_hmac()`, `hash_hkdf()`,
 * `hash_pbkdf2()`.
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
        self::defineMethod($subtle, 'deriveBits', 3, self::deriveBitsImpl());
        self::defineMethod($subtle, 'deriveKey', 5, self::deriveKeyImpl());

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
                $algName = strtoupper(self::normalizeAlgorithmName($alg));
                if ($algName === 'HMAC') {
                    $cryptoKey = self::requireCryptoKey($key);
                    self::assertUsage($cryptoKey, 'sign');
                    $hash = self::cryptoKeyHmacHash($cryptoKey);
                    $material = self::cryptoKeyMaterial($cryptoKey);
                    $mac = hash_hmac($hash, $data, $material, true);
                    return JsPromise::resolved(self::bufferFromBytes($mac));
                }
                if ($algName === 'RSASSA-PKCS1-V1_5') {
                    $cryptoKey = self::requireCryptoKey($key);
                    self::assertUsage($cryptoKey, 'sign');
                    $hash = self::keyAlgHashName($cryptoKey);
                    $sig = self::rsaPkcs1Sign(self::cryptoKeyMaterial($cryptoKey), $data, $hash);
                    return JsPromise::resolved(self::bufferFromBytes($sig));
                }
                if ($algName === 'RSA-PSS') {
                    $cryptoKey = self::requireCryptoKey($key);
                    self::assertUsage($cryptoKey, 'sign');
                    $hash = self::keyAlgHashName($cryptoKey);
                    $saltLen = self::extractSaltLength($alg, $hash);
                    $sig = self::rsaPssSign(self::cryptoKeyMaterial($cryptoKey), $data, $hash, $saltLen);
                    return JsPromise::resolved(self::bufferFromBytes($sig));
                }
                if ($algName === 'ECDSA') {
                    $cryptoKey = self::requireCryptoKey($key);
                    self::assertUsage($cryptoKey, 'sign');
                    $hash = strtoupper(self::stringFromAlgRef(
                        $alg instanceof JsObject ? $alg->get('hash') : JsUndefined::instance(),
                    ));
                    $curve = self::keyAlgNamedCurve($cryptoKey);
                    $sig = self::ecdsaSign(self::cryptoKeyMaterial($cryptoKey), $data, $hash, $curve);
                    return JsPromise::resolved(self::bufferFromBytes($sig));
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
                $algName = strtoupper(self::normalizeAlgorithmName($alg));
                if ($algName === 'HMAC') {
                    $cryptoKey = self::requireCryptoKey($key);
                    self::assertUsage($cryptoKey, 'verify');
                    $hash = self::cryptoKeyHmacHash($cryptoKey);
                    $material = self::cryptoKeyMaterial($cryptoKey);
                    $expected = hash_hmac($hash, $data, $material, true);
                    return JsPromise::resolved(JsBoolean::of(hash_equals($expected, $signature)));
                }
                if ($algName === 'RSASSA-PKCS1-V1_5') {
                    $cryptoKey = self::requireCryptoKey($key);
                    self::assertUsage($cryptoKey, 'verify');
                    $hash = self::keyAlgHashName($cryptoKey);
                    $ok = self::rsaPkcs1Verify(self::cryptoKeyMaterial($cryptoKey), $data, $signature, $hash);
                    return JsPromise::resolved(JsBoolean::of($ok));
                }
                if ($algName === 'RSA-PSS') {
                    $cryptoKey = self::requireCryptoKey($key);
                    self::assertUsage($cryptoKey, 'verify');
                    $hash = self::keyAlgHashName($cryptoKey);
                    $saltLen = self::extractSaltLength($alg, $hash);
                    $ok = self::rsaPssVerify(
                        self::cryptoKeyMaterial($cryptoKey),
                        $data,
                        $signature,
                        $hash,
                        $saltLen,
                    );
                    return JsPromise::resolved(JsBoolean::of($ok));
                }
                if ($algName === 'ECDSA') {
                    $cryptoKey = self::requireCryptoKey($key);
                    self::assertUsage($cryptoKey, 'verify');
                    $hash = strtoupper(self::stringFromAlgRef(
                        $alg instanceof JsObject ? $alg->get('hash') : JsUndefined::instance(),
                    ));
                    $curve = self::keyAlgNamedCurve($cryptoKey);
                    $ok = self::ecdsaVerify(
                        self::cryptoKeyMaterial($cryptoKey),
                        $data,
                        $signature,
                        $hash,
                        $curve,
                    );
                    return JsPromise::resolved(JsBoolean::of($ok));
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

                if ($algName === 'RSA-OAEP') {
                    $hash = self::keyAlgHashName($cryptoKey);
                    $label = $alg->has('label') && !$alg->get('label') instanceof JsUndefined
                        ? self::bufferSourceBytes($alg->get('label'))
                        : '';
                    $ct = self::rsaOaepEncrypt(self::cryptoKeyMaterial($cryptoKey), $plain, $hash, $label);
                    return JsPromise::resolved(self::bufferFromBytes($ct));
                }

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

                if ($algName === 'RSA-OAEP') {
                    $hash = self::keyAlgHashName($cryptoKey);
                    $label = $alg->has('label') && !$alg->get('label') instanceof JsUndefined
                        ? self::bufferSourceBytes($alg->get('label'))
                        : '';
                    $pt = self::rsaOaepDecrypt(self::cryptoKeyMaterial($cryptoKey), $cipherText, $hash, $label);
                    return JsPromise::resolved(self::bufferFromBytes($pt));
                }

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

                if (in_array($algName, ['RSA-OAEP', 'RSA-PSS', 'RSASSA-PKCS1-V1_5'], true)) {
                    $modulusBits = (int) TypeConversion::toNumber($alg->get('modulusLength'));
                    if ($modulusBits < 256) {
                        throw new TypeError("RSA modulusLength must be >= 256 (got {$modulusBits})");
                    }
                    $hash = strtoupper(self::stringFromAlgRef($alg->get('hash')));
                    self::ensurePhpHash($hash);
                    $publicExponent = $alg->has('publicExponent') && !$alg->get('publicExponent') instanceof JsUndefined
                        ? self::bufferSourceBytes($alg->get('publicExponent'))
                        : "\x01\x00\x01";
                    [$publicPem, $privatePem] = self::rsaGenerateKeyPair($modulusBits, $publicExponent);
                    $properAlgName = $algName === 'RSASSA-PKCS1-V1_5' ? 'RSASSA-PKCS1-v1_5' : $algName;
                    $algorithm = [
                        'name' => $properAlgName,
                        'modulusLength' => $modulusBits,
                        'publicExponent' => $publicExponent,
                        'hash' => ['name' => $hash],
                    ];
                    [$pubUsages, $privUsages] = self::splitKeyPairUsages($properAlgName, $usages);
                    $publicKey = self::makeCryptoKey('public', $algorithm, true, $pubUsages, $publicPem);
                    $privateKey = self::makeCryptoKey('private', $algorithm, $extractable, $privUsages, $privatePem);
                    return JsPromise::resolved(self::makeCryptoKeyPair($publicKey, $privateKey));
                }

                if ($algName === 'ECDSA' || $algName === 'ECDH') {
                    $curveName = strtoupper(self::stringPropOrThrow($alg, 'namedCurve', 'generateKey'));
                    $opensslCurve = self::opensslCurveName($curveName);
                    [$publicPem, $privatePem] = self::ecGenerateKeyPair($opensslCurve);
                    $algorithm = ['name' => $algName, 'namedCurve' => $curveName];
                    [$pubUsages, $privUsages] = self::splitKeyPairUsages($algName, $usages);
                    $publicKey = self::makeCryptoKey('public', $algorithm, true, $pubUsages, $publicPem);
                    $privateKey = self::makeCryptoKey('private', $algorithm, $extractable, $privUsages, $privatePem);
                    return JsPromise::resolved(self::makeCryptoKeyPair($publicKey, $privateKey));
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
                    if (strtoupper($algName) === 'HKDF') {
                        return JsPromise::resolved(self::makeCryptoKey(
                            'secret',
                            ['name' => 'HKDF'],
                            false,
                            $usages,
                            $bytes,
                        ));
                    }
                    if (strtoupper($algName) === 'PBKDF2') {
                        return JsPromise::resolved(self::makeCryptoKey(
                            'secret',
                            ['name' => 'PBKDF2'],
                            false,
                            $usages,
                            $bytes,
                        ));
                    }
                }
                if ($format === 'spki' || $format === 'pkcs8') {
                    return JsPromise::resolved(self::importAsymmetricKey(
                        $format,
                        self::bufferSourceBytes($keyData),
                        $algRaw,
                        $extractable,
                        $usages,
                    ));
                }
                if ($format === 'jwk') {
                    if (!$keyData instanceof JsObject) {
                        throw new TypeError('jwk import expects an object key');
                    }
                    $kty = self::stringPropOrThrow($keyData, 'kty', 'jwk');
                    if ($kty === 'oct') {
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
                        throw new TypeError("Unsupported algorithm '{$algName}' for jwk oct import");
                    }
                    if ($kty === 'RSA') {
                        return JsPromise::resolved(self::importRsaJwk(
                            $keyData,
                            $algRaw,
                            $extractable,
                            $usages,
                        ));
                    }
                    if ($kty === 'EC') {
                        return JsPromise::resolved(self::importEcJwk(
                            $keyData,
                            $algRaw,
                            $extractable,
                            $usages,
                        ));
                    }
                    throw new TypeError("jwk kty '{$kty}' not supported");
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
                $keyType = $key->getInternalProperty('[[KeyType]]');
                $alg = $key->getInternalProperty('[[KeyAlgorithm]]');
                $algName = is_array($alg) ? (string) ($alg['name'] ?? '') : '';
                $isAsym = in_array($algName, [
                    'RSA-OAEP',
                    'RSA-PSS',
                    'RSASSA-PKCS1-v1_5',
                    'ECDSA',
                    'ECDH',
                ], true);

                if ($format === 'raw') {
                    if ($isAsym && $keyType === 'public') {
                        return JsPromise::resolved(self::bufferFromBytes(
                            self::ecRawPublicFromPem($material),
                        ));
                    }
                    return JsPromise::resolved(self::bufferFromBytes($material));
                }
                if ($format === 'spki') {
                    if (!$isAsym || $keyType !== 'public') {
                        throw new TypeError('spki export requires a public asymmetric key');
                    }
                    return JsPromise::resolved(self::bufferFromBytes(self::pemToDer($material)));
                }
                if ($format === 'pkcs8') {
                    if (!$isAsym || $keyType !== 'private') {
                        throw new TypeError('pkcs8 export requires a private asymmetric key');
                    }
                    return JsPromise::resolved(self::bufferFromBytes(self::pemToDer($material)));
                }
                if ($format === 'jwk' && $isAsym) {
                    return JsPromise::resolved(self::exportAsymmetricJwk($key));
                }
                if ($format === 'jwk') {
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
    // deriveBits / deriveKey  (ECDH, HKDF, PBKDF2)
    // ---------------------------------------------------------------

    private static function deriveBitsImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($this_);
            try {
                $alg = self::requireObject($args[0] ?? JsUndefined::instance(), 'deriveBits');
                $baseKey = self::requireCryptoKey($args[1] ?? JsUndefined::instance());
                self::assertUsage($baseKey, 'deriveBits');
                $length = (int) TypeConversion::toNumber($args[2] ?? JsUndefined::instance());
                $bytes = self::deriveBytes($alg, $baseKey, $length);
                return JsPromise::resolved(self::bufferFromBytes($bytes));
            } catch (\Throwable $e) {
                return self::rejectFromThrow($e);
            }
        };
    }

    private static function deriveKeyImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($this_);
            try {
                $alg = self::requireObject($args[0] ?? JsUndefined::instance(), 'deriveKey');
                $baseKey = self::requireCryptoKey($args[1] ?? JsUndefined::instance());
                self::assertUsage($baseKey, 'deriveKey');
                $derivedAlg = self::requireObject($args[2] ?? JsUndefined::instance(), 'deriveKey');
                $extractable = TypeConversion::toBoolean($args[3] ?? JsUndefined::instance());
                $usages = self::collectUsages($args[4] ?? JsUndefined::instance());
                $derivedName = strtoupper(self::stringPropOrThrow($derivedAlg, 'name', 'deriveKey'));

                // Figure out how many bits we need for the derived key.
                if ($derivedName === 'HMAC') {
                    $hash = strtoupper(self::stringFromAlgRef($derivedAlg->get('hash')));
                    $defaultLen = match ($hash) {
                        'SHA-1' => 64,
                        'SHA-256' => 64,
                        'SHA-384' => 128,
                        'SHA-512' => 128,
                        default => throw new TypeError("Unsupported HMAC hash: {$hash}"),
                    };
                    $bytes = $derivedAlg->has('length') && !$derivedAlg->get('length') instanceof JsUndefined
                        ? (int) (TypeConversion::toNumber($derivedAlg->get('length')) / 8)
                        : $defaultLen;
                    $material = self::deriveBytes($alg, $baseKey, $bytes * 8);
                    return JsPromise::resolved(self::makeCryptoKey(
                        'secret',
                        ['name' => 'HMAC', 'hash' => ['name' => $hash], 'length' => $bytes * 8],
                        $extractable,
                        $usages,
                        $material,
                    ));
                }
                if (in_array($derivedName, ['AES-CBC', 'AES-CTR', 'AES-GCM'], true)) {
                    $bits = (int) TypeConversion::toNumber($derivedAlg->get('length'));
                    if (!in_array($bits, [128, 192, 256], true)) {
                        throw new TypeError("AES key length must be 128, 192, or 256 (got {$bits})");
                    }
                    $material = self::deriveBytes($alg, $baseKey, $bits);
                    return JsPromise::resolved(self::makeCryptoKey(
                        'secret',
                        ['name' => $derivedName, 'length' => $bits],
                        $extractable,
                        $usages,
                        $material,
                    ));
                }
                throw new TypeError("Unsupported derived key algorithm: {$derivedName}");
            } catch (\Throwable $e) {
                return self::rejectFromThrow($e);
            }
        };
    }

    private static function deriveBytes(JsObject $alg, JsObject $baseKey, int $lengthBits): string
    {
        if ($lengthBits <= 0 || $lengthBits % 8 !== 0) {
            throw new TypeError('deriveBits length must be a positive multiple of 8');
        }
        $bytes = $lengthBits / 8;
        $name = strtoupper(self::stringPropOrThrow($alg, 'name', 'derive'));
        if ($name === 'ECDH') {
            if (!extension_loaded('openssl')) {
                throw new \RuntimeException('ECDH requires ext-openssl');
            }
            $publicEntry = $alg->get('public');
            if (!$publicEntry instanceof JsObject || $publicEntry->getInternalProperty('[[IsCryptoKey]]') !== true) {
                throw new TypeError('ECDH algorithm.public must be a public CryptoKey');
            }
            $privateMaterial = self::cryptoKeyMaterial($baseKey);
            $publicMaterial = self::cryptoKeyMaterial($publicEntry);
            $shared = openssl_pkey_derive($publicMaterial, $privateMaterial);
            if ($shared === false) {
                throw new \RuntimeException('openssl_pkey_derive failed: ' . (openssl_error_string() ?: 'unknown'));
            }
            if (strlen($shared) < $bytes) {
                throw new TypeError('deriveBits length exceeds shared-secret size');
            }
            // Spec: take the leftmost `length` bits.
            return substr($shared, 0, $bytes);
        }
        if ($name === 'HKDF') {
            $hash = strtoupper(self::stringFromAlgRef($alg->get('hash')));
            self::ensurePhpHash($hash);
            $salt = $alg->has('salt') ? self::bufferSourceBytes($alg->get('salt')) : '';
            $info = $alg->has('info') ? self::bufferSourceBytes($alg->get('info')) : '';
            $ikm = self::cryptoKeyMaterial($baseKey);
            return hash_hkdf(strtolower(str_replace('-', '', $hash)), $ikm, $bytes, $info, $salt);
        }
        if ($name === 'PBKDF2') {
            $hash = strtoupper(self::stringFromAlgRef($alg->get('hash')));
            self::ensurePhpHash($hash);
            $salt = self::bufferSourceBytes($alg->get('salt'));
            $iterations = (int) TypeConversion::toNumber($alg->get('iterations'));
            if ($iterations < 1) {
                throw new TypeError('PBKDF2 iterations must be >= 1');
            }
            $password = self::cryptoKeyMaterial($baseKey);
            $phpAlgo = strtolower(str_replace('-', '', $hash));
            $out = hash_pbkdf2($phpAlgo, $password, $salt, $iterations, $bytes, true);
            return $out;
        }
        throw new TypeError("Unsupported derive algorithm: {$name}");
    }

    // ---------------------------------------------------------------
    // RSA / EC key gen + import + export + jwk
    // ---------------------------------------------------------------

    /** @return array{0:string, 1:string} */
    private static function rsaGenerateKeyPair(int $modulusBits, string $publicExponent): array
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('RSA generateKey requires ext-openssl');
        }
        unset($publicExponent); // PHP openssl only supports e=65537 via this API
        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => $modulusBits,
        ];
        $resource = openssl_pkey_new($config);
        if ($resource === false) {
            throw new \RuntimeException('openssl_pkey_new (RSA) failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        $privatePem = '';
        if (!openssl_pkey_export($resource, $privatePem)) {
            throw new \RuntimeException('openssl_pkey_export failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['key'])) {
            throw new \RuntimeException('openssl_pkey_get_details failed');
        }
        return [(string) $details['key'], $privatePem];
    }

    /** @return array{0:string, 1:string} */
    private static function ecGenerateKeyPair(string $opensslCurve): array
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('EC generateKey requires ext-openssl');
        }
        $config = [
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => $opensslCurve,
        ];
        $resource = openssl_pkey_new($config);
        if ($resource === false) {
            throw new \RuntimeException('openssl_pkey_new (EC) failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        $privatePem = '';
        if (!openssl_pkey_export($resource, $privatePem)) {
            throw new \RuntimeException('openssl_pkey_export failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['key'])) {
            throw new \RuntimeException('openssl_pkey_get_details failed');
        }
        return [(string) $details['key'], $privatePem];
    }

    private static function opensslCurveName(string $namedCurve): string
    {
        return match (strtoupper($namedCurve)) {
            'P-256' => 'prime256v1',
            'P-384' => 'secp384r1',
            'P-521' => 'secp521r1',
            default => throw new TypeError("Unsupported namedCurve: {$namedCurve}"),
        };
    }

    private static function curveByteSize(string $namedCurve): int
    {
        return match (strtoupper($namedCurve)) {
            'P-256' => 32,
            'P-384' => 48,
            'P-521' => 66,
            default => throw new TypeError("Unsupported namedCurve: {$namedCurve}"),
        };
    }

    /**
     * @param list<string> $usages
     */
    private static function importAsymmetricKey(
        string $format,
        string $bytes,
        JsValue $algRaw,
        bool $extractable,
        array $usages,
    ): JsObject {
        if (!$algRaw instanceof JsObject) {
            throw new TypeError('importKey algorithm must be an object for spki/pkcs8');
        }
        $algName = strtoupper(self::stringPropOrThrow($algRaw, 'name', 'importKey'));
        $pem = self::derToPem($bytes, $format === 'spki' ? 'PUBLIC KEY' : 'PRIVATE KEY');
        if (in_array($algName, ['RSA-OAEP', 'RSA-PSS', 'RSASSA-PKCS1-V1_5'], true)) {
            $hash = strtoupper(self::stringFromAlgRef($algRaw->get('hash')));
            self::ensurePhpHash($hash);
            $details = self::loadKeyDetails($pem, $format === 'spki' ? 'public' : 'private');
            if (($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA) {
                throw new TypeError('Imported key is not an RSA key');
            }
            $publicExponent = $details['rsa']['e'] ?? "\x01\x00\x01";
            $properAlgName = $algName === 'RSASSA-PKCS1-V1_5' ? 'RSASSA-PKCS1-v1_5' : $algName;
            $algorithm = [
                'name' => $properAlgName,
                'modulusLength' => (int) ($details['bits'] ?? 0),
                'publicExponent' => $publicExponent,
                'hash' => ['name' => $hash],
            ];
            return self::makeCryptoKey(
                $format === 'spki' ? 'public' : 'private',
                $algorithm,
                $extractable,
                $usages,
                $pem,
            );
        }
        if ($algName === 'ECDSA' || $algName === 'ECDH') {
            $namedCurve = strtoupper(self::stringPropOrThrow($algRaw, 'namedCurve', 'importKey'));
            self::opensslCurveName($namedCurve); // validates
            $algorithm = ['name' => $algName, 'namedCurve' => $namedCurve];
            return self::makeCryptoKey(
                $format === 'spki' ? 'public' : 'private',
                $algorithm,
                $extractable,
                $usages,
                $pem,
            );
        }
        throw new TypeError("Unsupported algorithm '{$algName}' for spki/pkcs8 import");
    }

    /**
     * @param list<string> $usages
     */
    private static function importRsaJwk(
        JsObject $jwk,
        JsValue $algRaw,
        bool $extractable,
        array $usages,
    ): JsObject {
        if (!$algRaw instanceof JsObject) {
            throw new TypeError('importKey algorithm must be an object for jwk');
        }
        $algName = strtoupper(self::stringPropOrThrow($algRaw, 'name', 'importKey'));
        $hash = strtoupper(self::stringFromAlgRef($algRaw->get('hash')));
        self::ensurePhpHash($hash);
        $n = self::base64UrlDecode(self::stringPropOrThrow($jwk, 'n', 'jwk'));
        $e = self::base64UrlDecode(self::stringPropOrThrow($jwk, 'e', 'jwk'));
        $hasPriv = $jwk->has('d');
        if ($hasPriv) {
            $d = self::base64UrlDecode(self::stringPropOrThrow($jwk, 'd', 'jwk'));
            $p = self::base64UrlDecode(self::stringPropOrThrow($jwk, 'p', 'jwk'));
            $q = self::base64UrlDecode(self::stringPropOrThrow($jwk, 'q', 'jwk'));
            $dp = self::base64UrlDecode(self::stringPropOrThrow($jwk, 'dp', 'jwk'));
            $dq = self::base64UrlDecode(self::stringPropOrThrow($jwk, 'dq', 'jwk'));
            $qi = self::base64UrlDecode(self::stringPropOrThrow($jwk, 'qi', 'jwk'));
            $der = self::buildRsaPrivateKeyPkcs8($n, $e, $d, $p, $q, $dp, $dq, $qi);
            $pem = self::derToPem($der, 'PRIVATE KEY');
            $type = 'private';
        } else {
            $der = self::buildRsaPublicKeySpki($n, $e);
            $pem = self::derToPem($der, 'PUBLIC KEY');
            $type = 'public';
        }
        $properAlgName = $algName === 'RSASSA-PKCS1-V1_5' ? 'RSASSA-PKCS1-v1_5' : $algName;
        $algorithm = [
            'name' => $properAlgName,
            'modulusLength' => strlen(ltrim($n, "\x00")) * 8,
            'publicExponent' => $e,
            'hash' => ['name' => $hash],
        ];
        return self::makeCryptoKey($type, $algorithm, $extractable, $usages, $pem);
    }

    /**
     * @param list<string> $usages
     */
    private static function importEcJwk(
        JsObject $jwk,
        JsValue $algRaw,
        bool $extractable,
        array $usages,
    ): JsObject {
        if (!$algRaw instanceof JsObject) {
            throw new TypeError('importKey algorithm must be an object for jwk');
        }
        $algName = strtoupper(self::stringPropOrThrow($algRaw, 'name', 'importKey'));
        $crv = self::stringPropOrThrow($jwk, 'crv', 'jwk');
        $namedCurve = strtoupper($crv);
        $opensslCurve = self::opensslCurveName($namedCurve);
        $size = self::curveByteSize($namedCurve);
        $x = self::base64UrlDecode(self::stringPropOrThrow($jwk, 'x', 'jwk'));
        $y = self::base64UrlDecode(self::stringPropOrThrow($jwk, 'y', 'jwk'));
        $x = str_pad($x, $size, "\x00", STR_PAD_LEFT);
        $y = str_pad($y, $size, "\x00", STR_PAD_LEFT);
        $hasPriv = $jwk->has('d');
        if ($hasPriv) {
            $d = self::base64UrlDecode(self::stringPropOrThrow($jwk, 'd', 'jwk'));
            $d = str_pad($d, $size, "\x00", STR_PAD_LEFT);
            $der = self::buildEcPrivateKeyPkcs8($opensslCurve, $d, $x, $y);
            $pem = self::derToPem($der, 'PRIVATE KEY');
            $type = 'private';
        } else {
            $der = self::buildEcPublicKeySpki($opensslCurve, $x, $y);
            $pem = self::derToPem($der, 'PUBLIC KEY');
            $type = 'public';
        }
        $algorithm = ['name' => $algName, 'namedCurve' => $namedCurve];
        return self::makeCryptoKey($type, $algorithm, $extractable, $usages, $pem);
    }

    private static function exportAsymmetricJwk(JsObject $key): JsObject
    {
        $rawAlg = $key->getInternalProperty('[[KeyAlgorithm]]');
        $alg = is_array($rawAlg) ? $rawAlg : [];
        $pem = self::cryptoKeyMaterial($key);
        $type = $key->getInternalProperty('[[KeyType]]');
        $algName = (string) ($alg['name'] ?? '');
        $details = self::loadKeyDetails($pem, $type === 'private' ? 'private' : 'public');
        $jwk = new JsObject();
        $defOwn = static function (JsObject $o, string $name, JsValue $v): void {
            $o->defineOwnProperty($name, PropertyDescriptor::data($v, true, true, true));
        };
        if (in_array($algName, ['RSA-OAEP', 'RSA-PSS', 'RSASSA-PKCS1-v1_5'], true)) {
            $defOwn($jwk, 'kty', new JsString('RSA'));
            $rsa = $details['rsa'] ?? [];
            $defOwn($jwk, 'n', new JsString(self::base64UrlEncode((string) ($rsa['n'] ?? ''))));
            $defOwn($jwk, 'e', new JsString(self::base64UrlEncode((string) ($rsa['e'] ?? ''))));
            if ($type === 'private') {
                $defOwn($jwk, 'd', new JsString(self::base64UrlEncode((string) ($rsa['d'] ?? ''))));
                $defOwn($jwk, 'p', new JsString(self::base64UrlEncode((string) ($rsa['p'] ?? ''))));
                $defOwn($jwk, 'q', new JsString(self::base64UrlEncode((string) ($rsa['q'] ?? ''))));
                $defOwn($jwk, 'dp', new JsString(self::base64UrlEncode((string) ($rsa['dmp1'] ?? ''))));
                $defOwn($jwk, 'dq', new JsString(self::base64UrlEncode((string) ($rsa['dmq1'] ?? ''))));
                $defOwn($jwk, 'qi', new JsString(self::base64UrlEncode((string) ($rsa['iqmp'] ?? ''))));
            }
            $hashName = $alg['hash']['name'] ?? null;
            $jwkAlg = self::rsaJwkAlg($algName, is_string($hashName) ? $hashName : null);
            if ($jwkAlg !== null) {
                $defOwn($jwk, 'alg', new JsString($jwkAlg));
            }
            $defOwn($jwk, 'ext', JsBoolean::of(true));
            return $jwk;
        }
        if ($algName === 'ECDSA' || $algName === 'ECDH') {
            $defOwn($jwk, 'kty', new JsString('EC'));
            $namedCurve = (string) ($alg['namedCurve'] ?? '');
            $defOwn($jwk, 'crv', new JsString($namedCurve));
            $ec = $details['ec'] ?? [];
            $size = self::curveByteSize($namedCurve);
            $xRaw = (string) ($ec['x'] ?? '');
            $yRaw = (string) ($ec['y'] ?? '');
            $defOwn($jwk, 'x', new JsString(self::base64UrlEncode(
                str_pad($xRaw, $size, "\x00", STR_PAD_LEFT),
            )));
            $defOwn($jwk, 'y', new JsString(self::base64UrlEncode(
                str_pad($yRaw, $size, "\x00", STR_PAD_LEFT),
            )));
            if ($type === 'private' && isset($ec['d'])) {
                $defOwn($jwk, 'd', new JsString(self::base64UrlEncode(
                    str_pad((string) $ec['d'], $size, "\x00", STR_PAD_LEFT),
                )));
            }
            $defOwn($jwk, 'ext', JsBoolean::of(true));
            return $jwk;
        }
        throw new TypeError("Unsupported algorithm '{$algName}' for jwk export");
    }

    private static function rsaJwkAlg(string $algName, ?string $hash): ?string
    {
        $suffix = match ($hash) {
            'SHA-1' => '1',
            'SHA-256' => '256',
            'SHA-384' => '384',
            'SHA-512' => '512',
            default => null,
        };
        if ($suffix === null) {
            return null;
        }
        return match ($algName) {
            'RSA-OAEP' => $hash === 'SHA-1' ? 'RSA-OAEP' : 'RSA-OAEP-' . $suffix,
            'RSA-PSS' => 'PS' . $suffix,
            'RSASSA-PKCS1-v1_5' => 'RS' . $suffix,
            default => null,
        };
    }

    /**
     * Load key via openssl and return its details, ensuring the right
     * accessor is used for public vs private PEMs.
     *
     * @return array<string, mixed>
     */
    private static function loadKeyDetails(string $pem, string $type): array
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException('openssl extension required');
        }
        if ($type === 'public') {
            $resource = openssl_pkey_get_public($pem);
        } else {
            $resource = openssl_pkey_get_private($pem);
        }
        if ($resource === false) {
            throw new \RuntimeException('Failed to load key: ' . (openssl_error_string() ?: 'unknown'));
        }
        $details = openssl_pkey_get_details($resource);
        if ($details === false) {
            throw new \RuntimeException('openssl_pkey_get_details failed');
        }
        return $details;
    }

    private static function ecRawPublicFromPem(string $pem): string
    {
        $details = self::loadKeyDetails($pem, 'public');
        $alg = $details['type'] ?? -1;
        if ($alg !== OPENSSL_KEYTYPE_EC) {
            throw new TypeError('raw export only supported for EC public keys');
        }
        $curve = (string) ($details['ec']['curve_name'] ?? '');
        $namedCurve = match ($curve) {
            'prime256v1' => 'P-256',
            'secp384r1' => 'P-384',
            'secp521r1' => 'P-521',
            default => null,
        };
        if ($namedCurve === null) {
            throw new TypeError("Unsupported EC curve for raw export: {$curve}");
        }
        $size = self::curveByteSize($namedCurve);
        $x = str_pad((string) ($details['ec']['x'] ?? ''), $size, "\x00", STR_PAD_LEFT);
        $y = str_pad((string) ($details['ec']['y'] ?? ''), $size, "\x00", STR_PAD_LEFT);
        return "\x04" . $x . $y;
    }

    // ---------------------------------------------------------------
    // RSASSA-PKCS1-v1_5
    // ---------------------------------------------------------------

    private static function rsaPkcs1Sign(string $privatePem, string $data, string $hash): string
    {
        $algo = self::opensslHashAlgo($hash);
        $sig = '';
        if (!openssl_sign($data, $sig, $privatePem, $algo)) {
            throw new \RuntimeException('openssl_sign failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        return $sig;
    }

    private static function rsaPkcs1Verify(string $publicPem, string $data, string $signature, string $hash): bool
    {
        $algo = self::opensslHashAlgo($hash);
        $result = openssl_verify($data, $signature, $publicPem, $algo);
        if ($result === -1) {
            throw new \RuntimeException('openssl_verify failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        return $result === 1;
    }

    // ---------------------------------------------------------------
    // RSA-PSS  (pure-PHP EMSA-PSS encode/decode on top of raw RSA)
    // ---------------------------------------------------------------

    private static function rsaPssSign(string $privatePem, string $data, string $hash, int $saltLen): string
    {
        $details = self::loadKeyDetails($privatePem, 'private');
        $emBits = ((int) $details['bits']) - 1;
        $emLen = (int) ceil($emBits / 8);
        $em = self::emsaPssEncode($data, $emBits, $hash, $saltLen);
        // Pad EM up to modulus byte length (k = ceil(modulusBits / 8)).
        $k = (int) ceil($details['bits'] / 8);
        $em = str_pad($em, $k, "\x00", STR_PAD_LEFT);
        $sig = '';
        if (!openssl_private_encrypt($em, $sig, $privatePem, OPENSSL_NO_PADDING)) {
            throw new \RuntimeException('openssl_private_encrypt failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        unset($emLen);
        return $sig;
    }

    private static function rsaPssVerify(
        string $publicPem,
        string $data,
        string $signature,
        string $hash,
        int $saltLen,
    ): bool {
        $details = self::loadKeyDetails($publicPem, 'public');
        $k = (int) ceil($details['bits'] / 8);
        if (strlen($signature) !== $k) {
            return false;
        }
        $em = '';
        if (!openssl_public_decrypt($signature, $em, $publicPem, OPENSSL_NO_PADDING)) {
            return false;
        }
        $emBits = ((int) $details['bits']) - 1;
        $emLen = (int) ceil($emBits / 8);
        // Strip leading zero bytes that bring EM up to k (mod size).
        if (strlen($em) > $emLen) {
            $em = substr($em, -$emLen);
        } elseif (strlen($em) < $emLen) {
            $em = str_pad($em, $emLen, "\x00", STR_PAD_LEFT);
        }
        return self::emsaPssVerify($data, $em, $emBits, $hash, $saltLen);
    }

    private static function emsaPssEncode(string $msg, int $emBits, string $hash, int $saltLen): string
    {
        $hLen = self::hashOutputSize($hash);
        $emLen = (int) ceil($emBits / 8);
        if ($emLen < $hLen + $saltLen + 2) {
            throw new \RuntimeException('PSS: encoding error');
        }
        $mHash = hash(self::phpHashName($hash), $msg, true);
        $salt = $saltLen > 0 ? random_bytes($saltLen) : '';
        $mPrime = str_repeat("\x00", 8) . $mHash . $salt;
        $h = hash(self::phpHashName($hash), $mPrime, true);
        $ps = str_repeat("\x00", $emLen - $saltLen - $hLen - 2);
        $db = $ps . "\x01" . $salt;
        $dbMask = self::mgf1($h, $emLen - $hLen - 1, $hash);
        $maskedDb = $db ^ $dbMask;
        // Zero out leftmost (8*emLen - emBits) bits of maskedDb.
        $bitsToClear = 8 * $emLen - $emBits;
        if ($bitsToClear > 0) {
            $maskedDb[0] = chr(ord($maskedDb[0]) & (0xFF >> $bitsToClear));
        }
        return $maskedDb . $h . "\xBC";
    }

    private static function emsaPssVerify(string $msg, string $em, int $emBits, string $hash, int $saltLen): bool
    {
        $hLen = self::hashOutputSize($hash);
        $emLen = strlen($em);
        if ($emLen < $hLen + $saltLen + 2) {
            return false;
        }
        if (substr($em, -1) !== "\xBC") {
            return false;
        }
        $maskedDb = substr($em, 0, $emLen - $hLen - 1);
        $h = substr($em, $emLen - $hLen - 1, $hLen);
        $bitsToClear = 8 * $emLen - $emBits;
        if ($bitsToClear > 0) {
            if ((ord($maskedDb[0]) & (0xFF << (8 - $bitsToClear)) & 0xFF) !== 0) {
                return false;
            }
        }
        $dbMask = self::mgf1($h, $emLen - $hLen - 1, $hash);
        $db = $maskedDb ^ $dbMask;
        if ($bitsToClear > 0) {
            $db[0] = chr(ord($db[0]) & (0xFF >> $bitsToClear));
        }
        $psLen = $emLen - $hLen - $saltLen - 2;
        for ($i = 0; $i < $psLen; $i++) {
            if ($db[$i] !== "\x00") {
                return false;
            }
        }
        if ($db[$psLen] !== "\x01") {
            return false;
        }
        $salt = $saltLen > 0 ? substr($db, -$saltLen) : '';
        $mHash = hash(self::phpHashName($hash), $msg, true);
        $mPrime = str_repeat("\x00", 8) . $mHash . $salt;
        $hPrime = hash(self::phpHashName($hash), $mPrime, true);
        return hash_equals($h, $hPrime);
    }

    private static function mgf1(string $seed, int $maskLen, string $hash): string
    {
        $hLen = self::hashOutputSize($hash);
        $iters = (int) ceil($maskLen / $hLen);
        $t = '';
        for ($i = 0; $i < $iters; $i++) {
            $c = pack('N', $i);
            $t .= hash(self::phpHashName($hash), $seed . $c, true);
        }
        return substr($t, 0, $maskLen);
    }

    // ---------------------------------------------------------------
    // RSA-OAEP  (pure-PHP OAEP encode/decode on top of raw RSA)
    // ---------------------------------------------------------------

    private static function rsaOaepEncrypt(string $publicPem, string $msg, string $hash, string $label): string
    {
        $details = self::loadKeyDetails($publicPem, 'public');
        $k = (int) ceil($details['bits'] / 8);
        $hLen = self::hashOutputSize($hash);
        if (strlen($msg) > $k - 2 * $hLen - 2) {
            throw new \RuntimeException('RSA-OAEP: message too long');
        }
        $em = self::oaepEncode($msg, $k, $hash, $label);
        $ct = '';
        if (!openssl_public_encrypt($em, $ct, $publicPem, OPENSSL_NO_PADDING)) {
            throw new \RuntimeException('openssl_public_encrypt failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        return $ct;
    }

    private static function rsaOaepDecrypt(string $privatePem, string $ct, string $hash, string $label): string
    {
        $details = self::loadKeyDetails($privatePem, 'private');
        $k = (int) ceil($details['bits'] / 8);
        if (strlen($ct) !== $k) {
            throw new \RuntimeException('RSA-OAEP: ciphertext length mismatch');
        }
        $em = '';
        if (!openssl_private_decrypt($ct, $em, $privatePem, OPENSSL_NO_PADDING)) {
            throw new \RuntimeException('openssl_private_decrypt failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        if (strlen($em) < $k) {
            $em = str_pad($em, $k, "\x00", STR_PAD_LEFT);
        }
        return self::oaepDecode($em, $k, $hash, $label);
    }

    private static function oaepEncode(string $msg, int $k, string $hash, string $label): string
    {
        $hLen = self::hashOutputSize($hash);
        $mLen = strlen($msg);
        $lHash = hash(self::phpHashName($hash), $label, true);
        $psLen = $k - $mLen - 2 * $hLen - 2;
        $ps = str_repeat("\x00", max(0, $psLen));
        $db = $lHash . $ps . "\x01" . $msg;
        $seed = random_bytes($hLen);
        $dbMask = self::mgf1($seed, $k - $hLen - 1, $hash);
        $maskedDb = $db ^ $dbMask;
        $seedMask = self::mgf1($maskedDb, $hLen, $hash);
        $maskedSeed = $seed ^ $seedMask;
        return "\x00" . $maskedSeed . $maskedDb;
    }

    private static function oaepDecode(string $em, int $k, string $hash, string $label): string
    {
        $hLen = self::hashOutputSize($hash);
        if ($k < 2 * $hLen + 2) {
            throw new \RuntimeException('RSA-OAEP: decoding error');
        }
        if (strlen($em) !== $k || $em[0] !== "\x00") {
            throw new \RuntimeException('RSA-OAEP: decoding error');
        }
        $maskedSeed = substr($em, 1, $hLen);
        $maskedDb = substr($em, 1 + $hLen);
        $seedMask = self::mgf1($maskedDb, $hLen, $hash);
        $seed = $maskedSeed ^ $seedMask;
        $dbMask = self::mgf1($seed, $k - $hLen - 1, $hash);
        $db = $maskedDb ^ $dbMask;
        $lHash = hash(self::phpHashName($hash), $label, true);
        $lHashPrime = substr($db, 0, $hLen);
        if (!hash_equals($lHash, $lHashPrime)) {
            throw new \RuntimeException('RSA-OAEP: decoding error');
        }
        $rest = substr($db, $hLen);
        $sep = strpos($rest, "\x01");
        if ($sep === false) {
            throw new \RuntimeException('RSA-OAEP: decoding error');
        }
        for ($i = 0; $i < $sep; $i++) {
            if ($rest[$i] !== "\x00") {
                throw new \RuntimeException('RSA-OAEP: decoding error');
            }
        }
        return substr($rest, $sep + 1);
    }

    // ---------------------------------------------------------------
    // ECDSA
    // ---------------------------------------------------------------

    private static function ecdsaSign(string $privatePem, string $data, string $hash, string $namedCurve): string
    {
        $algo = self::opensslHashAlgo($hash);
        $derSig = '';
        if (!openssl_sign($data, $derSig, $privatePem, $algo)) {
            throw new \RuntimeException('openssl_sign failed: ' . (openssl_error_string() ?: 'unknown'));
        }
        // WebCrypto expects raw r||s, not the DER-encoded form openssl produces.
        $size = self::curveByteSize($namedCurve);
        return self::derToRawEcdsaSignature($derSig, $size);
    }

    private static function ecdsaVerify(
        string $publicPem,
        string $data,
        string $signature,
        string $hash,
        string $namedCurve,
    ): bool {
        $size = self::curveByteSize($namedCurve);
        if (strlen($signature) !== 2 * $size) {
            return false;
        }
        $algo = self::opensslHashAlgo($hash);
        $derSig = self::rawToDerEcdsaSignature($signature, $size);
        $result = openssl_verify($data, $derSig, $publicPem, $algo);
        if ($result === -1) {
            return false;
        }
        return $result === 1;
    }

    private static function derToRawEcdsaSignature(string $der, int $size): string
    {
        $offset = 0;
        if (($der[$offset] ?? '') !== "\x30") {
            throw new \RuntimeException('ECDSA: bad DER signature');
        }
        $offset++;
        self::readDerLength($der, $offset);
        if (($der[$offset] ?? '') !== "\x02") {
            throw new \RuntimeException('ECDSA: bad DER signature');
        }
        $offset++;
        $rLen = self::readDerLength($der, $offset);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;
        if (($der[$offset] ?? '') !== "\x02") {
            throw new \RuntimeException('ECDSA: bad DER signature');
        }
        $offset++;
        $sLen = self::readDerLength($der, $offset);
        $s = substr($der, $offset, $sLen);
        // Strip any leading zeros introduced by DER signed-integer rules.
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        if (strlen($r) > $size || strlen($s) > $size) {
            throw new \RuntimeException('ECDSA: r/s exceeds curve size');
        }
        $r = str_pad($r, $size, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, $size, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }

    private static function rawToDerEcdsaSignature(string $raw, int $size): string
    {
        $r = substr($raw, 0, $size);
        $s = substr($raw, $size, $size);
        $rInt = self::derInteger($r);
        $sInt = self::derInteger($s);
        $body = $rInt . $sInt;
        return "\x30" . self::derLength(strlen($body)) . $body;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    /**
     * @param-out int $offset
     */
    private static function readDerLength(string $der, int &$offset): int
    {
        $b = ord($der[$offset]);
        $offset++;
        if (($b & 0x80) === 0) {
            return $b;
        }
        $count = $b & 0x7F;
        $len = 0;
        for ($i = 0; $i < $count; $i++) {
            $len = ($len << 8) | ord($der[$offset]);
            $offset++;
        }
        return $len;
    }

    private static function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = '';
        $n = $len;
        while ($n > 0) {
            $bytes = chr($n & 0xFF) . $bytes;
            $n >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    // ---------------------------------------------------------------
    // ASN.1 / PEM helpers (just enough for RSA + EC SPKI / PKCS8)
    // ---------------------------------------------------------------

    private static function derToPem(string $der, string $label): string
    {
        $b64 = base64_encode($der);
        $lines = trim(chunk_split($b64, 64, "\n"));
        return "-----BEGIN {$label}-----\n" . $lines . "\n-----END {$label}-----\n";
    }

    private static function pemToDer(string $pem): string
    {
        $clean = preg_replace('/-----BEGIN [^-]+-----|-----END [^-]+-----|\s+/', '', $pem) ?? '';
        $der = base64_decode($clean, true);
        if ($der === false) {
            throw new \RuntimeException('PEM decode failed');
        }
        return $der;
    }

    private static function asnInteger(string $bytes): string
    {
        // Bytes already big-endian; treat as positive.
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function asnSequence(string $body): string
    {
        return "\x30" . self::derLength(strlen($body)) . $body;
    }

    private static function asnBitString(string $body): string
    {
        // Unused-bits byte is 0.
        $bits = "\x00" . $body;
        return "\x03" . self::derLength(strlen($bits)) . $bits;
    }

    private static function asnOctetString(string $body): string
    {
        return "\x04" . self::derLength(strlen($body)) . $body;
    }

    /** OID 1.2.840.113549.1.1.1  rsaEncryption */
    private const OID_RSA = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01";

    /** OID 1.2.840.10045.2.1  id-ecPublicKey */
    private const OID_EC = "\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01";

    /** OID 1.2.840.10045.3.1.7  prime256v1 */
    private const OID_P256 = "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";

    /** OID 1.3.132.0.34  secp384r1 */
    private const OID_P384 = "\x06\x05\x2B\x81\x04\x00\x22";

    /** OID 1.3.132.0.35  secp521r1 */
    private const OID_P521 = "\x06\x05\x2B\x81\x04\x00\x23";

    private static function curveOid(string $opensslCurve): string
    {
        return match ($opensslCurve) {
            'prime256v1' => self::OID_P256,
            'secp384r1' => self::OID_P384,
            'secp521r1' => self::OID_P521,
            default => throw new TypeError("Unsupported EC curve: {$opensslCurve}"),
        };
    }

    private static function buildRsaPublicKeySpki(string $n, string $e): string
    {
        $rsaKey = self::asnSequence(self::asnInteger($n) . self::asnInteger($e));
        $algId = self::asnSequence(self::OID_RSA . "\x05\x00");
        return self::asnSequence($algId . self::asnBitString($rsaKey));
    }

    private static function buildRsaPrivateKeyPkcs8(
        string $n,
        string $e,
        string $d,
        string $p,
        string $q,
        string $dp,
        string $dq,
        string $qi,
    ): string {
        $rsaPrivate = self::asnSequence(
            self::asnInteger("\x00")          // version=0
            . self::asnInteger($n)
            . self::asnInteger($e)
            . self::asnInteger($d)
            . self::asnInteger($p)
            . self::asnInteger($q)
            . self::asnInteger($dp)
            . self::asnInteger($dq)
            . self::asnInteger($qi),
        );
        $algId = self::asnSequence(self::OID_RSA . "\x05\x00");
        return self::asnSequence(
            self::asnInteger("\x00")
            . $algId
            . self::asnOctetString($rsaPrivate),
        );
    }

    private static function buildEcPublicKeySpki(string $opensslCurve, string $x, string $y): string
    {
        $algId = self::asnSequence(self::OID_EC . self::curveOid($opensslCurve));
        $point = "\x04" . $x . $y;
        return self::asnSequence($algId . self::asnBitString($point));
    }

    private static function buildEcPrivateKeyPkcs8(string $opensslCurve, string $d, string $x, string $y): string
    {
        // RFC 5915 ECPrivateKey ::= SEQUENCE { version INTEGER (1),
        //   privateKey OCTET STRING, parameters [0] EXPLICIT OPTIONAL,
        //   publicKey  [1] EXPLICIT BIT STRING OPTIONAL }
        $point = "\x04" . $x . $y;
        $ecPrivate = self::asnSequence(
            self::asnInteger("\x01")
            . self::asnOctetString($d)
            . "\xA1" . self::derLength(strlen(self::asnBitString($point))) . self::asnBitString($point),
        );
        $algId = self::asnSequence(self::OID_EC . self::curveOid($opensslCurve));
        return self::asnSequence(
            self::asnInteger("\x00")
            . $algId
            . self::asnOctetString($ecPrivate),
        );
    }

    // ---------------------------------------------------------------
    // CryptoKeyPair + small lookup helpers
    // ---------------------------------------------------------------

    private static function makeCryptoKeyPair(JsObject $publicKey, JsObject $privateKey): JsObject
    {
        $pair = new JsObject();
        $pair->defineOwnProperty('publicKey', PropertyDescriptor::data($publicKey, true, true, true));
        $pair->defineOwnProperty('privateKey', PropertyDescriptor::data($privateKey, true, true, true));
        return $pair;
    }

    /**
     * @param list<string> $usages
     * @return array{0:list<string>, 1:list<string>}
     */
    private static function splitKeyPairUsages(string $algName, array $usages): array
    {
        $publicAllowed = match ($algName) {
            'RSA-OAEP' => ['encrypt', 'wrapKey'],
            'RSA-PSS', 'RSASSA-PKCS1-v1_5', 'ECDSA' => ['verify'],
            'ECDH' => [],
            default => [],
        };
        $privateAllowed = match ($algName) {
            'RSA-OAEP' => ['decrypt', 'unwrapKey'],
            'RSA-PSS', 'RSASSA-PKCS1-v1_5', 'ECDSA' => ['sign'],
            'ECDH' => ['deriveBits', 'deriveKey'],
            default => [],
        };
        $pub = [];
        $priv = [];
        foreach ($usages as $u) {
            if (in_array($u, $publicAllowed, true)) {
                $pub[] = $u;
            }
            if (in_array($u, $privateAllowed, true)) {
                $priv[] = $u;
            }
        }
        return [$pub, $priv];
    }

    private static function keyAlgHashName(JsObject $key): string
    {
        $alg = $key->getInternalProperty('[[KeyAlgorithm]]');
        if (is_array($alg) && isset($alg['hash']['name'])) {
            return strtoupper((string) $alg['hash']['name']);
        }
        throw new TypeError('Key algorithm missing hash');
    }

    private static function keyAlgNamedCurve(JsObject $key): string
    {
        $alg = $key->getInternalProperty('[[KeyAlgorithm]]');
        if (is_array($alg) && isset($alg['namedCurve'])) {
            return strtoupper((string) $alg['namedCurve']);
        }
        throw new TypeError('Key algorithm missing namedCurve');
    }

    private static function extractSaltLength(JsValue $alg, string $hash): int
    {
        $default = self::hashOutputSize($hash);
        if (!$alg instanceof JsObject) {
            return $default;
        }
        if (!$alg->has('saltLength')) {
            return $default;
        }
        $v = $alg->get('saltLength');
        if ($v instanceof JsUndefined) {
            return $default;
        }
        return (int) TypeConversion::toNumber($v);
    }

    private static function opensslHashAlgo(string $hash): int
    {
        return match ($hash) {
            'SHA-1' => OPENSSL_ALGO_SHA1,
            'SHA-256' => OPENSSL_ALGO_SHA256,
            'SHA-384' => OPENSSL_ALGO_SHA384,
            'SHA-512' => OPENSSL_ALGO_SHA512,
            default => throw new TypeError("Unsupported hash: {$hash}"),
        };
    }

    private static function phpHashName(string $hash): string
    {
        return match ($hash) {
            'SHA-1' => 'sha1',
            'SHA-256' => 'sha256',
            'SHA-384' => 'sha384',
            'SHA-512' => 'sha512',
            default => throw new TypeError("Unsupported hash: {$hash}"),
        };
    }

    private static function ensurePhpHash(string $hash): void
    {
        self::phpHashName($hash);
    }

    private static function hashOutputSize(string $hash): int
    {
        return match ($hash) {
            'SHA-1' => 20,
            'SHA-256' => 32,
            'SHA-384' => 48,
            'SHA-512' => 64,
            default => throw new TypeError("Unsupported hash: {$hash}"),
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
        if (isset($algorithm['modulusLength'])) {
            $algObj->defineOwnProperty('modulusLength', PropertyDescriptor::data(
                JsNumber::of((float) $algorithm['modulusLength']),
                true,
                true,
                true,
            ));
        }
        if (isset($algorithm['namedCurve'])) {
            $algObj->defineOwnProperty('namedCurve', PropertyDescriptor::data(
                new JsString((string) $algorithm['namedCurve']),
                true,
                true,
                true,
            ));
        }
        if (isset($algorithm['publicExponent']) && is_string($algorithm['publicExponent'])) {
            $expBytes = $algorithm['publicExponent'];
            $u8 = new \Phasis\Value\JsArray();
            $idx = 0;
            $len = strlen($expBytes);
            for ($k = 0; $k < $len; $k++) {
                $u8->defineOwnProperty((string) $idx, PropertyDescriptor::data(
                    JsNumber::of((float) ord($expBytes[$k])),
                    true,
                    true,
                    true,
                ));
                $idx++;
            }
            $u8->setLength($idx);
            $algObj->defineOwnProperty('publicExponent', PropertyDescriptor::data(
                $u8,
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
