<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\BuiltIn\Fetch\BodyExtraction;
use Phasis\BuiltIn\Fetch\BodyMixin;
use Phasis\BuiltIn\Url\UrlParser;
use Phasis\BuiltIn\Url\UrlSerializer;
use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG Fetch `Response` constructor + prototype.
 *
 *  https://fetch.spec.whatwg.org/#response-class
 *
 * Internal slots:
 *   [[IsResponse]]      : true
 *   [[Status]]          : int (200-599 or 0 for network error)
 *   [[StatusText]]      : string
 *   [[Headers]]         : JsObject (Headers instance)
 *   [[Url]]             : string ("" by default; set by fetch() when
 *                          response is the result of an actual transport
 *                          round-trip)
 *   [[Type]]            : string ("basic" | "cors" | "error" | …)
 *   [[Redirected]]      : bool
 *   plus Body-mixin slots (BodyMixin::markAsBodyHost)
 */
final class ResponseConstructor
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private static ?JsObject $prototype = null;

    public static function getPrototype(): ?JsObject
    {
        return self::$prototype;
    }

    public static function install(Environment $env): void
    {
        $proto = new JsObject();
        self::$prototype = $proto;

        self::installGetters($proto);
        self::installClone($proto, $env);
        BodyMixin::installBodyMixin($proto, $env);

        // Symbol.toStringTag = "Response"
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Response'), false, false, true),
        );

        $ctor = JsFunction::fromCallable(
            'Response',
            static function (JsValue $this_, array $args) use ($proto, $env): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Failed to construct 'Response': Please use the 'new' operator");
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $this_->setPrototype($ntProto instanceof JsObject ? $ntProto : $proto);
                }
                $body = $args[0] ?? JsUndefined::instance();
                $init = $args[1] ?? JsUndefined::instance();
                self::initResponse($this_, $body, $init, $env, /* isError */ false);
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

        self::installStatics($ctor, $proto, $env);

        $env->defineVar('Response', $ctor);
    }

    /**
     * Brand check used by fetch() and library code.
     */
    public static function isResponse(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsResponse]]') === true;
    }

    /**
     * Construct a Response from PHP-side bytes — used by fetch() once
     * shipped. Bypasses the JS constructor argument coercion path.
     */
    public static function createFromPhp(
        Environment $env,
        ?string $bodyBytes,
        int $status,
        string $statusText,
        JsObject $headers,
        string $url = '',
        string $type = 'basic',
        bool $redirected = false,
    ): JsObject {
        $proto = self::$prototype ?? new JsObject();
        $obj = new JsObject($proto);
        $obj->setInternalProperty('[[IsResponse]]', true);
        $obj->setInternalProperty('[[Status]]', $status);
        $obj->setInternalProperty('[[StatusText]]', $statusText);
        $obj->setInternalProperty('[[Headers]]', $headers);
        $obj->setInternalProperty('[[Url]]', $url);
        $obj->setInternalProperty('[[Type]]', $type);
        $obj->setInternalProperty('[[Redirected]]', $redirected);
        BodyMixin::markAsBodyHost($obj, $bodyBytes, null);
        unset($env);
        return $obj;
    }

    // ------------------------------------------------------------------
    // Construction
    // ------------------------------------------------------------------

    private static function initResponse(JsObject $instance, JsValue $body, JsValue $init, Environment $env, bool $isError): void
    {
        $instance->setInternalProperty('[[IsResponse]]', true);

        // -------- Status / statusText ------------------------------------
        $status = 200;
        $statusText = '';
        $type = $isError ? 'error' : 'basic';
        $initObj = $init instanceof JsObject ? $init : null;

        if ($initObj !== null) {
            $statusVal = $initObj->get('status');
            if (!$statusVal instanceof JsUndefined) {
                $n = TypeConversion::toNumber($statusVal);
                if (!is_finite($n)) {
                    throw new RangeError("Failed to construct 'Response': status is not a number.");
                }
                $intStatus = (int) $n;
                if ($intStatus < 200 || $intStatus > 599) {
                    throw new RangeError(
                        "Failed to construct 'Response': The status provided ($intStatus) is outside the range [200, 599]."
                    );
                }
                $status = $intStatus;
            }
            $stVal = $initObj->get('statusText');
            if (!$stVal instanceof JsUndefined) {
                $st = TypeConversion::toString($stVal);
                if (!self::isValidReasonPhrase($st)) {
                    throw new TypeError("Failed to construct 'Response': Invalid statusText.");
                }
                $statusText = $st;
            }
        }

        $instance->setInternalProperty('[[Status]]', $status);
        $instance->setInternalProperty('[[StatusText]]', $statusText);
        $instance->setInternalProperty('[[Type]]', $type);
        $instance->setInternalProperty('[[Url]]', '');
        $instance->setInternalProperty('[[Redirected]]', false);

        // -------- Headers ------------------------------------------------
        $headers = HeadersConstructor::create($env, []);
        if ($initObj !== null) {
            $hInit = $initObj->get('headers');
            if (!$hInit instanceof JsUndefined && !$hInit instanceof JsNull) {
                self::fillHeadersFromValue($headers, $hInit);
            }
        }
        // Switch the headers guard to "response" so subsequent
        // append/set/delete go through the forbidden-response-header check.
        HeadersConstructor::setGuard($headers, 'response');
        $instance->setInternalProperty('[[Headers]]', $headers);

        // -------- Body ---------------------------------------------------
        $bodyBytes = null;
        $bodyContentType = null;

        if (!$body instanceof JsUndefined && !$body instanceof JsNull) {
            // Spec: null-body status (101, 204, 205, 304) MUST have null body.
            if (self::isNullBodyStatus($status)) {
                throw new TypeError(
                    "Failed to construct 'Response': Response with null body status cannot have body."
                );
            }
            $extracted = BodyExtraction::extractBody($body, $headers, $env);
            $bodyBytes = $extracted['bytes'];
            $bodyContentType = $extracted['contentType'];
            BodyExtraction::maybeSetContentType($headers, $extracted);
        }

        BodyMixin::markAsBodyHost($instance, $bodyBytes, $bodyContentType);
    }

    private static function isNullBodyStatus(int $status): bool
    {
        return $status === 101 || $status === 103 || $status === 204 || $status === 205 || $status === 304;
    }

    private static function isValidReasonPhrase(string $value): bool
    {
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($value[$i]);
            // HTAB / VCHAR / obs-text (0x80–0xff).
            if ($o === 0x09 || ($o >= 0x20 && $o <= 0x7e) || $o >= 0x80) {
                continue;
            }
            return false;
        }
        return true;
    }

    private static function fillHeadersFromValue(JsObject $headers, JsValue $init): void
    {
        if (HeadersConstructor::isHeaders($init)) {
            /** @var JsObject $init */
            $list = $init->getInternalProperty(HeadersConstructor::SLOT_LIST);
            if (is_array($list)) {
                foreach ($list as $pair) {
                    if (!is_array($pair) || count($pair) < 2) {
                        continue;
                    }
                    HeadersConstructor::appendFromPhp($headers, (string) $pair[0], (string) $pair[1]);
                }
            }
            return;
        }
        if (!$init instanceof JsObject) {
            throw new TypeError("Failed to construct 'Response': member headers is not a Headers, sequence, or record.");
        }
        $iterSym = SymbolConstructor::iterator();
        $iterMethod = $init->getBySymbol($iterSym);
        if ($iterMethod instanceof JsFunction) {
            self::fillFromPairIterable($headers, $init, $iterMethod);
            return;
        }
        foreach ($init->ownKeys() as $key) {
            $desc = $init->getOwnPropertyDescriptor($key);
            if ($desc === null || !($desc->enumerable ?? false)) {
                continue;
            }
            $value = $init->get($key);
            HeadersConstructor::appendFromPhp($headers, $key, TypeConversion::toString($value));
        }
    }

    private static function fillFromPairIterable(JsObject $headers, JsObject $iterable, JsFunction $iteratorMethod): void
    {
        $iterator = $iteratorMethod->call($iterable, []);
        if (!$iterator instanceof JsObject) {
            throw new TypeError('Headers init iterator must return an object');
        }
        $next = $iterator->get('next');
        if (!$next instanceof JsFunction) {
            throw new TypeError('Headers init iterator missing next()');
        }
        while (true) {
            $step = $next->call($iterator, []);
            if (!$step instanceof JsObject) {
                throw new TypeError('Headers init iterator step must return an object');
            }
            if (TypeConversion::toBoolean($step->get('done'))) {
                break;
            }
            $entry = $step->get('value');
            if (!$entry instanceof JsObject) {
                throw new TypeError('Headers init pair must be iterable of two items');
            }
            $entryIter = $entry->getBySymbol(SymbolConstructor::iterator());
            if (!$entryIter instanceof JsFunction) {
                throw new TypeError('Headers init pair is not iterable');
            }
            $sub = $entryIter->call($entry, []);
            if (!$sub instanceof JsObject) {
                throw new TypeError('Headers init pair iterator must return an object');
            }
            $subNext = $sub->get('next');
            if (!$subNext instanceof JsFunction) {
                throw new TypeError('Headers init pair iterator missing next()');
            }
            $items = [];
            for ($i = 0; $i < 3; $i++) {
                $st = $subNext->call($sub, []);
                if (!$st instanceof JsObject) {
                    throw new TypeError('Headers init step must return an object');
                }
                if (TypeConversion::toBoolean($st->get('done'))) {
                    break;
                }
                $items[] = $st->get('value');
            }
            if (count($items) !== 2) {
                throw new TypeError('Headers init: each pair must have exactly 2 elements');
            }
            HeadersConstructor::appendFromPhp(
                $headers,
                TypeConversion::toString($items[0]),
                TypeConversion::toString($items[1]),
            );
        }
    }

    // ------------------------------------------------------------------
    // Statics: Response.error / Response.redirect / Response.json
    // ------------------------------------------------------------------

    private static function installStatics(JsFunction $ctor, JsObject $proto, Environment $env): void
    {
        // Response.error()
        $errorFn = JsFunction::fromCallable(
            'error',
            static function (JsValue $this_, array $args) use ($proto, $env): JsValue {
                unset($this_, $args);
                $obj = new JsObject($proto);
                $obj->setInternalProperty('[[IsResponse]]', true);
                $obj->setInternalProperty('[[Status]]', 0);
                $obj->setInternalProperty('[[StatusText]]', '');
                $obj->setInternalProperty('[[Type]]', 'error');
                $obj->setInternalProperty('[[Url]]', '');
                $obj->setInternalProperty('[[Redirected]]', false);
                $errHeaders = HeadersConstructor::create($env, []);
                $obj->setInternalProperty('[[Headers]]', $errHeaders);
                BodyMixin::markAsBodyHost($obj, null, null);
                return $obj;
            },
            0,
        );
        $errorFn->setNonConstructable();
        $ctor->defineOwnProperty('error', PropertyDescriptor::data($errorFn, true, false, true));

        // Response.redirect(url, status?)
        $redirectFn = JsFunction::fromCallable(
            'redirect',
            static function (JsValue $this_, array $args) use ($proto, $env): JsValue {
                unset($this_);
                $urlVal = $args[0] ?? JsUndefined::instance();
                $statusVal = $args[1] ?? JsUndefined::instance();
                $rawUrl = TypeConversion::toString($urlVal);
                $parsed = UrlParser::parse($rawUrl);
                if ($parsed === null) {
                    throw new TypeError("Failed to execute 'redirect' on 'Response': Invalid URL: '$rawUrl'");
                }
                $status = 302;
                if (!$statusVal instanceof JsUndefined) {
                    $n = TypeConversion::toNumber($statusVal);
                    $status = (int) $n;
                }
                if (!in_array($status, self::REDIRECT_STATUSES, true)) {
                    throw new RangeError(
                        "Failed to execute 'redirect' on 'Response': Invalid status code"
                    );
                }
                $serialized = UrlSerializer::serializeUrl($parsed);
                $obj = new JsObject($proto);
                $obj->setInternalProperty('[[IsResponse]]', true);
                $obj->setInternalProperty('[[Status]]', $status);
                $obj->setInternalProperty('[[StatusText]]', '');
                $obj->setInternalProperty('[[Type]]', 'default');
                $obj->setInternalProperty('[[Url]]', '');
                $obj->setInternalProperty('[[Redirected]]', false);
                $hdrs = HeadersConstructor::create($env, [['Location', $serialized]]);
                $obj->setInternalProperty('[[Headers]]', $hdrs);
                BodyMixin::markAsBodyHost($obj, null, null);
                return $obj;
            },
            1,
        );
        $redirectFn->setNonConstructable();
        $ctor->defineOwnProperty('redirect', PropertyDescriptor::data($redirectFn, true, false, true));

        // Response.json(data, init?)
        $jsonFn = JsFunction::fromCallable(
            'json',
            static function (JsValue $this_, array $args) use ($proto, $env): JsValue {
                unset($this_);
                $data = $args[0] ?? JsUndefined::instance();
                $init = $args[1] ?? JsUndefined::instance();

                // Stringify the data via JSON.stringify (no replacer, no
                // indent). undefined data → throws TypeError per spec.
                if ($data instanceof JsUndefined) {
                    throw new TypeError("Failed to execute 'json' on 'Response': value is undefined");
                }
                $jsonText = self::callJsonStringify($env, $data);
                if ($jsonText === null) {
                    throw new TypeError("Failed to execute 'json' on 'Response': value cannot be JSON-stringified");
                }

                // Build the response via the same path as `new Response()`
                // so all init handling (status, statusText, headers) runs.
                $bodyJs = new JsString($jsonText);
                $obj = new JsObject($proto);
                self::initResponse($obj, $bodyJs, $init, $env, false);

                // Force Content-Type to application/json per spec (even if
                // the init already supplied one).
                $headers = $obj->getInternalProperty('[[Headers]]');
                if ($headers instanceof JsObject) {
                    HeadersConstructor::setFromPhp($headers, 'Content-Type', 'application/json');
                }
                return $obj;
            },
            1,
        );
        $jsonFn->setNonConstructable();
        $ctor->defineOwnProperty('json', PropertyDescriptor::data($jsonFn, true, false, true));
    }

    private static function callJsonStringify(Environment $env, JsValue $value): ?string
    {
        if (!$env->has('JSON')) {
            return null;
        }
        $jsonObj = $env->get('JSON');
        if (!$jsonObj instanceof JsObject) {
            return null;
        }
        $stringify = $jsonObj->get('stringify');
        if (!$stringify instanceof JsFunction) {
            return null;
        }
        $result = $stringify->call($jsonObj, [$value]);
        if ($result instanceof JsString) {
            return $result->value;
        }
        if ($result instanceof JsUndefined) {
            return null;
        }
        return TypeConversion::toString($result);
    }

    // ------------------------------------------------------------------
    // Prototype getters
    // ------------------------------------------------------------------

    private static function installGetters(JsObject $proto): void
    {
        self::defineNumberGetter($proto, 'status', '[[Status]]');
        self::defineStringGetter($proto, 'statusText', '[[StatusText]]');
        self::defineStringGetter($proto, 'url', '[[Url]]');
        self::defineStringGetter($proto, 'type', '[[Type]]');
        self::defineBoolGetter($proto, 'redirected', '[[Redirected]]');

        // ok = (200..=299)
        $okGet = JsFunction::fromCallable(
            'get ok',
            static function (JsValue $this_): JsValue {
                self::assertReceiver($this_, 'ok');
                /** @var JsObject $this_ */
                $s = $this_->getInternalProperty('[[Status]]');
                $s = is_int($s) ? $s : 0;
                return JsBoolean::of($s >= 200 && $s < 300);
            },
            0,
        );
        $okGet->setNonConstructable();
        $proto->defineOwnProperty('ok', PropertyDescriptor::accessor($okGet, null, false, true));

        // headers getter
        $hdrGet = JsFunction::fromCallable(
            'get headers',
            static function (JsValue $this_): JsValue {
                self::assertReceiver($this_, 'headers');
                /** @var JsObject $this_ */
                $h = $this_->getInternalProperty('[[Headers]]');
                return $h instanceof JsObject ? $h : JsUndefined::instance();
            },
            0,
        );
        $hdrGet->setNonConstructable();
        $proto->defineOwnProperty('headers', PropertyDescriptor::accessor($hdrGet, null, false, true));
    }

    private static function defineStringGetter(JsObject $proto, string $key, string $slot): void
    {
        $get = JsFunction::fromCallable(
            'get ' . $key,
            static function (JsValue $this_) use ($key, $slot): JsValue {
                self::assertReceiver($this_, $key);
                /** @var JsObject $this_ */
                $val = $this_->getInternalProperty($slot);
                return new JsString(is_string($val) ? $val : '');
            },
            0,
        );
        $get->setNonConstructable();
        $proto->defineOwnProperty($key, PropertyDescriptor::accessor($get, null, false, true));
    }

    private static function defineNumberGetter(JsObject $proto, string $key, string $slot): void
    {
        $get = JsFunction::fromCallable(
            'get ' . $key,
            static function (JsValue $this_) use ($key, $slot): JsValue {
                self::assertReceiver($this_, $key);
                /** @var JsObject $this_ */
                $val = $this_->getInternalProperty($slot);
                return JsNumber::of(is_int($val) ? (float) $val : 0.0);
            },
            0,
        );
        $get->setNonConstructable();
        $proto->defineOwnProperty($key, PropertyDescriptor::accessor($get, null, false, true));
    }

    private static function defineBoolGetter(JsObject $proto, string $key, string $slot): void
    {
        $get = JsFunction::fromCallable(
            'get ' . $key,
            static function (JsValue $this_) use ($key, $slot): JsValue {
                self::assertReceiver($this_, $key);
                /** @var JsObject $this_ */
                return JsBoolean::of($this_->getInternalProperty($slot) === true);
            },
            0,
        );
        $get->setNonConstructable();
        $proto->defineOwnProperty($key, PropertyDescriptor::accessor($get, null, false, true));
    }

    private static function assertReceiver(JsValue $v, string $accessor): void
    {
        if (!self::isResponse($v)) {
            throw new TypeError("'$accessor' called on an object that does not implement interface Response");
        }
    }

    // ------------------------------------------------------------------
    // clone()
    // ------------------------------------------------------------------

    private static function installClone(JsObject $proto, Environment $env): void
    {
        $fn = JsFunction::fromCallable(
            'clone',
            static function (JsValue $this_, array $args) use ($proto, $env): JsValue {
                unset($args);
                self::assertReceiver($this_, 'clone');
                /** @var JsObject $this_ */
                if (BodyMixin::isBodyUsed($this_) || BodyMixin::isStreamLockedOrDisturbed($this_)) {
                    throw new TypeError("Failed to execute 'clone' on 'Response': body is unusable.");
                }
                $clone = new JsObject($proto);
                $clone->setInternalProperty('[[IsResponse]]', true);
                $clone->setInternalProperty('[[Status]]', $this_->getInternalProperty('[[Status]]'));
                $clone->setInternalProperty('[[StatusText]]', $this_->getInternalProperty('[[StatusText]]'));
                $clone->setInternalProperty('[[Type]]', $this_->getInternalProperty('[[Type]]'));
                $clone->setInternalProperty('[[Url]]', $this_->getInternalProperty('[[Url]]'));
                $clone->setInternalProperty('[[Redirected]]', $this_->getInternalProperty('[[Redirected]]'));

                // Deep-copy headers
                $srcHeaders = $this_->getInternalProperty('[[Headers]]');
                $newHeaders = HeadersConstructor::create($env, []);
                if ($srcHeaders instanceof JsObject) {
                    $list = $srcHeaders->getInternalProperty(HeadersConstructor::SLOT_LIST);
                    if (is_array($list)) {
                        foreach ($list as $pair) {
                            if (!is_array($pair) || count($pair) < 2) {
                                continue;
                            }
                            HeadersConstructor::appendFromPhp($newHeaders, (string) $pair[0], (string) $pair[1]);
                        }
                    }
                }
                $clone->setInternalProperty('[[Headers]]', $newHeaders);

                $bytes = BodyMixin::bodyBytes($this_);
                $ct = BodyMixin::bodyContentType($this_);
                BodyMixin::markAsBodyHost($clone, $bytes, $ct);
                return $clone;
            },
            0,
        );
        $proto->defineOwnProperty('clone', PropertyDescriptor::data($fn, true, false, true));
    }
}
