<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\BuiltIn\Fetch\BodyExtraction;
use Phasis\BuiltIn\Fetch\BodyMixin;
use Phasis\BuiltIn\Streams\ReadableStream;
use Phasis\BuiltIn\Streams\StreamHelpers;
use Phasis\BuiltIn\Url\UrlParser;
use Phasis\BuiltIn\Url\UrlSerializer;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG Fetch `Request` constructor + prototype.
 *
 *  https://fetch.spec.whatwg.org/#request-class
 *
 * A `Request` is the value type for an HTTP request — URL, method,
 * headers, body, and a handful of fetch-mode toggles. Phasis exposes
 * every spec property as a read-only own accessor on the prototype, all
 * sourced from internal slots populated at construction.
 *
 * Internal slots:
 *   [[IsRequest]]        : true
 *   [[Method]]           : string (uppercased for standard verbs)
 *   [[Url]]              : string (serialized via WHATWG URL parser)
 *   [[Headers]]          : JsObject (Headers instance)
 *   [[Mode]]             : string
 *   [[Credentials]]      : string
 *   [[Cache]]            : string
 *   [[Redirect]]         : string
 *   [[Referrer]]         : string
 *   [[ReferrerPolicy]]   : string
 *   [[Integrity]]        : string
 *   [[Keepalive]]        : bool
 *   [[Signal]]           : JsObject (AbortSignal)
 *   [[Priority]]         : string
 *   [[Destination]]      : string ("")
 *   plus the Body-mixin slots populated via BodyMixin::markAsBodyHost
 */
final class RequestConstructor
{
    /** Forbidden methods that throw on Request construction per spec. */
    private const FORBIDDEN_METHODS = ['CONNECT', 'TRACE', 'TRACK'];

    /** Methods that get uppercased (the spec "normalize a method"). */
    private const NORMALIZE_METHODS = ['DELETE', 'GET', 'HEAD', 'OPTIONS', 'POST', 'PUT'];

    private const VALID_MODE = ['same-origin', 'no-cors', 'cors', 'navigate'];
    private const VALID_CREDENTIALS = ['omit', 'same-origin', 'include'];
    private const VALID_CACHE = ['default', 'no-store', 'reload', 'no-cache', 'force-cache', 'only-if-cached'];
    private const VALID_REDIRECT = ['follow', 'error', 'manual'];
    private const VALID_REFERRER_POLICY = [
        '',
        'no-referrer',
        'no-referrer-when-downgrade',
        'same-origin',
        'origin',
        'strict-origin',
        'origin-when-cross-origin',
        'strict-origin-when-cross-origin',
        'unsafe-url',
    ];
    private const VALID_PRIORITY = ['high', 'low', 'auto'];

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

