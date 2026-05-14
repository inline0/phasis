<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsDataView;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * W3C File API: Blob + File.
 *
 *  - Blob:  https://w3c.github.io/FileAPI/#blob-section
 *  - File:  https://w3c.github.io/FileAPI/#file-section
 *
 * Internal slots stored on each instance:
 *  - [[BlobBytes]]       string (raw PHP byte sequence; for File this is the
 *                        underlying file body)
 *  - [[BlobType]]        string (a parsed MIME type or empty string)
 *  - [[IsBlob]]          true marker for brand checks
 *  - [[IsFile]]          true marker on File instances
 *  - [[FileName]]        string (File only)
 *  - [[LastModified]]    int milliseconds (File only)
 *
 * `slice()` returns a fresh Blob over a substring without copying buffers
 * beyond the substring extraction. `text()` / `arrayBuffer()` / `bytes()`
 * return Promises so the value-shape matches the spec exactly even though
 * Phasis resolves the reads synchronously. `stream()` deliberately throws
 * a clear TypeError pending the ReadableStream layer (Fetch Pack phase
 * F-7); the rest of the API is fully functional today.
 */
class BlobConstructor
{
    private static ?JsObject $blobPrototype = null;
    private static ?JsObject $filePrototype = null;

    public static function install(Environment $env): void
    {
        self::$blobPrototype = null;
        self::$filePrototype = null;

        $blobProto = self::buildBlobPrototype();
        self::$blobPrototype = $blobProto;
        $blobCtor = self::buildBlobConstructor($blobProto);

        $fileProto = self::buildFilePrototype($blobProto);
        self::$filePrototype = $fileProto;
        $fileCtor = self::buildFileConstructor($fileProto, $blobCtor);

        $env->defineVar('Blob', $blobCtor);
        $env->defineVar('File', $fileCtor);
    }

    // ===== Public helpers (used by FormData and future Body mixin) ========

