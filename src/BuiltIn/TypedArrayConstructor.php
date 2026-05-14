<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\SyntaxError;
use Phasis\Exceptions\TypeError;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsDataView;
use Phasis\Value\JsProxy;
use Phasis\Value\JsSharedArrayBuffer;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\Object\PropertyDescriptor;

/**
 * Installs ArrayBuffer, DataView, and all TypedArray constructors.
 *
 * Each typed array constructor (Int8Array, Uint8Array, etc.) follows the same
 * pattern: accepts a length, another typed array, an ArrayBuffer, or an
 * array-like source.
 */
class TypedArrayConstructor
{
    use TypedArray\BufferTypes;
    use TypedArray\DataView;
    use TypedArray\Uint8Methods;
    use TypedArray\PrototypeMethods;
    use TypedArray\TypedArrayHelpers;


    public static function install(Environment $env): void
    {
        self::installArrayBuffer($env);
        self::installSharedArrayBuffer($env);
        self::installDataView($env);
        self::installTypedArrays($env);
    }












    /**
     * Install Uint8Array-specific base64/hex static and prototype methods.
     *
     * These methods are defined in the ECMAScript proposal for Uint8Array base64/hex
     * encoding and are only available on Uint8Array, not other typed array types.
     */
    private static function installUint8ArrayMethods(JsFunction $constructor, JsObject $proto): void
    {
        // Uint8Array.fromBase64(string, options?).
        // Per spec, the result is always a plain Uint8Array (ignores receiver).
        $fromBase64Fn = JsFunction::fromCallable(
            'fromBase64',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                $strVal = $args[0] ?? JsUndefined::instance();
                if (!$strVal instanceof JsString) {
                    throw new TypeError('Uint8Array.fromBase64: first argument must be a string');
                }
                $str = $strVal->toJsString();
                [$alphabet, $lastChunkHandling] = self::parseBase64Options($args[1] ?? JsUndefined::instance());
                $read = 0;
                $bytes = self::decodeBase64($str, $alphabet, $lastChunkHandling, null, $read);
                $ta = JsTypedArray::fromLength('Uint8Array', count($bytes), $proto);
                foreach ($bytes as $i => $b) {
                    $ta->setIndex($i, JsNumber::of((float) $b));
                }
                return $ta;
            },
            1,
        );
        $constructor->defineOwnProperty(
            'fromBase64',
            PropertyDescriptor::data($fromBase64Fn, true, false, true),
        );

