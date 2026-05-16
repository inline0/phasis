<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsValue;

/**
 * WebCrypto `crypto` global — `Crypto` interface.
 *
 * Ships:
 *   - `crypto.getRandomValues(typedArray)` — fills with CSPRNG bytes
 *     from PHP's `random_bytes()`. Returns the same TypedArray.
 *   - `crypto.randomUUID()` — RFC 4122 v4 (random) UUID. Per spec,
 *     16 cryptographically random bytes with the variant + version
 *     bits set, formatted xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
 *     where y is one of 8/9/A/B.
 *   - `crypto.subtle` — SubtleCrypto interface (see SubtleCryptoObject).
 *
 * Web spec restricts `getRandomValues` to integer typed arrays (no
 * Float32/Float64). Buffer size is capped at 65,536 bytes per call
 * (browser convention; some implementations enforce it as a
 * QuotaExceededError). We honor the cap.
 */
final class CryptoObject
{
    /** Spec-mandated max bytes per getRandomValues call. */
    private const QUOTA_BYTES = 65_536;

    public static function install(Environment $env): void
    {
        $crypto = new JsObject();

        $crypto->defineOwnProperty(
            'getRandomValues',
            PropertyDescriptor::data(
                JsFunction::fromCallable('getRandomValues', self::getRandomValuesImpl($env), 1),
                true,
                false,
                true,
            ),
        );

        $crypto->defineOwnProperty(
            'randomUUID',
            PropertyDescriptor::data(
                JsFunction::fromCallable('randomUUID', self::randomUuidImpl(), 0),
                true,
                false,
                true,
            ),
        );

        // crypto.subtle — pull from the SubtleCrypto singleton. Defined
        // as a getter so the property reads from the same instance
        // every time.
        $subtle = SubtleCryptoObject::create();
        $crypto->defineOwnProperty(
            'subtle',
            PropertyDescriptor::data($subtle, false, false, true),
        );

        $env->defineVar('crypto', $crypto);
    }

    private static function getRandomValuesImpl(Environment $env): \Closure
    {
        return static function (JsValue $this_, array $args) use ($env): JsValue {
            unset($this_);
            $array = $args[0] ?? null;
            // Spec: parameter must be an integer typed array. Other
            // ArrayBufferView shapes (DataView, Float*Array) throw
            // TypeMismatchError per the Web Crypto spec; anything
            // that isn't an ArrayBufferView at all throws TypeError.
            if ($array instanceof \Phasis\Value\JsDataView) {
                throw new \Phasis\Exceptions\JsThrowable(
                    DomExceptionConstructor::create(
                        $env,
                        "Failed to execute 'getRandomValues' on 'Crypto': "
                        . 'The provided ArrayBufferView is of type DataView, '
                        . 'which is not an integer array type.',
                        'TypeMismatchError',
                    ),
                );
            }
            if (!$array instanceof JsTypedArray) {
                throw new TypeError(
                    "Failed to execute 'getRandomValues' on 'Crypto': "
                    . 'parameter 1 is not of type ArrayBufferView',
                );
            }
            // Per spec, Float16Array / Float32Array / Float64Array are
            // NOT supported (TypeMismatchError, a DOMException).
            $kind = $array->getTypeName();
            if ($kind === 'Float16Array' || $kind === 'Float32Array' || $kind === 'Float64Array') {
                throw new \Phasis\Exceptions\JsThrowable(
                    DomExceptionConstructor::create(
                        $env,
                        "Failed to execute 'getRandomValues' on 'Crypto': "
                        . 'The provided ArrayBufferView is of type ' . $kind
                        . ', not a Uint8Array / Int8Array / Uint8ClampedArray / Int16Array '
                        . '/ Uint16Array / Int32Array / Uint32Array / BigInt64Array / BigUint64Array.',
                        'TypeMismatchError',
                    ),
                );
            }
            $byteLength = $array->getLength() * $array->getBytesPerElement();
            if ($byteLength > self::QUOTA_BYTES) {
                // Per spec, throw QuotaExceededError (a DOMException),
                // not TypeError — WPT explicitly checks the exception
                // name.
                throw new \Phasis\Exceptions\JsThrowable(
                    DomExceptionConstructor::create(
                        $env,
                        "Failed to execute 'getRandomValues' on 'Crypto': "
                        . 'The ArrayBufferView byte length (' . $byteLength
                        . ') exceeds the number of bytes of entropy available '
                        . 'via this API (' . self::QUOTA_BYTES . ').',
                        'QuotaExceededError',
                    ),
                );
            }
            if ($byteLength === 0) {
                return $array;
            }
            $bytes = random_bytes($byteLength);
            $buf = $array->getBuffer();
            $buf->writeBytes($array->getByteOffset(), $bytes);
            return $array;
        };
    }

    private static function randomUuidImpl(): \Closure
    {
        return static function (JsValue $this_, array $args): JsValue {
            unset($this_, $args);
            $bytes = random_bytes(16);
            // RFC 4122 §4.4: set version (4) and variant (10) bits.
            $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
            $hex = bin2hex($bytes);
            $uuid = substr($hex, 0, 8)
                . '-' . substr($hex, 8, 4)
                . '-' . substr($hex, 12, 4)
                . '-' . substr($hex, 16, 4)
                . '-' . substr($hex, 20, 12);
            return new JsString($uuid);
        };
    }
}
