<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\BuiltIn\Url\SearchParams;
use Phasis\BuiltIn\Url\UrlGettersSetters;
use Phasis\BuiltIn\Url\UrlParser;
use Phasis\BuiltIn\Url\UrlRecord;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsString;
use Phasis\Value\JsNull;
use Phasis\Value\JsObject;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Installs WHATWG `URL` and `URLSearchParams` global constructors.
 *
 * Implements the URL Standard (https://url.spec.whatwg.org/) at v1 with
 * these documented limitations:
 *
 *  - No IDN / Punycode. Non-ASCII hostnames on special schemes fail with
 *    TypeError "Invalid URL". Non-special schemes (opaque hosts) pass
 *    through UTF-8 percent-encoded. The PRD chose this lenient path over
 *    bundling a 5 K-line UTS#46 table for v1; phase 3.5 may add it.
 *
 *  - `origin` for `blob:` URLs returns "null" rather than recursing into
 *    the inner URL — a minor lossy simplification.
 *
 *  - Validation-error reporting (non-fatal warnings the spec emits) is
 *    silently absorbed. Only failure-class errors throw.
 */
class UrlConstructor
{
    public static function reset(): void
    {
        SearchParams::reset();
    }

    public static function install(Environment $env): void
    {
        self::reset();

        // Capture %IteratorPrototype% so URLSearchParams iterators inherit
        // from it (otherwise Array.from(usp.keys()) gives an empty array).
        $iterProto = $env->has('__IteratorPrototype__') ? $env->get('__IteratorPrototype__') : null;
        SearchParams::setIntrinsicIteratorPrototype($iterProto instanceof JsObject ? $iterProto : null);

        $proto = new JsObject();
        UrlGettersSetters::installOn($proto);

        $constructor = JsFunction::fromCallable(
            'URL',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Failed to construct 'URL': Please use the 'new' operator");
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $this_->setPrototype($ntProto instanceof JsObject ? $ntProto : $proto);
                }

                $url = self::doParse($args, /* throwOnFail */ true);
                $this_->setInternalProperty(UrlGettersSetters::URL_SLOT, $url);
                return $this_;
            },
            2,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        // URL.canParse(url, base?)
        $canParse = JsFunction::fromCallable('canParse', function (JsValue $this_, array $args): JsValue {
            try {
                $r = self::doParse($args, /* throwOnFail */ false);
                return new JsBoolean($r !== null);
            } catch (\Throwable) {
                return new JsBoolean(false);
            }
        }, 1);
        $canParse->setNonConstructable();
        $constructor->defineOwnProperty('canParse', PropertyDescriptor::data($canParse, true, false, true));

        // URL.parse(url, base?) → URL or null.
        $parseFn = JsFunction::fromCallable('parse', function (JsValue $this_, array $args) use ($proto): JsValue {
            try {
                $rec = self::doParse($args, /* throwOnFail */ false);
            } catch (\Throwable) {
                return JsNull::instance();
            }
            if ($rec === null) {
                return JsNull::instance();
            }
            $obj = new JsObject($proto);
            $obj->setInternalProperty(UrlGettersSetters::URL_SLOT, $rec);
            return $obj;
        }, 1);
        $parseFn->setNonConstructable();
        $constructor->defineOwnProperty('parse', PropertyDescriptor::data($parseFn, true, false, true));

        // URL.createObjectURL(blob|MediaSource) and URL.revokeObjectURL(url).
        // We support the Blob/File case; MediaSource isn't shipped. Each
        // call mints a `blob:<scheme>//<origin>/<uuid>` URL the fetch
        // transport recognises and resolves out of an in-memory
        // registry (see BlobURLRegistry).
        $createObjectURL = JsFunction::fromCallable(
            'createObjectURL',
            static function (JsValue $this_, array $args): JsValue {
                $obj = $args[0] ?? JsUndefined::instance();
                if (!$obj instanceof JsObject || !\Phasis\BuiltIn\BlobConstructor::isBlob($obj)) {
                    throw new TypeError(
                        "Failed to execute 'createObjectURL' on 'URL': "
                        . 'parameter 1 is not of type Blob.'
                    );
                }
                $url = \Phasis\BuiltIn\BlobURLRegistry::register(
                    \Phasis\BuiltIn\BlobConstructor::getBytes($obj),
                    \Phasis\BuiltIn\BlobConstructor::getType($obj),
                );
                return new JsString($url);
            },
            1,
        );
        $createObjectURL->setNonConstructable();
        $constructor->defineOwnProperty(
            'createObjectURL',
            PropertyDescriptor::data($createObjectURL, true, false, true),
        );

        $revokeObjectURL = JsFunction::fromCallable(
            'revokeObjectURL',
            static function (JsValue $this_, array $args): JsValue {
                $url = TypeConversion::toString($args[0] ?? JsUndefined::instance());
                \Phasis\BuiltIn\BlobURLRegistry::revoke($url);
                return JsUndefined::instance();
            },
            1,
        );
        $revokeObjectURL->setNonConstructable();
        $constructor->defineOwnProperty(
            'revokeObjectURL',
            PropertyDescriptor::data($revokeObjectURL, true, false, true),
        );

        $env->defineVar('URL', $constructor);

        // URLSearchParams alongside URL.
        $env->defineVar('URLSearchParams', SearchParams::getConstructor());
    }

    /**
     * Shared parse helper used by `new URL`, `URL.canParse`, `URL.parse`.
     *
     * @param array<int, JsValue> $args
     */
    private static function doParse(array $args, bool $throwOnFail): ?UrlRecord
    {
        $rawInput = $args[0] ?? JsUndefined::instance();
        $rawBase = $args[1] ?? JsUndefined::instance();

        // Per spec, URL constructor coerces both inputs via ToString.
        // canParse/parse normalize the same way except they don't throw on
        // failure — but they DO let ToString side-effects happen.
        $input = TypeConversion::toString($rawInput);

        $baseRecord = null;
        if (!$rawBase instanceof JsUndefined) {
            $baseStr = TypeConversion::toString($rawBase);
            $baseRecord = UrlParser::parse($baseStr);
            if ($baseRecord === null) {
                if ($throwOnFail) {
                    throw new TypeError("Invalid base URL: '$baseStr'");
                }
                return null;
            }
        }

        $rec = UrlParser::parse($input, $baseRecord);
        if ($rec === null) {
            if ($throwOnFail) {
                throw new TypeError("Invalid URL: '$input'");
            }
            return null;
        }
        return $rec;
    }
}
