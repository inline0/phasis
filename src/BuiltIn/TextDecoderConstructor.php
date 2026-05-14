<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsDataView;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG TextDecoder built-in.
 *
 * Per https://encoding.spec.whatwg.org/#textdecoder. Supports utf-8,
 * utf-16le, and utf-16be (the three encodings the WHATWG conformance
 * suite exercises heavily). Legacy single-byte encodings are not
 * implemented in this pass.
 *
 * The decoder stores instance state on the JsObject's internal property
 * map:
 *   [[Encoding]]: canonical encoding name string
 *   [[Fatal]]: bool
 *   [[IgnoreBOM]]: bool
 *   [[PendingBytes]]: tail bytes held over from a streaming decode
 *   [[BomSeen]]: whether a leading BOM was consumed (UTF-8)
 *   [[IsTextDecoder]]: brand check tag
 */
class TextDecoderConstructor
{
    private const SUPPORTED = [
        // Label -> canonical name. See https://encoding.spec.whatwg.org/#concept-encoding-get.
        'utf-8' => 'utf-8',
        'utf8' => 'utf-8',
        'unicode-1-1-utf-8' => 'utf-8',
        'utf-16' => 'utf-16le',
        'utf-16le' => 'utf-16le',
        'utf-16be' => 'utf-16be',
    ];

    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable(
            'TextDecoder',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor TextDecoder requires 'new'");
                }

                $labelVal = $args[0] ?? JsUndefined::instance();
                $optionsVal = $args[1] ?? JsUndefined::instance();

                if ($labelVal instanceof JsUndefined) {
                    $label = 'utf-8';
                } else {
                    $label = TypeConversion::toString($labelVal);
                }
                $canonical = self::canonicalizeLabel($label);
                if ($canonical === null) {
                    throw new RangeError(
                        "TextDecoder constructor: '{$label}' is not a supported encoding"
                    );
                }

