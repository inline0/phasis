<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG TextEncoder built-in.
 *
 * Per https://encoding.spec.whatwg.org/#textencoder, TextEncoder is UTF-8
 * only. The constructor takes no arguments. The `encoding` getter always
 * returns "utf-8". `encode(string)` returns a fresh Uint8Array of UTF-8
 * bytes; lone surrogates are emitted as U+FFFD per spec. `encodeInto`
 * fills an existing Uint8Array and stops at the buffer boundary without
 * producing a partial code point.
 *
 * Phasis stores strings internally as UTF-8 with CESU-8 3-byte encoding
 * for lone surrogates. The encode loop converts each CESU-8 lone
 * surrogate to U+FFFD (3 bytes: EF BF BD) and passes through all other
 * bytes verbatim.
 */
class TextEncoderConstructor
{
    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable(
            'TextEncoder',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor TextEncoder requires 'new'");
                }

                // Per spec OrdinaryCreateFromConstructor: take prototype from newTarget.
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : $proto;
                    $this_->setPrototype($useProto);
                }

                $this_->setInternalProperty('[[Encoding]]', 'utf-8');
                $this_->setInternalProperty('[[IsTextEncoder]]', true);
                return $this_;
            },
            0,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );

        $env->defineVar('TextEncoder', $constructor);
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        // encoding getter: always returns "utf-8".
        $encodingGetter = JsFunction::fromCallable(
            'get encoding',
            function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsTextEncoder]]') !== true) {
                    throw new TypeError("'get encoding' called on an object that is not a TextEncoder");
                }
                return new JsString('utf-8');
            },
            0,
        );
        $proto->defineOwnProperty(
            'encoding',
            PropertyDescriptor::accessor($encodingGetter, null, false, true),
        );

        // encode(input = ""): Uint8Array.
        $encodeFn = JsFunction::fromCallable(
            'encode',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsTextEncoder]]') !== true) {
                    throw new TypeError("'encode' called on an object that is not a TextEncoder");
                }
                $input = $args[0] ?? JsUndefined::instance();
                $str = ($input instanceof JsUndefined) ? '' : TypeConversion::toString($input);
                $bytes = self::stringToUtf8Bytes($str);
                return self::makeUint8Array($bytes);
            },
            1,
        );
        $proto->defineOwnProperty(
            'encode',
            PropertyDescriptor::data($encodeFn, true, false, true),
        );

        // encodeInto(source, destination): {read, written}.
        $encodeIntoFn = JsFunction::fromCallable(
            'encodeInto',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsTextEncoder]]') !== true) {
                    throw new TypeError("'encodeInto' called on an object that is not a TextEncoder");
                }
                $sourceVal = $args[0] ?? JsUndefined::instance();
                $destVal = $args[1] ?? JsUndefined::instance();

                $str = TypeConversion::toString($sourceVal);

                if (!$destVal instanceof JsTypedArray || $destVal->getTypeName() !== 'Uint8Array') {
                    throw new TypeError(
                        "TextEncoder.encodeInto: destination must be a Uint8Array"
                    );
                }
                $destVal->validateNotDetached();

                [$read, $written] = self::encodeIntoBuffer($str, $destVal);

                $result = new JsObject();
                $result->defineOwnProperty(
                    'read',
                    PropertyDescriptor::data(JsNumber::of((float) $read), true, true, true),
                );
                $result->defineOwnProperty(
                    'written',
                    PropertyDescriptor::data(JsNumber::of((float) $written), true, true, true),
                );
                return $result;
            },
            2,
        );
        $proto->defineOwnProperty(
            'encodeInto',
            PropertyDescriptor::data($encodeIntoFn, true, false, true),
        );

        // Symbol.toStringTag = "TextEncoder".
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('TextEncoder'), false, false, true),
        );

        return $proto;
    }

    /**
     * Convert a Phasis internal string (UTF-8 + CESU-8 lone surrogates) to
     * canonical UTF-8 bytes per the WHATWG encoder algorithm: each lone
     * surrogate is replaced by U+FFFD (EF BF BD). All other byte sequences
     * pass through unchanged.
     */
    public static function stringToUtf8Bytes(string $str): string
    {
        $len = strlen($str);
        $out = '';
        $i = 0;
        while ($i < $len) {
            $b = ord($str[$i]);
            // CESU-8 lone surrogate detection: ED A0-BF xx (D800-DFFF range).
            if (
                $b === 0xED
                && $i + 2 < $len
                && (ord($str[$i + 1]) & 0xE0) === 0xA0
            ) {
                // Replace lone surrogate with U+FFFD.
                $out .= "\xEF\xBF\xBD";
                $i += 3;
                continue;
            }
            // ASCII fast path.
            if ($b < 0x80) {
                $out .= $str[$i];
                $i += 1;
                continue;
            }
            // 2-byte sequence (C0-DF).
            if (($b & 0xE0) === 0xC0) {
                if ($i + 1 < $len) {
                    $out .= $str[$i] . $str[$i + 1];
                    $i += 2;
                } else {
                    $out .= "\xEF\xBF\xBD";
                    $i += 1;
                }
                continue;
            }
            // 3-byte sequence (E0-EF), surrogates already handled above.
            if (($b & 0xF0) === 0xE0) {
                if ($i + 2 < $len) {
                    $out .= $str[$i] . $str[$i + 1] . $str[$i + 2];
                    $i += 3;
                } else {
                    $out .= "\xEF\xBF\xBD";
                    $i += 1;
                }
                continue;
            }
            // 4-byte sequence (F0-F7).
            if (($b & 0xF8) === 0xF0) {
                if ($i + 3 < $len) {
                    $out .= $str[$i] . $str[$i + 1] . $str[$i + 2] . $str[$i + 3];
                    $i += 4;
                } else {
                    $out .= "\xEF\xBF\xBD";
                    $i += 1;
                }
                continue;
            }
            // Invalid leading byte; emit replacement.
            $out .= "\xEF\xBF\xBD";
            $i += 1;
        }
        return $out;
    }

    /**
     * encodeInto inner loop: fill the destination Uint8Array with UTF-8
     * bytes from $str, advancing through the source's UTF-16 code units.
     *
     * Returns [readCodeUnits, writtenBytes]. Stops at the buffer boundary
     * without emitting a partial code point.
     *
     * @return array{int,int}
     */
    private static function encodeIntoBuffer(string $str, JsTypedArray $dest): array
    {
        $buffer = $dest->getBuffer();
        $offset = $dest->getByteOffset();
        $capacity = $dest->getLength();

        $len = strlen($str);
        $read = 0;
        $written = 0;
        $i = 0;

        while ($i < $len) {
            $b = ord($str[$i]);

            // CESU-8 lone surrogate -> U+FFFD (3 bytes), 1 code unit read.
            if (
                $b === 0xED
                && $i + 2 < $len
                && (ord($str[$i + 1]) & 0xE0) === 0xA0
            ) {
                if ($written + 3 > $capacity) {
                    break;
                }
                $buffer->writeBytes($offset + $written, "\xEF\xBF\xBD");
                $written += 3;
                $read += 1;
                $i += 3;
                continue;
            }

            if ($b < 0x80) {
                if ($written + 1 > $capacity) {
                    break;
                }
                $buffer->writeBytes($offset + $written, $str[$i]);
                $written += 1;
                $read += 1;
                $i += 1;
                continue;
            }
            if (($b & 0xE0) === 0xC0 && $i + 1 < $len) {
                if ($written + 2 > $capacity) {
                    break;
                }
                $buffer->writeBytes($offset + $written, substr($str, $i, 2));
                $written += 2;
                $read += 1; // BMP <= U+07FF == 1 UTF-16 code unit.
                $i += 2;
                continue;
            }
            if (($b & 0xF0) === 0xE0 && $i + 2 < $len) {
                if ($written + 3 > $capacity) {
                    break;
                }
                $buffer->writeBytes($offset + $written, substr($str, $i, 3));
                $written += 3;
                $read += 1; // BMP -> 1 UTF-16 code unit.
                $i += 3;
                continue;
            }
            if (($b & 0xF8) === 0xF0 && $i + 3 < $len) {
                if ($written + 4 > $capacity) {
                    break;
                }
                $buffer->writeBytes($offset + $written, substr($str, $i, 4));
                $written += 4;
                $read += 2; // Supplementary plane -> surrogate pair (2 UTF-16 code units).
                $i += 4;
                continue;
            }
            // Invalid leading byte: emit replacement and advance one source byte.
            if ($written + 3 > $capacity) {
                break;
            }
            $buffer->writeBytes($offset + $written, "\xEF\xBF\xBD");
            $written += 3;
            $read += 1;
            $i += 1;
        }

        return [$read, $written];
    }

    /**
     * Build a fresh Uint8Array over a new ArrayBuffer containing $bytes.
     * Resolves the current realm's Uint8Array.prototype so the result
     * carries the right prototype chain even when called cross-realm.
     */
    public static function makeUint8Array(string $bytes): JsTypedArray
    {
        $length = strlen($bytes);
        $proto = self::resolveUint8ArrayPrototype();
        $ta = JsTypedArray::fromLength('Uint8Array', $length, $proto);
        if ($length > 0) {
            $ta->getBuffer()->writeBytes($ta->getByteOffset(), $bytes);
        }
        return $ta;
    }

    private static function resolveUint8ArrayPrototype(): ?JsObject
    {
        $realm = \Phasis\Engine::getCurrentRealm();
        if ($realm === null) {
            return null;
        }
        $env = $realm->getGlobalEnv();
        if (!$env->has('Uint8Array')) {
            return null;
        }
        $ctor = $env->get('Uint8Array');
        if (!$ctor instanceof JsObject) {
            return null;
        }
        $proto = $ctor->get('prototype');
        return $proto instanceof JsObject ? $proto : null;
    }
}