        // Uint8Array.fromHex(string).
        // Per spec, the result is always a plain Uint8Array (ignores receiver).
        $fromHexFn = JsFunction::fromCallable(
            'fromHex',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                $strVal = $args[0] ?? JsUndefined::instance();
                if (!$strVal instanceof JsString) {
                    throw new TypeError('Uint8Array.fromHex: first argument must be a string');
                }
                $str = $strVal->toJsString();
                $read = 0;
                $bytes = self::decodeHex($str, null, $read);
                $ta = JsTypedArray::fromLength('Uint8Array', count($bytes), $proto);
                foreach ($bytes as $i => $b) {
                    $ta->setIndex($i, JsNumber::of((float) $b));
                }
                return $ta;
            },
            1,
        );
        $constructor->defineOwnProperty(
            'fromHex',
            PropertyDescriptor::data($fromHexFn, true, false, true),
        );

        // Uint8Array.prototype.toBase64(options?).
        $toBase64Fn = JsFunction::fromCallable(
            'toBase64',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsTypedArray || $this_->getTypeName() !== 'Uint8Array') {
                    throw new TypeError(
                        'Uint8Array.prototype.toBase64 called on incompatible receiver'
                    );
                }

                $optVal = $args[0] ?? JsUndefined::instance();
                $alphabet = 'base64';
                $omitPadding = false;

                if ($optVal instanceof JsObject) {
                    $alphabetVal = $optVal->get('alphabet');
                    if (!$alphabetVal instanceof JsUndefined) {
                        if (!$alphabetVal instanceof JsString) {
                            throw new TypeError(
                                "Uint8Array.prototype.toBase64: 'alphabet' option must be a string"
                            );
                        }
                        $alphabet = $alphabetVal->toJsString();
                        if ($alphabet !== 'base64' && $alphabet !== 'base64url') {
                            throw new TypeError(
                                "Uint8Array.prototype.toBase64: 'alphabet' must be 'base64' or 'base64url'"
                            );
                        }
                    }
                    $omitPaddingVal = $optVal->get('omitPadding');
                    if (!$omitPaddingVal instanceof JsUndefined) {
                        $omitPadding = TypeConversion::toBoolean($omitPaddingVal);
                    }
                }
                // Per spec: detachedness check fires AFTER option reads so
                // a getter that detaches the buffer still triggers TypeError.
                $this_->validateNotDetached();

                $len = $this_->getLength();
                $bin = '';
                for ($i = 0; $i < $len; $i++) {
                    $bin .= chr((int) TypeConversion::toNumber($this_->getIndex($i)));
                }

                $encoded = base64_encode($bin);

                if ($alphabet === 'base64url') {
                    $encoded = strtr($encoded, '+/', '-_');
                }

                if ($omitPadding) {
                    $encoded = rtrim($encoded, '=');
                }

                return new JsString($encoded);
            },
            0,
        );
        $proto->defineOwnProperty(
            'toBase64',
            PropertyDescriptor::data($toBase64Fn, true, false, true),
        );

        // Uint8Array.prototype.toHex().
        $toHexFn = JsFunction::fromCallable(
            'toHex',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsTypedArray || $this_->getTypeName() !== 'Uint8Array') {
                    throw new TypeError(
                        'Uint8Array.prototype.toHex called on incompatible receiver'
                    );
                }
                $this_->validateNotDetached();
                $len = $this_->getLength();
                $hex = '';
                for ($i = 0; $i < $len; $i++) {
                    $hex .= sprintf('%02x', (int) TypeConversion::toNumber($this_->getIndex($i)));
                }
                return new JsString($hex);
            },
            0,
        );
        $proto->defineOwnProperty(
            'toHex',
            PropertyDescriptor::data($toHexFn, true, false, true),
        );

        // Uint8Array.prototype.setFromBase64(string, options?).
        // Writes decoded bytes directly to the target, chunk by chunk. On error,
        // previously written complete chunks remain; the partial/erroneous chunk
        // is not written. Stops gracefully when the target buffer is full.
        $setFromBase64Fn = JsFunction::fromCallable(
            'setFromBase64',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsTypedArray || $this_->getTypeName() !== 'Uint8Array') {
                    throw new TypeError(
                        'Uint8Array.prototype.setFromBase64 called on incompatible receiver'
                    );
                }
                $this_->validateNotDetached();

                $strVal = $args[0] ?? JsUndefined::instance();
                if (!$strVal instanceof JsString) {
                    throw new TypeError(
                        'Uint8Array.prototype.setFromBase64: first argument must be a string'
                    );
                }
                $str = $strVal->toJsString();
                [$alphabet, $lastChunkHandling] = self::parseBase64Options(
                    $args[1] ?? JsUndefined::instance()
                );
                $this_->validateNotDetached();

                if ($alphabet === 'base64url') {
                    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
                } else {
                    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
                }
                $lookup = array_flip(str_split($chars));

                $targetLen = $this_->getLength();
                $written = 0;
                $readCount = 0;
                $inputLen = strlen($str);
                $i = 0;
                $chunk = [];
                $chunkStartPos = 0;
                $pendingError = null;

                while ($i < $inputLen) {
                    $c = $str[$i];
                    $ord = ord($c);
                    // Skip ASCII whitespace.
                    if ($ord === 0x20 || $ord === 0x09 || $ord === 0x0A || $ord === 0x0C || $ord === 0x0D) {
                        $i++;
                        continue;
                    }
                    if ($c === '=') {
                        break;
                    }
                    if (!isset($lookup[$c])) {
                        $pendingError = new SyntaxError("Invalid character in base64 string: '{$c}'");
                        break;
                    }
                    if (count($chunk) === 0) {
                        $chunkStartPos = $i;
                    }
                    $chunk[] = $lookup[$c];
                    $i++;

                    if (count($chunk) === 4) {
                        $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                        $b1 = (($chunk[1] & 0x0F) << 4) | ($chunk[2] >> 2);
                        $b2 = (($chunk[2] & 0x03) << 6) | $chunk[3];

                        // Per spec: stop before a full 3-byte chunk that won't fit entirely.
                        if ($written + 3 > $targetLen) {
                            $readCount = $chunkStartPos;
                            $result = new JsObject();
                            $result->set('read', JsNumber::of((float) $readCount));
                            $result->set('written', JsNumber::of((float) $written));
                            return $result;
                        }

                        $this_->setIndex($written, JsNumber::of((float) $b0));
                        $this_->setIndex($written + 1, JsNumber::of((float) $b1));
                        $this_->setIndex($written + 2, JsNumber::of((float) $b2));
                        $written += 3;
                        $chunk = [];
                        $readCount = $i;
                    }
                }

                if ($pendingError !== null) {
                    throw $pendingError;
                }

                // Handle padding.
                $padStart = $i;
                $padCount = 0;
                while ($i < $inputLen && $str[$i] === '=') {
                    $padCount++;
                    $i++;
                }

                // After padding, only whitespace is allowed.
                while ($i < $inputLen) {
                    $c = $str[$i];
                    $ord = ord($c);
                    if ($ord === 0x20 || $ord === 0x09 || $ord === 0x0A || $ord === 0x0C || $ord === 0x0D) {
                        $i++;
                        continue;
                    }
                    throw new SyntaxError("Unexpected character after base64 data: '{$c}'");
                }

                $chunkLen = count($chunk);

                if ($chunkLen === 0) {
                    $readCount = $inputLen;
                    $result = new JsObject();
                    $result->set('read', JsNumber::of((float) $readCount));
                    $result->set('written', JsNumber::of((float) $written));
                    return $result;
                }

                if ($chunkLen === 2 && $padCount > 2) {
                    throw new SyntaxError('Invalid base64 padding');
                }
                if ($chunkLen === 3 && $padCount > 1) {
                    throw new SyntaxError('Invalid base64 padding');
                }

                if ($chunkLen === 1) {
                    if ($lastChunkHandling === 'stop-before-partial') {
                        $readCount = $chunkStartPos;
                        $result = new JsObject();
                        $result->set('read', JsNumber::of((float) $readCount));
                        $result->set('written', JsNumber::of((float) $written));
                        return $result;
                    }
                    throw new SyntaxError('Invalid base64: incomplete final chunk');
                }

                if ($chunkLen === 2) {
                    $hasNonZeroTrailing = ($chunk[1] & 0x0F) !== 0;
                    if ($padCount === 1) {
                        if ($lastChunkHandling === 'stop-before-partial') {
                            $readCount = $chunkStartPos;
                            $result = new JsObject();
                            $result->set('read', JsNumber::of((float) $readCount));
                            $result->set('written', JsNumber::of((float) $written));
                            return $result;
                        }
                        throw new SyntaxError('Invalid base64: partial padding in final chunk');
                    }
                    if ($padCount === 0) {
                        if ($lastChunkHandling === 'stop-before-partial') {
                            $readCount = $chunkStartPos;
                            $result = new JsObject();
                            $result->set('read', JsNumber::of((float) $readCount));
                            $result->set('written', JsNumber::of((float) $written));
                            return $result;
                        }
                        if ($lastChunkHandling === 'strict') {
                            throw new SyntaxError('Invalid base64: missing padding in final chunk');
                        }
                        $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                        if ($written < $targetLen) {
                            $this_->setIndex($written, JsNumber::of((float) $b0));
                            $written++;
                        }
                        $readCount = $padStart;
                        $result = new JsObject();
                        $result->set('read', JsNumber::of((float) $readCount));
                        $result->set('written', JsNumber::of((float) $written));
                        return $result;
                    }
                    // $padCount === 2: correct.
                    if ($lastChunkHandling === 'strict' && $hasNonZeroTrailing) {
                        throw new SyntaxError('Invalid base64: non-zero padding bits in final chunk');
                    }
                    $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                    if ($written < $targetLen) {
                        $this_->setIndex($written, JsNumber::of((float) $b0));
                        $written++;
                    }
                    $readCount = $i;
                    $result = new JsObject();
                    $result->set('read', JsNumber::of((float) $readCount));
                    $result->set('written', JsNumber::of((float) $written));
                    return $result;
                }

                // $chunkLen === 3: 3 chars encode 2 bytes.
                $hasNonZeroTrailing = ($chunk[2] & 0x03) !== 0;
                if ($padCount === 0) {
                    if ($lastChunkHandling === 'stop-before-partial') {
                        $readCount = $chunkStartPos;
                        $result = new JsObject();
                        $result->set('read', JsNumber::of((float) $readCount));
                        $result->set('written', JsNumber::of((float) $written));
                        return $result;
                    }
                    if ($lastChunkHandling === 'strict') {
                        throw new SyntaxError('Invalid base64: missing padding in final chunk');
                    }
                    // loose: if not all 2 bytes fit, stop before the whole chunk.
                    if ($written + 2 > $targetLen) {
                        $readCount = $chunkStartPos;
                        $result = new JsObject();
                        $result->set('read', JsNumber::of((float) $readCount));
                        $result->set('written', JsNumber::of((float) $written));
                        return $result;
                    }
                    $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                    $b1 = (($chunk[1] & 0x0F) << 4) | ($chunk[2] >> 2);
                    $this_->setIndex($written, JsNumber::of((float) $b0));
                    $written++;
                    $this_->setIndex($written, JsNumber::of((float) $b1));
                    $written++;
                    $readCount = $padStart;
                    $result = new JsObject();
                    $result->set('read', JsNumber::of((float) $readCount));
                    $result->set('written', JsNumber::of((float) $written));
                    return $result;
                }
                if ($padCount > 1) {
                    throw new SyntaxError('Invalid base64 padding');
                }
                // $padCount === 1: correct padding.
                if ($lastChunkHandling === 'strict' && $hasNonZeroTrailing) {
                    throw new SyntaxError('Invalid base64: non-zero padding bits in final chunk');
                }
                // If not all 2 bytes fit, stop before the whole chunk.
                if ($written + 2 > $targetLen) {
                    $readCount = $chunkStartPos;
                    $result = new JsObject();
                    $result->set('read', JsNumber::of((float) $readCount));
                    $result->set('written', JsNumber::of((float) $written));
                    return $result;
                }
                $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                $b1 = (($chunk[1] & 0x0F) << 4) | ($chunk[2] >> 2);
                $this_->setIndex($written, JsNumber::of((float) $b0));
                $written++;
                $this_->setIndex($written, JsNumber::of((float) $b1));
                $written++;
                $readCount = $i;
                $result = new JsObject();
                $result->set('read', JsNumber::of((float) $readCount));
                $result->set('written', JsNumber::of((float) $written));
                return $result;
            },
            1,
        );
        $proto->defineOwnProperty(
            'setFromBase64',
            PropertyDescriptor::data($setFromBase64Fn, true, false, true),
        );

        // Uint8Array.prototype.setFromHex(string).
        // Writes decoded bytes directly to the target pair by pair. On error,
        // previously written valid pairs remain; the erroneous pair is not written.
        // Stops gracefully when the target buffer is full.
        $setFromHexFn = JsFunction::fromCallable(
            'setFromHex',
            function (JsValue $this_, array $args): JsValue {
                if (!$this_ instanceof JsTypedArray || $this_->getTypeName() !== 'Uint8Array') {
                    throw new TypeError(
                        'Uint8Array.prototype.setFromHex called on incompatible receiver'
                    );
                }
                $this_->validateNotDetached();

                $strVal = $args[0] ?? JsUndefined::instance();
                if (!$strVal instanceof JsString) {
                    throw new TypeError(
                        'Uint8Array.prototype.setFromHex: first argument must be a string'
                    );
                }
                $str = $strVal->toJsString();
                $inputLen = strlen($str);
                $targetLen = $this_->getLength();
                $written = 0;
                $readCount = 0;

                if ($inputLen % 2 !== 0) {
                    throw new SyntaxError('Uint8Array.setFromHex: input must have even length');
                }

                for ($i = 0; $i < $inputLen; $i += 2) {
                    if ($written >= $targetLen) {
                        $readCount = $i;
                        $result = new JsObject();
                        $result->set('read', JsNumber::of((float) $readCount));
                        $result->set('written', JsNumber::of((float) $written));
                        return $result;
                    }
                    $hi = $str[$i];
                    $lo = $str[$i + 1];
                    if (!ctype_xdigit($hi) || !ctype_xdigit($lo)) {
                        throw new SyntaxError("Invalid hex character in input: '{$hi}{$lo}'");
                    }
                    $this_->setIndex($written, JsNumber::of((float) hexdec($hi . $lo)));
                    $written++;
                    $readCount = $i + 2;
                }

                $result = new JsObject();
                $result->set('read', JsNumber::of((float) $readCount));
                $result->set('written', JsNumber::of((float) $written));
                return $result;
            },
            1,
        );
        $proto->defineOwnProperty(
            'setFromHex',
            PropertyDescriptor::data($setFromHexFn, true, false, true),
        );
    }

    /**
     * Parse base64 options object, returning [alphabet, lastChunkHandling].
     *
     * Options must be string-typed (not boxed String objects), matching the spec
     * which uses IsString rather than ToString coercion.
     *
     * @return array{string, string}
     */
    private static function parseBase64Options(JsValue $optVal): array
    {
        $alphabet = 'base64';
        $lastChunkHandling = 'loose';

        if ($optVal instanceof JsObject) {
            $alphabetVal = $optVal->get('alphabet');
            if (!$alphabetVal instanceof JsUndefined) {
                if (!$alphabetVal instanceof JsString) {
                    throw new TypeError(
                        "Uint8Array.fromBase64: 'alphabet' option must be a string"
                    );
                }
                $alphabet = $alphabetVal->toJsString();
                if ($alphabet !== 'base64' && $alphabet !== 'base64url') {
                    throw new TypeError(
                        "Uint8Array.fromBase64: 'alphabet' option must be 'base64' or 'base64url'"
                    );
                }
            }
            $lastChunkHandlingVal = $optVal->get('lastChunkHandling');
            if (!$lastChunkHandlingVal instanceof JsUndefined) {
                if (!$lastChunkHandlingVal instanceof JsString) {
                    throw new TypeError(
                        "Uint8Array.fromBase64: 'lastChunkHandling' option must be a string"
                    );
                }
                $lastChunkHandling = $lastChunkHandlingVal->toJsString();
                if (
                    $lastChunkHandling !== 'loose'
                    && $lastChunkHandling !== 'strict'
                    && $lastChunkHandling !== 'stop-before-partial'
                ) {
                    throw new TypeError(
                        "Uint8Array.fromBase64: 'lastChunkHandling' must be 'loose', 'strict',"
                        . " or 'stop-before-partial'"
                    );
                }
            }
        }

        return [$alphabet, $lastChunkHandling];
    }

    /**
     * Decode a base64 string into bytes per the ECMAScript Uint8Array.fromBase64 spec.
     *
     * Processes the input in 4-char base64 chunks. ASCII whitespace (space, tab, LF,
     * FF, CR) is silently skipped. Any other non-base64 character causes a SyntaxError.
     *
     * When $maxBytes is non-null, decoding stops once $maxBytes bytes would be produced,
     * used by setFromBase64 to respect the target array size.
     *
     * $readCount is set to the number of input characters consumed.
     *
     * @param int|null $maxBytes Maximum bytes to write (null = unlimited).
     * @param int $readCount Set to the number of source chars consumed.
     * @return int[] Array of decoded byte values.
     */
    private static function decodeBase64(
        string $input,
        string $alphabet,
        string $lastChunkHandling,
        ?int $maxBytes,
        int &$readCount,
    ): array {
        if ($alphabet === 'base64url') {
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        } else {
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        }
        $lookup = array_flip(str_split($chars));

        $bytes = [];
        $chunk = [];
        $readCount = 0;
        $inputLen = strlen($input);
        $i = 0;
        $chunkStartPos = 0;

        while ($i < $inputLen) {
            $c = $input[$i];
            $ord = ord($c);

            // ASCII whitespace: space, tab, LF, FF, CR.
            if ($ord === 0x20 || $ord === 0x09 || $ord === 0x0A || $ord === 0x0C || $ord === 0x0D) {
                $i++;
                continue;
            }

            if ($c === '=') {
                break;
            }

            if (!isset($lookup[$c])) {
                throw new SyntaxError("Invalid character in base64 string: '{$c}'");
            }

            if (count($chunk) === 0) {
                $chunkStartPos = $i;
            }
            $chunk[] = $lookup[$c];
            $i++;

            if (count($chunk) === 4) {
                $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                $b1 = (($chunk[1] & 0x0F) << 4) | ($chunk[2] >> 2);
                $b2 = (($chunk[2] & 0x03) << 6) | $chunk[3];

                if ($maxBytes !== null && count($bytes) + 3 > $maxBytes) {
                    $remaining = $maxBytes - count($bytes);
                    if ($remaining >= 1) {
                        $bytes[] = $b0;
                    }
                    if ($remaining >= 2) {
                        $bytes[] = $b1;
                    }
                    if ($remaining >= 3) {
                        $bytes[] = $b2;
                    }
                    $readCount = $i;
                    return $bytes;
                }

                $bytes[] = $b0;
                $bytes[] = $b1;
                $bytes[] = $b2;
                $chunk = [];
                $readCount = $i;
            }
        }

        // Handle padding characters at current position.
        $padStart = $i;
        $padCount = 0;
        while ($i < $inputLen && $input[$i] === '=') {
            $padCount++;
            $i++;
        }

        // After padding, only whitespace is allowed.
        while ($i < $inputLen) {
            $c = $input[$i];
            $ord = ord($c);
            if ($ord === 0x20 || $ord === 0x09 || $ord === 0x0A || $ord === 0x0C || $ord === 0x0D) {
                $i++;
                continue;
            }
            throw new SyntaxError("Unexpected character after base64 data: '{$c}'");
        }

        $chunkLen = count($chunk);

        if ($chunkLen === 0) {
            $readCount = $inputLen;
            return $bytes;
        }

        // Excess padding is always invalid.
        if ($chunkLen === 2 && $padCount > 2) {
            throw new SyntaxError('Invalid base64 padding');
        }
        if ($chunkLen === 3 && $padCount > 1) {
            throw new SyntaxError('Invalid base64 padding');
        }

        if ($chunkLen === 1) {
            // A single base64 char cannot represent any complete byte.
            if ($lastChunkHandling === 'stop-before-partial') {
                $readCount = $chunkStartPos;
                return $bytes;
            }
            throw new SyntaxError('Invalid base64: incomplete final chunk');
        }

        if ($chunkLen === 2) {
            // 2 chars encode 1 byte. Correct padding is '=='.
            $hasNonZeroTrailing = ($chunk[1] & 0x0F) !== 0;

            if ($padCount === 1) {
                // Partial padding (only one '='): invalid except stop-before-partial.
                if ($lastChunkHandling === 'stop-before-partial') {
                    $readCount = $chunkStartPos;
                    return $bytes;
                }
                throw new SyntaxError('Invalid base64: partial padding in final chunk');
            }

            if ($padCount === 0) {
                if ($lastChunkHandling === 'stop-before-partial') {
                    $readCount = $chunkStartPos;
                    return $bytes;
                }
                if ($lastChunkHandling === 'strict') {
                    throw new SyntaxError('Invalid base64: missing padding in final chunk');
                }
                // loose: decode ignoring trailing bits.
                $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
                if ($maxBytes === null || count($bytes) < $maxBytes) {
                    $bytes[] = $b0;
                }
                $readCount = $padStart;
                return $bytes;
            }

            // $padCount === 2: correct padding.
            if ($lastChunkHandling === 'strict' && $hasNonZeroTrailing) {
                throw new SyntaxError('Invalid base64: non-zero padding bits in final chunk');
            }
            $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
            if ($maxBytes === null || count($bytes) < $maxBytes) {
                $bytes[] = $b0;
            }
            $readCount = $i;
            return $bytes;
        }

        // $chunkLen === 3: 3 chars encode 2 bytes. Correct padding is '='.
        $hasNonZeroTrailing = ($chunk[2] & 0x03) !== 0;

        if ($padCount === 0) {
            if ($lastChunkHandling === 'stop-before-partial') {
                $readCount = $chunkStartPos;
                return $bytes;
            }
            if ($lastChunkHandling === 'strict') {
                throw new SyntaxError('Invalid base64: missing padding in final chunk');
            }
            // loose: decode ignoring trailing bits.
            $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
            $b1 = (($chunk[1] & 0x0F) << 4) | ($chunk[2] >> 2);
            $canWrite = $maxBytes === null ? 2 : min(2, $maxBytes - count($bytes));
            if ($canWrite >= 1) {
                $bytes[] = $b0;
            }
            if ($canWrite >= 2) {
                $bytes[] = $b1;
            }
            $readCount = $padStart;
            return $bytes;
        }

        // $padCount >= 1.
        if ($padCount > 1) {
            throw new SyntaxError('Invalid base64 padding');
        }

        // $padCount === 1: correct padding.
        if ($lastChunkHandling === 'strict' && $hasNonZeroTrailing) {
            throw new SyntaxError('Invalid base64: non-zero padding bits in final chunk');
        }
        $b0 = ($chunk[0] << 2) | ($chunk[1] >> 4);
        $b1 = (($chunk[1] & 0x0F) << 4) | ($chunk[2] >> 2);
        $canWrite = $maxBytes === null ? 2 : min(2, $maxBytes - count($bytes));
        if ($canWrite >= 1) {
            $bytes[] = $b0;
        }
        if ($canWrite >= 2) {
            $bytes[] = $b1;
        }
        $readCount = $i;
        return $bytes;
    }
}