    public static function isBlob(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsBlob]]') === true;
    }

    public static function isFile(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsFile]]') === true;
    }

    public static function getBytes(JsObject $blob): string
    {
        $bytes = $blob->getInternalProperty('[[BlobBytes]]');
        return is_string($bytes) ? $bytes : '';
    }

    public static function getType(JsObject $blob): string
    {
        $type = $blob->getInternalProperty('[[BlobType]]');
        return is_string($type) ? $type : '';
    }

    /**
     * Construct a Blob JS object from raw bytes + MIME type without
     * routing through the public JS constructor. Used by Body.blob() and
     * Response.blob() once those land.
     */
    public static function createBlob(string $bytes, string $type = ''): JsObject
    {
        $proto = self::$blobPrototype ?? self::buildBlobPrototype();
        $obj = new JsObject($proto);
        $obj->setInternalProperty('[[IsBlob]]', true);
        $obj->setInternalProperty('[[BlobBytes]]', $bytes);
        $obj->setInternalProperty('[[BlobType]]', self::normalizeType($type));
        return $obj;
    }

    /**
     * Construct a File JS object directly from bytes + filename. Used by
     * FormData.append/set when a Blob value comes in with a `filename`
     * override per the WHATWG xhr spec §4.
     */
    public static function createFile(
        string $bytes,
        string $name,
        string $type = '',
        ?int $lastModifiedMs = null,
    ): JsObject {
        $proto = self::$filePrototype ?? self::buildFilePrototype(
            self::$blobPrototype ?? self::buildBlobPrototype()
        );
        $obj = new JsObject($proto);
        $obj->setInternalProperty('[[IsBlob]]', true);
        $obj->setInternalProperty('[[IsFile]]', true);
        $obj->setInternalProperty('[[BlobBytes]]', $bytes);
        $obj->setInternalProperty('[[BlobType]]', self::normalizeType($type));
        $obj->setInternalProperty('[[FileName]]', $name);
        $obj->setInternalProperty(
            '[[LastModified]]',
            $lastModifiedMs ?? (int) (microtime(true) * 1000),
        );
        return $obj;
    }

    // ===== Blob constructor ===============================================

    private static function buildBlobConstructor(JsObject $proto): JsFunction
    {
        $ctor = JsFunction::fromCallable(
            'Blob',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Failed to construct 'Blob': Please use the 'new' operator");
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $this_->setPrototype($ntProto instanceof JsObject ? $ntProto : $proto);
                }

                $parts = $args[0] ?? JsUndefined::instance();
                $options = $args[1] ?? JsUndefined::instance();

                [$bytes, $type] = self::processPartsAndOptions($parts, $options);

                $this_->setInternalProperty('[[IsBlob]]', true);
                $this_->setInternalProperty('[[BlobBytes]]', $bytes);
                $this_->setInternalProperty('[[BlobType]]', $type);
                return $this_;
            },
            2,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true),
        );
        return $ctor;
    }

    // ===== File constructor ===============================================

    private static function buildFileConstructor(JsObject $proto, JsFunction $blobCtor): JsFunction
    {
        $ctor = JsFunction::fromCallable(
            'File',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Failed to construct 'File': Please use the 'new' operator");
                }
                if (count($args) < 2) {
                    throw new TypeError(
                        "Failed to construct 'File': 2 arguments required, but only "
                        . count($args) . ' present.'
                    );
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $this_->setPrototype($ntProto instanceof JsObject ? $ntProto : $proto);
                }

                $parts = $args[0];
                $name = TypeConversion::toString($args[1]);
                $options = $args[2] ?? JsUndefined::instance();

                [$bytes, $type] = self::processPartsAndOptions($parts, $options);

                $lastModified = null;
                if ($options instanceof JsObject) {
                    $lm = $options->get('lastModified');
                    if (!$lm instanceof JsUndefined) {
                        $lmNum = TypeConversion::toNumber($lm);
                        if (is_nan($lmNum)) {
                            $lastModified = 0;
                        } else {
                            $lastModified = (int) $lmNum;
                        }
                    }
                }
                if ($lastModified === null) {
                    $lastModified = (int) (microtime(true) * 1000);
                }

                // Per spec, "/" in name must be replaced with ":"
                $name = str_replace('/', ':', $name);

                $this_->setInternalProperty('[[IsBlob]]', true);
                $this_->setInternalProperty('[[IsFile]]', true);
                $this_->setInternalProperty('[[BlobBytes]]', $bytes);
                $this_->setInternalProperty('[[BlobType]]', $type);
                $this_->setInternalProperty('[[FileName]]', $name);
                $this_->setInternalProperty('[[LastModified]]', $lastModified);
                return $this_;
            },
            3,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true),
        );
        return $ctor;
    }

    // ===== Prototypes =====================================================

    private static function buildBlobPrototype(): JsObject
    {
        $proto = new JsObject();

        // size getter
        $sizeGetter = JsFunction::fromCallable(
            'get size',
            function (JsValue $this_): JsValue {
                self::assertBlob($this_, 'size');
                /** @var JsObject $this_ */
                return JsNumber::of((float) strlen(self::getBytes($this_)));
            },
            0,
        );
        $proto->defineOwnProperty(
            'size',
            PropertyDescriptor::accessor($sizeGetter, null, true, true),
        );

        // type getter
        $typeGetter = JsFunction::fromCallable(
            'get type',
            function (JsValue $this_): JsValue {
                self::assertBlob($this_, 'type');
                /** @var JsObject $this_ */
                return new JsString(self::getType($this_));
            },
            0,
        );
        $proto->defineOwnProperty(
            'type',
            PropertyDescriptor::accessor($typeGetter, null, true, true),
        );

        // slice(start?, end?, contentType?)
        $sliceFn = JsFunction::fromCallable(
            'slice',
            function (JsValue $this_, array $args): JsValue {
                self::assertBlob($this_, 'slice');
                /** @var JsObject $this_ */
                $bytes = self::getBytes($this_);
                $size = strlen($bytes);

                $relStart = self::resolveSliceIndex($args[0] ?? JsUndefined::instance(), 0, $size);
                $relEnd = self::resolveSliceIndex($args[1] ?? JsUndefined::instance(), $size, $size);
                $span = max(0, $relEnd - $relStart);

                $contentType = '';
                $ctVal = $args[2] ?? JsUndefined::instance();
                if (!$ctVal instanceof JsUndefined) {
                    $contentType = self::normalizeType(TypeConversion::toString($ctVal));
                }

                $sliced = $span > 0 ? substr($bytes, $relStart, $span) : '';
                return self::createBlob($sliced, $contentType);
            },
            3,
        );
        $proto->defineOwnProperty(
            'slice',
            PropertyDescriptor::data($sliceFn, true, false, true),
        );

        // arrayBuffer(): Promise<ArrayBuffer>
        $arrayBufferFn = JsFunction::fromCallable(
            'arrayBuffer',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isBlob($this_)) {
                    return JsPromise::rejected(
                        self::makeTypeErrorObject("'arrayBuffer' called on an object that is not a Blob")
                    );
                }
                /** @var JsObject $this_ */
                $bytes = self::getBytes($this_);
                $buf = new JsArrayBuffer(strlen($bytes), JsArrayBuffer::getDefaultPrototype());
                if ($bytes !== '') {
                    $buf->writeBytes(0, $bytes);
                }
                return JsPromise::resolved($buf);
            },
            0,
        );
        $proto->defineOwnProperty(
            'arrayBuffer',
            PropertyDescriptor::data($arrayBufferFn, true, false, true),
        );

        // bytes(): Promise<Uint8Array> (ES2024 addition to FileAPI)
        $bytesFn = JsFunction::fromCallable(
            'bytes',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isBlob($this_)) {
                    return JsPromise::rejected(
                        self::makeTypeErrorObject("'bytes' called on an object that is not a Blob")
                    );
                }
                /** @var JsObject $this_ */
                $bytes = self::getBytes($this_);
                return JsPromise::resolved(TextEncoderConstructor::makeUint8Array($bytes));
            },
            0,
        );
        $proto->defineOwnProperty(
            'bytes',
            PropertyDescriptor::data($bytesFn, true, false, true),
        );

        // text(): Promise<string>  (UTF-8 decode, replacement chars on invalid bytes)
        $textFn = JsFunction::fromCallable(
            'text',
            function (JsValue $this_, array $args): JsValue {
                if (!self::isBlob($this_)) {
                    return JsPromise::rejected(
                        self::makeTypeErrorObject("'text' called on an object that is not a Blob")
                    );
                }
                /** @var JsObject $this_ */
                $bytes = self::getBytes($this_);
                $decoded = self::decodeUtf8WithReplacement($bytes);
                return JsPromise::resolved(new JsString($decoded));
            },
            0,
        );
        $proto->defineOwnProperty(
            'text',
            PropertyDescriptor::data($textFn, true, false, true),
        );

        // stream(): ReadableStream — not implemented yet.
        // Phase F-7 of the Fetch Pack ships ReadableStream; until then,
        // calling stream() raises a clear, spec-named TypeError so callers
        // see a concrete signal rather than `undefined.getReader()`.
        $streamFn = JsFunction::fromCallable(
            'stream',
            function (JsValue $this_, array $args): JsValue {
                self::assertBlob($this_, 'stream');
                throw new TypeError(
                    'Blob.prototype.stream() requires ReadableStream which is not yet '
                    . 'implemented in Phasis; consume the Blob via arrayBuffer(), '
                    . 'bytes(), or text() instead.'
                );
            },
            0,
        );
        $proto->defineOwnProperty(
            'stream',
            PropertyDescriptor::data($streamFn, true, false, true),
        );

        // Symbol.toStringTag = "Blob"
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Blob'), false, false, true),
        );

        return $proto;
    }

    private static function buildFilePrototype(JsObject $blobProto): JsObject
    {
        $proto = new JsObject($blobProto);

        // name getter
        $nameGetter = JsFunction::fromCallable(
            'get name',
            function (JsValue $this_): JsValue {
                self::assertFile($this_, 'name');
                /** @var JsObject $this_ */
                $name = $this_->getInternalProperty('[[FileName]]');
                return new JsString(is_string($name) ? $name : '');
            },
            0,
        );
        $proto->defineOwnProperty(
            'name',
            PropertyDescriptor::accessor($nameGetter, null, true, true),
        );

        // lastModified getter
        $lastModifiedGetter = JsFunction::fromCallable(
            'get lastModified',
            function (JsValue $this_): JsValue {
                self::assertFile($this_, 'lastModified');
                /** @var JsObject $this_ */
                $ms = $this_->getInternalProperty('[[LastModified]]');
                return JsNumber::of((float) (is_int($ms) ? $ms : 0));
            },
            0,
        );
        $proto->defineOwnProperty(
            'lastModified',
            PropertyDescriptor::accessor($lastModifiedGetter, null, true, true),
        );

        // webkitRelativePath getter — always "" since we have no DOM file picker.
        $relPathGetter = JsFunction::fromCallable(
            'get webkitRelativePath',
            function (JsValue $this_): JsValue {
                self::assertFile($this_, 'webkitRelativePath');
                return new JsString('');
            },
            0,
        );
        $proto->defineOwnProperty(
            'webkitRelativePath',
            PropertyDescriptor::accessor($relPathGetter, null, true, true),
        );

        // Symbol.toStringTag = "File"
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('File'), false, false, true),
        );

        return $proto;
    }

    // ===== Helpers ========================================================

    /**
     * Convert constructor arguments (blobParts, options) into raw bytes
     * and a parsed MIME type, per the Blob constructor steps:
     * https://w3c.github.io/FileAPI/#constructorBlob.
     *
     * @return array{0:string,1:string}
     */
    private static function processPartsAndOptions(JsValue $parts, JsValue $options): array
    {
        $bytes = '';
        $endings = 'transparent';
        $type = '';

        if ($options instanceof JsObject) {
            $endingsVal = $options->get('endings');
            if (!$endingsVal instanceof JsUndefined) {
                $endingsStr = TypeConversion::toString($endingsVal);
                if ($endingsStr !== 'transparent' && $endingsStr !== 'native') {
                    throw new TypeError(
                        "Failed to construct 'Blob': endings must be 'transparent' or 'native'"
                    );
                }
                $endings = $endingsStr;
            }
            $typeVal = $options->get('type');
            if (!$typeVal instanceof JsUndefined) {
                $type = self::normalizeType(TypeConversion::toString($typeVal));
            }
        } elseif (!$options instanceof JsUndefined && !$options instanceof \Phasis\Value\JsNull) {
            throw new TypeError("Blob: options must be a dictionary");
        }

        if ($parts instanceof JsUndefined) {
            return [$bytes, $type];
        }

        if (!$parts instanceof JsObject) {
            throw new TypeError("Failed to construct 'Blob': blobParts must be a sequence");
        }

        // blobParts is an iterable of BlobPart. Walk via Symbol.iterator
        // to match spec sequence semantics (also handles Array values).
        $iterSym = SymbolConstructor::iterator();
        $iterMethod = $parts->getBySymbol($iterSym);
        if (!$iterMethod instanceof JsFunction) {
            throw new TypeError(
                "Failed to construct 'Blob': blobParts is not iterable"
            );
        }
        $iterator = $iterMethod->call($parts, []);
        if (!$iterator instanceof JsObject) {
            throw new TypeError(
                "Failed to construct 'Blob': iterator must return an object"
            );
        }
        $next = $iterator->get('next');
        if (!$next instanceof JsFunction) {
            throw new TypeError(
                "Failed to construct 'Blob': iterator missing next()"
            );
        }

        while (true) {
            $step = $next->call($iterator, []);
            if (!$step instanceof JsObject) {
                throw new TypeError(
                    "Failed to construct 'Blob': iterator result must be an object"
                );
            }
            if (TypeConversion::toBoolean($step->get('done'))) {
                break;
            }
            $element = $step->get('value');
            $bytes .= self::partToBytes($element, $endings);
        }

        return [$bytes, $type];
    }

    /**
     * Convert a single BlobPart to its byte representation:
     *  - Blob: take its inner bytes.
     *  - ArrayBuffer / TypedArray / DataView: copy out the view's bytes.
     *  - Other: ToString and UTF-8 encode (with $endings handling).
     */
    private static function partToBytes(JsValue $element, string $endings): string
    {
        if (self::isBlob($element)) {
            /** @var JsObject $element */
            return self::getBytes($element);
        }
        if ($element instanceof JsTypedArray) {
            $element->validateNotDetached();
            $len = $element->getLength() * $element->getBytesPerElement();
            if ($len === 0) {
                return '';
            }
            return $element->getBuffer()->readBytes($element->getByteOffset(), $len);
        }
        if ($element instanceof JsDataView) {
            return $element->getBuffer()->readBytes(
                $element->getByteOffset(),
                $element->getByteLength()
            );
        }
        if ($element instanceof JsArrayBuffer) {
            if ($element->isDetached()) {
                return '';
            }
            return $element->readBytes(0, $element->getByteLength());
        }

        // Anything else stringifies. Phasis internal strings are UTF-8
        // (with CESU-8 for lone surrogates); the spec wants canonical
        // UTF-8 so reuse TextEncoder's lone-surrogate replacement.
        $str = TypeConversion::toString($element);
        $utf8 = TextEncoderConstructor::stringToUtf8Bytes($str);
        if ($endings === 'native') {
            // On non-Windows, native line endings are LF; CRLF on Windows.
            // Phasis is host-portable; convert any LF/CRLF in source to the
            // platform-native form to satisfy the spec literally.
            $native = PHP_EOL;
            // First normalize all line endings to LF, then expand to native.
            $utf8 = str_replace(["\r\n", "\r"], "\n", $utf8);
            if ($native !== "\n") {
                $utf8 = str_replace("\n", $native, $utf8);
            }
        }
        return $utf8;
    }

    /**
     * MIME type normalization per spec §3.1:
     *   - If the type contains any non-ASCII or non-printable byte, return "".
     *   - Otherwise lowercase the value.
     */
    private static function normalizeType(string $type): string
    {
        $len = strlen($type);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($type[$i]);
            if ($b < 0x20 || $b > 0x7E) {
                return '';
            }
        }
        return strtolower($type);
    }

    /**
     * Resolve a slice index. `undefined` falls back to $default.
     * Negative numbers are interpreted relative to $size, then clamped
     * into [0, $size] per Blob.slice() spec.
     */
    private static function resolveSliceIndex(JsValue $val, int $default, int $size): int
    {
        if ($val instanceof JsUndefined) {
            return max(0, min($default, $size));
        }
        $num = TypeConversion::toNumber($val);
        if (is_nan($num)) {
            $num = 0.0;
        }
        // Match the spec's WebIDL [Clamp] semantics + relative resolution.
        $idx = (int) $num;
        if ($idx < 0) {
            $idx = max($size + $idx, 0);
        } else {
            $idx = min($idx, $size);
        }
        return $idx;
    }

    /**
     * Decode a UTF-8 byte string into a Phasis internal string. Invalid
     * sequences are replaced by U+FFFD per the WHATWG UTF-8 decoder.
     */
    private static function decodeUtf8WithReplacement(string $bytes): string
    {
        $len = strlen($bytes);
        $out = '';
        $i = 0;
        while ($i < $len) {
            $b = ord($bytes[$i]);
            if ($b < 0x80) {
                $out .= $bytes[$i];
                $i++;
                continue;
            }

            $needed = 0;
            $lower = 0x80;
            $upper = 0xBF;
            $cp = 0;
            if (($b & 0xE0) === 0xC0) {
                if ($b < 0xC2) {
                    $out .= "\xEF\xBF\xBD";
                    $i++;
                    continue;
                }
                $needed = 1;
                $cp = $b & 0x1F;
            } elseif (($b & 0xF0) === 0xE0) {
                if ($b === 0xE0) {
                    $lower = 0xA0;
                } elseif ($b === 0xED) {
                    $upper = 0x9F;
                }
                $needed = 2;
                $cp = $b & 0x0F;
            } elseif (($b & 0xF8) === 0xF0) {
                if ($b === 0xF0) {
                    $lower = 0x90;
                } elseif ($b === 0xF4) {
                    $upper = 0x8F;
                } elseif ($b > 0xF4) {
                    $out .= "\xEF\xBF\xBD";
                    $i++;
                    continue;
                }
                $needed = 3;
                $cp = $b & 0x07;
            } else {
                $out .= "\xEF\xBF\xBD";
                $i++;
                continue;
            }

            if ($i + $needed >= $len) {
                $out .= "\xEF\xBF\xBD";
                $i++;
                continue;
            }

            $valid = true;
            $seq = $bytes[$i];
            for ($j = 1; $j <= $needed; $j++) {
                $cb = ord($bytes[$i + $j]);
                $jLower = ($j === 1) ? $lower : 0x80;
                $jUpper = ($j === 1) ? $upper : 0xBF;
                if ($cb < $jLower || $cb > $jUpper) {
                    $valid = false;
                    break;
                }
                $cp = ($cp << 6) | ($cb & 0x3F);
                $seq .= $bytes[$i + $j];
            }
            if (!$valid) {
                $out .= "\xEF\xBF\xBD";
                $i++;
                continue;
            }
            // Pass the validated UTF-8 sequence through verbatim — it's
            // already canonical UTF-8 which is exactly Phasis's internal
            // representation.
            $out .= $seq;
            $i += $needed + 1;
        }
        return $out;
    }

    private static function assertBlob(JsValue $v, string $member): void
    {
        if (!self::isBlob($v)) {
            throw new TypeError("'" . $member . "' called on an object that is not a Blob");
        }
    }

    private static function assertFile(JsValue $v, string $member): void
    {
        if (!self::isFile($v)) {
            throw new TypeError("'" . $member . "' called on an object that is not a File");
        }
    }

    /**
     * Build a JS-visible TypeError as a JsObject so we can hand it to a
     * rejected promise (which expects a JsValue, not a PHP exception).
     */
    private static function makeTypeErrorObject(string $message): JsValue
    {
        $realm = \Phasis\Engine::getCurrentRealm();
        if ($realm !== null) {
            $env = $realm->getGlobalEnv();
            if ($env->has('TypeError')) {
                $ctor = $env->get('TypeError');
                if ($ctor instanceof JsFunction) {
                    try {
                        return $ctor->construct([new JsString($message)]);
                    } catch (\Throwable) {
                        // Fall through to string fallback.
                    }
                }
            }
        }
        return new JsString($message);
    }
}