        // Symbol.toStringTag = "Request"
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Request'), false, false, true),
        );

        $ctor = JsFunction::fromCallable(
            'Request',
            static function (JsValue $this_, array $args) use ($proto, $env): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Failed to construct 'Request': Please use the 'new' operator");
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $this_->setPrototype($ntProto instanceof JsObject ? $ntProto : $proto);
                }
                self::initRequest($this_, $args[0] ?? JsUndefined::instance(), $args[1] ?? JsUndefined::instance(), $env);
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

        $env->defineVar('Request', $ctor);
    }

    /**
     * Brand check used by Request(input) when input is another Request.
     */
    public static function isRequest(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty('[[IsRequest]]') === true;
    }

    // ------------------------------------------------------------------
    // Construction
    // ------------------------------------------------------------------

    private static function initRequest(JsObject $instance, JsValue $input, JsValue $init, Environment $env): void
    {
        $instance->setInternalProperty('[[IsRequest]]', true);

        // -------- Resolve input ---------------------------------------
        $sourceRequest = null;
        $url = '';
        if (self::isRequest($input)) {
            /** @var JsObject $input */
            $sourceRequest = $input;
            $url = (string) ($input->getInternalProperty('[[Url]]') ?? '');
        } else {
            $rawUrl = TypeConversion::toString($input);
            $parsed = UrlParser::parse($rawUrl);
            if ($parsed === null) {
                throw new TypeError("Failed to construct 'Request': Invalid URL: '$rawUrl'");
            }
            $url = UrlSerializer::serializeUrl($parsed);
        }
        $instance->setInternalProperty('[[Url]]', $url);

        $initObj = $init instanceof JsObject ? $init : null;
        $hasInit = $initObj !== null;

        // -------- Method -----------------------------------------------
        $method = 'GET';
        if ($sourceRequest !== null) {
            $m = $sourceRequest->getInternalProperty('[[Method]]');
            if (is_string($m)) {
                $method = $m;
            }
        }
        if ($hasInit) {
            $mInit = $initObj->get('method');
            if (!$mInit instanceof JsUndefined) {
                $mStr = TypeConversion::toString($mInit);
                $method = self::normalizeMethod($mStr);
            }
        }
        if (in_array(strtoupper($method), self::FORBIDDEN_METHODS, true)) {
            throw new TypeError("Failed to construct 'Request': '$method' HTTP method is unsupported.");
        }
        $instance->setInternalProperty('[[Method]]', $method);

        // -------- Headers ----------------------------------------------
        $headers = HeadersConstructor::create($env, []);
        if ($sourceRequest !== null) {
            $srcHeaders = $sourceRequest->getInternalProperty('[[Headers]]');
            if ($srcHeaders instanceof JsObject) {
                self::copyHeadersInto($headers, $srcHeaders);
            }
        }
        if ($hasInit) {
            $hInit = $initObj->get('headers');
            if (!$hInit instanceof JsUndefined && !$hInit instanceof JsNull) {
                self::fillHeadersFromValue($headers, $hInit, $env);
            }
        }
        $instance->setInternalProperty('[[Headers]]', $headers);

        // -------- Mode / credentials / cache / redirect / referrer / etc ----
        $instance->setInternalProperty('[[Mode]]', self::pickString(
            $sourceRequest,
            $initObj,
            '[[Mode]]',
            'mode',
            'cors',
            self::VALID_MODE,
        ));
        $instance->setInternalProperty('[[Credentials]]', self::pickString(
            $sourceRequest,
            $initObj,
            '[[Credentials]]',
            'credentials',
            'same-origin',
            self::VALID_CREDENTIALS,
        ));
        $instance->setInternalProperty('[[Cache]]', self::pickString(
            $sourceRequest,
            $initObj,
            '[[Cache]]',
            'cache',
            'default',
            self::VALID_CACHE,
        ));
        $instance->setInternalProperty('[[Redirect]]', self::pickString(
            $sourceRequest,
            $initObj,
            '[[Redirect]]',
            'redirect',
            'follow',
            self::VALID_REDIRECT,
        ));
        $instance->setInternalProperty('[[Referrer]]', self::pickString(
            $sourceRequest,
            $initObj,
            '[[Referrer]]',
            'referrer',
            'about:client',
            null,
        ));
        $instance->setInternalProperty('[[ReferrerPolicy]]', self::pickString(
            $sourceRequest,
            $initObj,
            '[[ReferrerPolicy]]',
            'referrerPolicy',
            '',
            self::VALID_REFERRER_POLICY,
        ));
        $instance->setInternalProperty('[[Integrity]]', self::pickString(
            $sourceRequest,
            $initObj,
            '[[Integrity]]',
            'integrity',
            '',
            null,
        ));
        $instance->setInternalProperty('[[Priority]]', self::pickString(
            $sourceRequest,
            $initObj,
            '[[Priority]]',
            'priority',
            'auto',
            self::VALID_PRIORITY,
        ));

        // keepalive (bool)
        $keepalive = false;
        if ($sourceRequest !== null) {
            $ka = $sourceRequest->getInternalProperty('[[Keepalive]]');
            if (is_bool($ka)) {
                $keepalive = $ka;
            }
        }
        if ($hasInit) {
            $kInit = $initObj->get('keepalive');
            if (!$kInit instanceof JsUndefined) {
                $keepalive = TypeConversion::toBoolean($kInit);
            }
        }
        $instance->setInternalProperty('[[Keepalive]]', $keepalive);

        // -------- Signal -----------------------------------------------
        $signal = null;
        if ($sourceRequest !== null) {
            $s = $sourceRequest->getInternalProperty('[[Signal]]');
            if ($s instanceof JsObject) {
                $signal = $s;
            }
        }
        if ($hasInit) {
            $sInit = $initObj->get('signal');
            if (!$sInit instanceof JsUndefined) {
                if ($sInit instanceof JsNull) {
                    $signal = null;
                } elseif (
                    $sInit instanceof JsObject
                    && $sInit->getInternalProperty(AbortControllerConstructor::SLOT_IS_SIGNAL) === true
                ) {
                    $signal = $sInit;
                } else {
                    throw new TypeError(
                        "Failed to construct 'Request': member signal is not an AbortSignal."
                    );
                }
            }
        }
        $instance->setInternalProperty('[[Signal]]', $signal);

        $instance->setInternalProperty('[[Destination]]', '');

        // -------- Body --------------------------------------------------
        $bodyBytes = null;
        $bodyContentType = null;

        // From source Request (if no init body): clone its buffered bytes.
        if ($sourceRequest !== null) {
            $sourceBytes = $sourceRequest->getInternalProperty('[[BodyBytes]]');
            if (is_string($sourceBytes)) {
                $bodyBytes = $sourceBytes;
                $ct = $sourceRequest->getInternalProperty('[[BodyContentType]]');
                if (is_string($ct)) {
                    $bodyContentType = $ct;
                }
            }
        }

        if ($hasInit) {
            $bInit = $initObj->get('body');
            if (!$bInit instanceof JsUndefined && !$bInit instanceof JsNull) {
                $methodUpper = strtoupper($method);
                if ($methodUpper === 'GET' || $methodUpper === 'HEAD') {
                    throw new TypeError(
                        "Failed to construct 'Request': Request with GET/HEAD method cannot have body."
                    );
                }
                $extracted = BodyExtraction::extractBody($bInit, $headers, $env, $keepalive);
                $bodyBytes = $extracted['bytes'];
                $bodyContentType = $extracted['contentType'];
                BodyExtraction::maybeSetContentType($headers, $extracted);
            }
        }

        BodyMixin::markAsBodyHost($instance, $bodyBytes, $bodyContentType);
    }

    private static function copyHeadersInto(JsObject $dst, JsObject $src): void
    {
        $list = $src->getInternalProperty(HeadersConstructor::SLOT_LIST);
        if (!is_array($list)) {
            return;
        }
        foreach ($list as $pair) {
            if (!is_array($pair) || count($pair) < 2) {
                continue;
            }
            HeadersConstructor::appendFromPhp($dst, (string) $pair[0], (string) $pair[1]);
        }
    }

    private static function fillHeadersFromValue(JsObject $headers, JsValue $init, Environment $env): void
    {
        unset($env);
        // Use the same algorithm Headers.prototype uses by feeding `init`
        // through Headers' append API. The Headers ctor would also accept
        // these shapes, but routing through Headers.prototype.append
        // would require receiver tricks. Simpler: directly handle
        // Headers instance / iterable / record.
        if (HeadersConstructor::isHeaders($init)) {
            /** @var JsObject $init */
            self::copyHeadersInto($headers, $init);
            return;
        }
        if (!$init instanceof JsObject) {
            throw new TypeError("Failed to construct 'Request': member headers is not a Headers, sequence, or record.");
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

    private static function normalizeMethod(string $method): string
    {
        $upper = strtoupper($method);
        if (in_array($upper, self::NORMALIZE_METHODS, true)) {
            return $upper;
        }
        if (!self::isValidMethodToken($method)) {
            throw new TypeError("'$method' is not a valid HTTP method.");
        }
        return $method;
    }

    private static function isValidMethodToken(string $method): bool
    {
        if ($method === '') {
            return false;
        }
        $len = strlen($method);
        for ($i = 0; $i < $len; $i++) {
            $c = $method[$i];
            $o = ord($c);
            $isAlpha = ($o >= 0x41 && $o <= 0x5a) || ($o >= 0x61 && $o <= 0x7a);
            $isDigit = $o >= 0x30 && $o <= 0x39;
            $isTokenSpecial = strpos("!#$%&'*+-.^_`|~", $c) !== false;
            if (!$isAlpha && !$isDigit && !$isTokenSpecial) {
                return false;
            }
        }
        return true;
    }

    /**
     * Read an enum-valued string init option, falling back to a source
     * Request's value and then to the spec default. If $valid is non-null,
     * the value must be one of those entries (else default is kept; we
     * silently swap rather than throw to mirror Chrome/Bun behavior for
     * unknown enum values during the constructor).
     *
     * @param list<string>|null $valid
     */
    private static function pickString(
        ?JsObject $sourceRequest,
        ?JsObject $initObj,
        string $slot,
        string $key,
        string $default,
        ?array $valid,
    ): string {
        $current = $default;
        if ($sourceRequest !== null) {
            $existing = $sourceRequest->getInternalProperty($slot);
            if (is_string($existing)) {
                $current = $existing;
            }
        }
        if ($initObj !== null) {
            $v = $initObj->get($key);
            if (!$v instanceof JsUndefined) {
                $str = TypeConversion::toString($v);
                if ($valid === null || in_array($str, $valid, true)) {
                    $current = $str;
                }
            }
        }
        return $current;
    }

    // ------------------------------------------------------------------
    // Prototype getters
    // ------------------------------------------------------------------

    private static function installGetters(JsObject $proto): void
    {
        $defs = [
            'method' => '[[Method]]',
            'url' => '[[Url]]',
            'destination' => '[[Destination]]',
            'mode' => '[[Mode]]',
            'credentials' => '[[Credentials]]',
            'cache' => '[[Cache]]',
            'redirect' => '[[Redirect]]',
            'referrer' => '[[Referrer]]',
            'referrerPolicy' => '[[ReferrerPolicy]]',
            'integrity' => '[[Integrity]]',
            'priority' => '[[Priority]]',
        ];
        foreach ($defs as $key => $slot) {
            self::defineStringGetter($proto, $key, $slot);
        }
        self::defineHeadersGetter($proto);
        self::defineKeepaliveGetter($proto);
        self::defineSignalGetter($proto);
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

    private static function defineHeadersGetter(JsObject $proto): void
    {
        $get = JsFunction::fromCallable(
            'get headers',
            static function (JsValue $this_): JsValue {
                self::assertReceiver($this_, 'headers');
                /** @var JsObject $this_ */
                $h = $this_->getInternalProperty('[[Headers]]');
                return $h instanceof JsObject ? $h : JsUndefined::instance();
            },
            0,
        );
        $get->setNonConstructable();
        $proto->defineOwnProperty('headers', PropertyDescriptor::accessor($get, null, false, true));
    }

    private static function defineKeepaliveGetter(JsObject $proto): void
    {
        $get = JsFunction::fromCallable(
            'get keepalive',
            static function (JsValue $this_): JsValue {
                self::assertReceiver($this_, 'keepalive');
                /** @var JsObject $this_ */
                return JsBoolean::of($this_->getInternalProperty('[[Keepalive]]') === true);
            },
            0,
        );
        $get->setNonConstructable();
        $proto->defineOwnProperty('keepalive', PropertyDescriptor::accessor($get, null, false, true));
    }

    private static function defineSignalGetter(JsObject $proto): void
    {
        $get = JsFunction::fromCallable(
            'get signal',
            static function (JsValue $this_): JsValue {
                self::assertReceiver($this_, 'signal');
                /** @var JsObject $this_ */
                $sig = $this_->getInternalProperty('[[Signal]]');
                if ($sig instanceof JsObject) {
                    return $sig;
                }
                // Per spec, a Request always has a signal — return a fresh
                // never-aborted AbortSignal if no signal was provided.
                $newSignal = self::buildDefaultSignal();
                $this_->setInternalProperty('[[Signal]]', $newSignal);
                return $newSignal;
            },
            0,
        );
        $get->setNonConstructable();
        $proto->defineOwnProperty('signal', PropertyDescriptor::accessor($get, null, false, true));
    }

    private static function buildDefaultSignal(): JsObject
    {
        $realm = \Phasis\Engine::getCurrentRealm();
        if ($realm !== null) {
            $env = $realm->getGlobalEnv();
            if ($env->has('AbortSignal')) {
                $ctor = $env->get('AbortSignal');
                if ($ctor instanceof JsObject) {
                    $proto = $ctor->get('prototype');
                    if ($proto instanceof JsObject) {
                        $sig = new JsObject($proto);
                        $sig->setInternalProperty(AbortControllerConstructor::SLOT_IS_SIGNAL, true);
                        $sig->setInternalProperty(AbortControllerConstructor::SLOT_ABORTED, false);
                        $sig->setInternalProperty(AbortControllerConstructor::SLOT_REASON, JsUndefined::instance());
                        $sig->setInternalProperty(AbortControllerConstructor::SLOT_ALGORITHMS, []);
                        EventTargetConstructor::brandAsEventTarget($sig);
                        return $sig;
                    }
                }
            }
        }
        $obj = new JsObject();
        $obj->setInternalProperty(AbortControllerConstructor::SLOT_IS_SIGNAL, true);
        $obj->setInternalProperty(AbortControllerConstructor::SLOT_ABORTED, false);
        return $obj;
    }

    private static function assertReceiver(JsValue $v, string $accessor): void
    {
        if (!self::isRequest($v)) {
            throw new TypeError("'$accessor' called on an object that does not implement interface Request");
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
                    throw new TypeError('Failed to execute \'clone\' on \'Request\': body is unusable.');
                }
                $clone = new JsObject($proto);
                self::cloneInto($clone, $this_, $env);
                return $clone;
            },
            0,
        );
        $proto->defineOwnProperty('clone', PropertyDescriptor::data($fn, true, false, true));
    }

    private static function cloneInto(JsObject $dst, JsObject $src, Environment $env): void
    {
        $dst->setInternalProperty('[[IsRequest]]', true);
        $dst->setInternalProperty('[[Method]]', $src->getInternalProperty('[[Method]]'));
        $dst->setInternalProperty('[[Url]]', $src->getInternalProperty('[[Url]]'));
        $dst->setInternalProperty('[[Mode]]', $src->getInternalProperty('[[Mode]]'));
        $dst->setInternalProperty('[[Credentials]]', $src->getInternalProperty('[[Credentials]]'));
        $dst->setInternalProperty('[[Cache]]', $src->getInternalProperty('[[Cache]]'));
        $dst->setInternalProperty('[[Redirect]]', $src->getInternalProperty('[[Redirect]]'));
        $dst->setInternalProperty('[[Referrer]]', $src->getInternalProperty('[[Referrer]]'));
        $dst->setInternalProperty('[[ReferrerPolicy]]', $src->getInternalProperty('[[ReferrerPolicy]]'));
        $dst->setInternalProperty('[[Integrity]]', $src->getInternalProperty('[[Integrity]]'));
        $dst->setInternalProperty('[[Priority]]', $src->getInternalProperty('[[Priority]]'));
        $dst->setInternalProperty('[[Keepalive]]', $src->getInternalProperty('[[Keepalive]]'));
        $dst->setInternalProperty('[[Signal]]', $src->getInternalProperty('[[Signal]]'));
        $dst->setInternalProperty('[[Destination]]', $src->getInternalProperty('[[Destination]]') ?? '');

        // Headers: deep copy via the helper so guards / brand are correct.
        $srcHeaders = $src->getInternalProperty('[[Headers]]');
        $newHeaders = HeadersConstructor::create($env, []);
        if ($srcHeaders instanceof JsObject) {
            self::copyHeadersInto($newHeaders, $srcHeaders);
        }
        $dst->setInternalProperty('[[Headers]]', $newHeaders);

        // Body: clone bytes verbatim. The cached stream is NOT carried
        // over so the clone has its own fresh stream on first .body read
        // (matches spec's "tee" semantics for the buffered case).
        $bytes = BodyMixin::bodyBytes($src);
        $ct = BodyMixin::bodyContentType($src);
        BodyMixin::markAsBodyHost($dst, $bytes, $ct);
    }
}