                $fatal = false;
                $ignoreBOM = false;
                if (!$optionsVal instanceof JsUndefined) {
                    if (!$optionsVal instanceof JsObject) {
                        throw new TypeError(
                            'TextDecoder constructor: options must be an object'
                        );
                    }
                    $fatalProp = $optionsVal->get('fatal');
                    if (!$fatalProp instanceof JsUndefined) {
                        $fatal = TypeConversion::toBoolean($fatalProp);
                    }
                    $ibProp = $optionsVal->get('ignoreBOM');
                    if (!$ibProp instanceof JsUndefined) {
                        $ignoreBOM = TypeConversion::toBoolean($ibProp);
                    }
                }

                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : $proto;
                    $this_->setPrototype($useProto);
                }

                $this_->setInternalProperty('[[IsTextDecoder]]', true);
                $this_->setInternalProperty('[[Encoding]]', $canonical);
                $this_->setInternalProperty('[[Fatal]]', $fatal);
                $this_->setInternalProperty('[[IgnoreBOM]]', $ignoreBOM);
                $this_->setInternalProperty('[[PendingBytes]]', '');
                $this_->setInternalProperty('[[BomSeen]]', false);
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

        $env->defineVar('TextDecoder', $constructor);
    }

    /**
     * Normalize a label per the WHATWG "get an encoding" algorithm: strip
     * leading/trailing ASCII whitespace, lowercase ASCII letters, then
     * match against the table. Returns null for unsupported labels.
     */
    private static function canonicalizeLabel(string $label): ?string
    {
        // ASCII whitespace per spec: U+0009, U+000A, U+000C, U+000D, U+0020.
        $trimmed = trim($label, "\t\n\f\r ");
        $lower = strtolower($trimmed);
        return self::SUPPORTED[$lower] ?? null;
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        $proto->defineOwnProperty(
            'encoding',
            PropertyDescriptor::accessor(
                JsFunction::fromCallable('get encoding', function (JsValue $this_): JsValue {
                    self::brandCheck($this_);
                    \assert($this_ instanceof JsObject);
                    $enc = $this_->getInternalProperty('[[Encoding]]');
                    return new JsString(\is_string($enc) ? $enc : 'utf-8');
                }, 0),
                null,
                false,
                true,
            ),
        );

        $proto->defineOwnProperty(
            'fatal',
            PropertyDescriptor::accessor(
                JsFunction::fromCallable('get fatal', function (JsValue $this_): JsValue {
                    self::brandCheck($this_);
                    \assert($this_ instanceof JsObject);
                    return JsBoolean::of((bool) $this_->getInternalProperty('[[Fatal]]'));
                }, 0),
                null,
                false,
                true,
            ),
        );

        $proto->defineOwnProperty(
            'ignoreBOM',
            PropertyDescriptor::accessor(
                JsFunction::fromCallable('get ignoreBOM', function (JsValue $this_): JsValue {
                    self::brandCheck($this_);
                    \assert($this_ instanceof JsObject);
                    return JsBoolean::of((bool) $this_->getInternalProperty('[[IgnoreBOM]]'));
                }, 0),
                null,
                false,
                true,
            ),
        );

        // decode(input?, options?): string.
        $decodeFn = JsFunction::fromCallable(
            'decode',
            function (JsValue $this_, array $args): JsValue {
                self::brandCheck($this_);
                \assert($this_ instanceof JsObject);

                $inputVal = $args[0] ?? JsUndefined::instance();
                $optsVal = $args[1] ?? JsUndefined::instance();

                $stream = false;
                if (!$optsVal instanceof JsUndefined) {
                    if (!$optsVal instanceof JsObject) {
                        throw new TypeError(
                            'TextDecoder.decode: options must be an object'
                        );
                    }
                    $sProp = $optsVal->get('stream');
                    if (!$sProp instanceof JsUndefined) {
                        $stream = TypeConversion::toBoolean($sProp);
                    }
                }

                $bytes = ($inputVal instanceof JsUndefined) ? '' : self::extractBytes($inputVal);

                $encoding = (string) $this_->getInternalProperty('[[Encoding]]');
                $fatal = (bool) $this_->getInternalProperty('[[Fatal]]');
                $ignoreBOM = (bool) $this_->getInternalProperty('[[IgnoreBOM]]');

                $pending = (string) $this_->getInternalProperty('[[PendingBytes]]');
                $bomSeen = (bool) $this_->getInternalProperty('[[BomSeen]]');

                $all = $pending . $bytes;

                // Strip BOM at the very start of the stream unless ignoreBOM.
                $offset = 0;
                if (!$bomSeen && !$ignoreBOM) {
                    $allLen = \strlen($all);
                    $isUtf8Bom = $encoding === 'utf-8'
                        && $allLen >= 3
                        && $all[0] === "\xEF"
                        && $all[1] === "\xBB"
                        && $all[2] === "\xBF";
                    $isUtf16LeBom = $encoding === 'utf-16le'
                        && $allLen >= 2
                        && $all[0] === "\xFF"
                        && $all[1] === "\xFE";
                    $isUtf16BeBom = $encoding === 'utf-16be'
                        && $allLen >= 2
                        && $all[0] === "\xFE"
                        && $all[1] === "\xFF";
                    if ($isUtf8Bom) {
                        $offset = 3;
                        $bomSeen = true;
                    } elseif ($isUtf16LeBom || $isUtf16BeBom) {
                        $offset = 2;
                        $bomSeen = true;
                    } else {
                        // Partial BOM at start of stream while streaming:
                        // hold the bytes until we can decide. Otherwise (non-stream)
                        // they are part of the data.
                        $partialUtf8 = $encoding === 'utf-8' && $allLen >= 1 && $all[0] === "\xEF";
                        $partialUtf16Le = $encoding === 'utf-16le' && $allLen >= 1 && $all[0] === "\xFF";
                        $partialUtf16Be = $encoding === 'utf-16be' && $allLen >= 1 && $all[0] === "\xFE";
                        if ($stream && ($partialUtf8 || $partialUtf16Le || $partialUtf16Be)) {
                            $this_->setInternalProperty('[[PendingBytes]]', $all);
                            return new JsString('');
                        }
                    }
                }

                $data = $offset > 0 ? \substr($all, $offset) : $all;
                $newPending = '';

                switch ($encoding) {
                    case 'utf-8':
                        $result = self::decodeUtf8($data, $fatal, $stream, $newPending);
                        break;
                    case 'utf-16le':
                        $result = self::decodeUtf16($data, true, $fatal, $stream, $newPending);
                        break;
                    case 'utf-16be':
                        $result = self::decodeUtf16($data, false, $fatal, $stream, $newPending);
                        break;
                    default:
                        throw new TypeError("TextDecoder: unsupported encoding '{$encoding}'");
                }

                if ($stream) {
                    $this_->setInternalProperty('[[PendingBytes]]', $newPending);
                    $this_->setInternalProperty('[[BomSeen]]', $bomSeen);
                } else {
                    // End of stream: reset for next call.
                    $this_->setInternalProperty('[[PendingBytes]]', '');
                    $this_->setInternalProperty('[[BomSeen]]', false);
                }

                return new JsString($result);
            },
            0,
        );
        $proto->defineOwnProperty(
            'decode',
            PropertyDescriptor::data($decodeFn, true, false, true),
        );

        // Symbol.toStringTag = "TextDecoder".
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('TextDecoder'), false, false, true),
        );

        return $proto;
    }

    private static function brandCheck(JsValue $this_): void
    {
        if (!$this_ instanceof JsObject || $this_->getInternalProperty('[[IsTextDecoder]]') !== true) {
            throw new TypeError(
                'TextDecoder method called on an object that is not a TextDecoder'
            );
        }
    }

    /**
     * Pull the underlying byte string out of a BufferSource (ArrayBuffer,
     * TypedArray, or DataView). Throws TypeError for other inputs.
     */
    private static function extractBytes(JsValue $input): string
    {
        if ($input instanceof JsTypedArray) {
            $input->validateNotDetached();
            $len = $input->getLength() * $input->getBytesPerElement();
            return $input->getBuffer()->readBytes($input->getByteOffset(), $len);
        }
        if ($input instanceof JsDataView) {
            return $input->getBuffer()->readBytes($input->getByteOffset(), $input->getByteLength());
        }
        if ($input instanceof JsArrayBuffer) {
            if ($input->isDetached()) {
                throw new TypeError('TextDecoder.decode: ArrayBuffer is detached');
            }
            return $input->readBytes(0, $input->getByteLength());
        }
        throw new TypeError(
            'TextDecoder.decode: input must be an ArrayBuffer, TypedArray, or DataView'
        );
    }

    /**
     * Decode a UTF-8 byte string into a Phasis internal string (UTF-8).
     *
     * Implements the WHATWG UTF-8 decoder. Invalid sequences are replaced
     * with U+FFFD (or throw TypeError when fatal). When $stream is true,
     * a trailing incomplete sequence is returned via $pendingOut and is
     * not emitted; on end-of-stream that incomplete tail produces a
     * single replacement char (or throws if fatal).
     */
    /**
     * @param-out string $pendingOut
     */
    private static function decodeUtf8(
        string $bytes,
        bool $fatal,
        bool $stream,
        string &$pendingOut
    ): string {
        $pendingOut = '';
        $len = \strlen($bytes);
        $out = '';
        $i = 0;

        while ($i < $len) {
            $b = \ord($bytes[$i]);

            if ($b < 0x80) {
                $out .= $bytes[$i];
                $i += 1;
                continue;
            }

            // Determine sequence length and minimum codepoint.
            $needed = 0;
            $lower = 0x80;
            $upper = 0xBF;
            $cp = 0;
            if (($b & 0xE0) === 0xC0) {
                if ($b < 0xC2) {
                    // Overlong / invalid leading byte.
                    if ($fatal) {
                        throw new TypeError('TextDecoder.decode: invalid UTF-8 byte sequence');
                    }
                    $out .= "\xEF\xBF\xBD";
                    $i += 1;
                    continue;
                }
                $needed = 1;
                $cp = $b & 0x1F;
            } elseif (($b & 0xF0) === 0xE0) {
                $needed = 2;
                $cp = $b & 0x0F;
                if ($b === 0xE0) {
                    $lower = 0xA0; // Reject overlong: cp must >= 0x800.
                } elseif ($b === 0xED) {
                    $upper = 0x9F; // Reject surrogates U+D800-U+DFFF.
                }
            } elseif (($b & 0xF8) === 0xF0) {
                if ($b > 0xF4) {
                    if ($fatal) {
                        throw new TypeError('TextDecoder.decode: invalid UTF-8 byte sequence');
                    }
                    $out .= "\xEF\xBF\xBD";
                    $i += 1;
                    continue;
                }
                $needed = 3;
                $cp = $b & 0x07;
                if ($b === 0xF0) {
                    $lower = 0x90;
                } elseif ($b === 0xF4) {
                    $upper = 0x8F;
                }
            } else {
                if ($fatal) {
                    throw new TypeError('TextDecoder.decode: invalid UTF-8 byte sequence');
                }
                $out .= "\xEF\xBF\xBD";
                $i += 1;
                continue;
            }

            // Check we have enough bytes left.
            if ($i + $needed >= $len) {
                // Incomplete tail.
                if ($stream) {
                    // Hold over for next chunk.
                    $pendingOut = \substr($bytes, $i);
                    return $out;
                }
                if ($fatal) {
                    throw new TypeError('TextDecoder.decode: incomplete UTF-8 byte sequence');
                }
                $out .= "\xEF\xBF\xBD";
                return $out;
            }

            // Validate continuation bytes.
            $ok = true;
            $secondByte = \ord($bytes[$i + 1]);
            if ($secondByte < $lower || $secondByte > $upper) {
                $ok = false;
            }
            if ($ok) {
                $cp = ($cp << 6) | ($secondByte & 0x3F);
                for ($k = 2; $k <= $needed; $k++) {
                    $nb = \ord($bytes[$i + $k]);
                    if ($nb < 0x80 || $nb > 0xBF) {
                        $ok = false;
                        break;
                    }
                    $cp = ($cp << 6) | ($nb & 0x3F);
                }
            }

            if (!$ok) {
                if ($fatal) {
                    throw new TypeError('TextDecoder.decode: invalid UTF-8 byte sequence');
                }
                $out .= "\xEF\xBF\xBD";
                // WHATWG: advance only past the invalid leading byte;
                // resume from the bad continuation.
                $i += 1;
                continue;
            }

            $out .= self::codepointToUtf8($cp);
            $i += $needed + 1;
        }

        return $out;
    }

    /**
     * Decode UTF-16 (LE or BE) into Phasis internal string. Lone
     * surrogates pass through as CESU-8 3-byte sequences (matching how
     * Phasis stores them); fatal mode rejects them.
     */
    /**
     * @param-out string $pendingOut
     */
    private static function decodeUtf16(
        string $bytes,
        bool $littleEndian,
        bool $fatal,
        bool $stream,
        string &$pendingOut
    ): string {
        $pendingOut = '';
        $len = \strlen($bytes);
        $out = '';
        $i = 0;
        $pendingHigh = -1;

        while ($i + 1 < $len) {
            $b0 = \ord($bytes[$i]);
            $b1 = \ord($bytes[$i + 1]);
            $cu = $littleEndian ? ($b0 | ($b1 << 8)) : (($b0 << 8) | $b1);
            $i += 2;

            if ($pendingHigh !== -1) {
                if ($cu >= 0xDC00 && $cu <= 0xDFFF) {
                    $cp = (($pendingHigh - 0xD800) << 10) + ($cu - 0xDC00) + 0x10000;
                    $out .= self::codepointToUtf8($cp);
                    $pendingHigh = -1;
                    continue;
                }
                // Lone high surrogate.
                if ($fatal) {
                    throw new TypeError('TextDecoder.decode: lone surrogate');
                }
                $out .= "\xEF\xBF\xBD";
                $pendingHigh = -1;
                // Fall through and handle the current code unit normally.
            }

            if ($cu >= 0xD800 && $cu <= 0xDBFF) {
                $pendingHigh = $cu;
                continue;
            }
            if ($cu >= 0xDC00 && $cu <= 0xDFFF) {
                // Unexpected low surrogate.
                if ($fatal) {
                    throw new TypeError('TextDecoder.decode: lone surrogate');
                }
                $out .= "\xEF\xBF\xBD";
                continue;
            }
            $out .= self::codepointToUtf8($cu);
        }

        // Handle a single trailing byte.
        if ($i < $len) {
            if ($stream) {
                if ($pendingHigh !== -1) {
                    // Save high surrogate (2 bytes) plus the trailing byte.
                    $pendingOut = self::encodeUtf16CodeUnit($pendingHigh, $littleEndian) . \substr($bytes, $i);
                } else {
                    $pendingOut = \substr($bytes, $i);
                }
                return $out;
            }
            if ($fatal) {
                throw new TypeError('TextDecoder.decode: incomplete UTF-16 byte sequence');
            }
            if ($pendingHigh !== -1) {
                $out .= "\xEF\xBF\xBD"; // Replacement for orphaned high surrogate.
                $pendingHigh = -1;
            }
            $out .= "\xEF\xBF\xBD"; // Replacement for orphan byte.
            return $out;
        }

        if ($pendingHigh !== -1) {
            if ($stream) {
                $pendingOut = self::encodeUtf16CodeUnit($pendingHigh, $littleEndian);
                return $out;
            }
            if ($fatal) {
                throw new TypeError('TextDecoder.decode: lone surrogate');
            }
            $out .= "\xEF\xBF\xBD";
        }

        return $out;
    }

    private static function encodeUtf16CodeUnit(int $cu, bool $littleEndian): string
    {
        if ($littleEndian) {
            return \chr($cu & 0xFF) . \chr(($cu >> 8) & 0xFF);
        }
        return \chr(($cu >> 8) & 0xFF) . \chr($cu & 0xFF);
    }

    /**
     * Encode a Unicode codepoint into Phasis's internal string format
     * (canonical UTF-8 for normal codepoints, CESU-8 for lone surrogates).
     */
    private static function codepointToUtf8(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            // Includes lone surrogates (CESU-8 encoding).
            return \chr(0xE0 | ($cp >> 12))
                . \chr(0x80 | (($cp >> 6) & 0x3F))
                . \chr(0x80 | ($cp & 0x3F));
        }
        return \chr(0xF0 | ($cp >> 18))
            . \chr(0x80 | (($cp >> 12) & 0x3F))
            . \chr(0x80 | (($cp >> 6) & 0x3F))
            . \chr(0x80 | ($cp & 0x3F));
    }
}
