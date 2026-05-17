<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WHATWG Fetch `Headers` built-in.
 *
 * Per https://fetch.spec.whatwg.org/#headers-class, `Headers` is a
 * case-insensitive multi-map with sorted iteration. Internally we store
 * a list of `[lowercaseName, value]` pairs in `[[HeadersList]]`. Names
 * are normalized to lowercase on store; iteration walks a sorted copy
 * of the list.
 *
 * Set-Cookie is special: multiple Set-Cookie values are kept as
 * separate list entries and never combined via comma. `get("Set-Cookie")`
 * returns only the first. `getSetCookie()` returns every value.
 *
 * Guards (immutable / request / request-no-cors / response / none) per
 * the spec gate which mutations are allowed. v1 ships only `none` — the
 * Request/Response constructors landing in a later phase will install
 * the appropriate guard via `setInternalProperty('[[HeadersGuard]]', ...)`
 * and this code will branch on it then.
 */
class HeadersConstructor
{
    /** Internal-slot key for the pair list. */
    public const SLOT_LIST = '[[HeadersList]]';

    /** Internal-slot key for guard ("none" | "immutable" | "request" | "request-no-cors" | "response"). */
    public const SLOT_GUARD = '[[HeadersGuard]]';

    /** Marker slot so prototype methods can authenticate the receiver. */
    public const SLOT_BRAND = '[[IsHeaders]]';

    private static ?JsObject $iteratorPrototype = null;

    /** Cache of Headers.prototype after install. */
    private static ?JsObject $prototype = null;

    public static function reset(): void
    {
        self::$iteratorPrototype = null;
        self::$prototype = null;
    }

    /**
     * Brand check used by Request/Response constructors when consuming a
     * Headers instance via the `headers` init option.
     */
    public static function isHeaders(JsValue $v): bool
    {
        return $v instanceof JsObject && $v->getInternalProperty(self::SLOT_BRAND) === true;
    }

    /**
     * Switch the guard on an existing Headers instance and re-validate the
     * stored list against the new guard. Entries that the new guard would
     * have rejected are silently dropped. Used by Request/Response
     * constructors after they know their mode/type.
     */
    public static function setGuard(JsObject $headers, string $guard): void
    {
        if (!self::isHeaders($headers)) {
            return;
        }
        $headers->setInternalProperty(self::SLOT_GUARD, $guard);
        // Re-filter the existing list: drop any pair that the guard now
        // rejects. Pairs are validated by the *combined* per-name view, so
        // we accumulate as we go and rebuild the list.
        $oldList = self::listOf($headers);
        $newList = [];
        foreach ($oldList as $pair) {
            if (self::isGuardAcceptable($newList, $guard, $pair[0], $pair[1])) {
                $newList[] = $pair;
            }
        }
        self::setList($headers, $newList);
    }

    /**
     * Build a fresh Headers JS object from PHP. Each `$initList` entry is
     * `[name, value]`; names/values pass through the same normalization
     * the JS constructor uses (lowercase + RFC 7230 validation, value
     * trimming + CR/LF/NUL guard). The result has guard "none" so it can
     * be freely mutated by the caller.
     *
     * Used by Response.json / Response.redirect and by Body extraction
     * when defaulting Content-Type for a Request.
     *
     * @param list<array{0:string,1:string}> $initList
     */
    public static function create(Environment $env, array $initList = []): JsObject
    {
        // Locate Headers.prototype from the env's installed constructor so
        // the result is `instanceof Headers` from JS.
        $proto = self::$prototype;
        if ($proto === null && $env->has('Headers')) {
            $ctor = $env->get('Headers');
            if ($ctor instanceof JsObject) {
                $maybe = $ctor->get('prototype');
                if ($maybe instanceof JsObject) {
                    $proto = $maybe;
                    self::$prototype = $proto;
                }
            }
        }
        if ($proto === null) {
            // Defensive: should not happen because Headers installs before
            // anything calls this helper, but keep a usable fallback.
            $proto = self::buildPrototype();
            self::$prototype = $proto;
        }

        $obj = new JsObject($proto);
        $obj->setInternalProperty(self::SLOT_BRAND, true);
        $obj->setInternalProperty(self::SLOT_GUARD, 'none');
        $obj->setInternalProperty(self::SLOT_LIST, []);

        foreach ($initList as $pair) {
            $name = self::normalizeName($pair[0]);
            $value = self::normalizeValue($pair[1]);
            self::appendPair($obj, $name, $value);
        }
        return $obj;
    }

    /**
     * Append a header pair to an existing Headers JS object from PHP. The
     * name/value pass through the same normalization the JS API uses;
     * the guard is currently ignored (v1 ships only "none").
     */
    public static function appendFromPhp(JsObject $headers, string $name, string $value): void
    {
        if (!self::isHeaders($headers)) {
            return;
        }
        self::appendPair($headers, self::normalizeName($name), self::normalizeValue($value));
    }

    /**
     * Set a header pair from PHP — replaces every existing entry for that
     * name with a single new one. Mirrors `Headers.prototype.set`.
     */
    public static function setFromPhp(JsObject $headers, string $name, string $value): void
    {
        if (!self::isHeaders($headers)) {
            return;
        }
        self::setPair($headers, self::normalizeName($name), self::normalizeValue($value));
    }

    /**
     * Check whether a header is already present on the list — PHP
     * counterpart of `Headers.prototype.has` used by Response.json /
     * fetch policy code to decide whether to inject a default
     * Content-Type without overriding a caller-supplied one.
     */
    public static function hasFromPhp(JsObject $headers, string $name): bool
    {
        if (!self::isHeaders($headers)) {
            return false;
        }
        $needle = strtolower($name);
        foreach (self::listOf($headers) as $pair) {
            if ($pair[0] === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read a header value by name from PHP — returns null if absent.
     * Matches `Headers.prototype.get` semantics (comma-joined multi-values
     * except for Set-Cookie, which returns the first).
     */
    public static function getFromPhp(JsObject $headers, string $name): ?string
    {
        if (!self::isHeaders($headers)) {
            return null;
        }
        $needle = strtolower($name);
        $list = self::listOf($headers);
        $matches = [];
        foreach ($list as $pair) {
            if ($pair[0] === $needle) {
                $matches[] = $pair[1];
            }
        }
        return $matches === [] ? null : implode(', ', $matches);
    }

    public static function install(Environment $env): void
    {
        self::reset();

        $iteratorIntrinsic = $env->has('__IteratorPrototype__')
            ? $env->get('__IteratorPrototype__')
            : null;
        $intrinsicProto = $iteratorIntrinsic instanceof JsObject ? $iteratorIntrinsic : null;

        $proto = self::buildPrototype();
        self::$prototype = $proto;

        $constructor = JsFunction::fromCallable(
            'Headers',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Failed to construct 'Headers': Please use the 'new' operator");
                }
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $this_->setPrototype($ntProto instanceof JsObject ? $ntProto : $proto);
                }

                $this_->setInternalProperty(self::SLOT_BRAND, true);
                $this_->setInternalProperty(self::SLOT_GUARD, 'none');
                $this_->setInternalProperty(self::SLOT_LIST, []);

                $init = $args[0] ?? JsUndefined::instance();
                self::fillFromInit($this_, $init);
                return $this_;
            },
            1,
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

        // Build iterator prototype lazily but with the correct intrinsic chain captured now.
        self::$iteratorPrototype = self::buildIteratorPrototype($intrinsicProto);

        $env->defineVar('Headers', $constructor);
    }

    /**
     * Build the public Headers.prototype.
     */
    private static function buildPrototype(): JsObject
    {
        $proto = new JsObject();

        $proto->defineOwnProperty('append', PropertyDescriptor::data(
            JsFunction::fromCallable('append', function (JsValue $this_, array $args): JsValue {
                self::assertReceiver($this_);
                if (count($args) < 2) {
                    throw new TypeError("Failed to execute 'append' on 'Headers': 2 arguments required");
                }
                $name = self::normalizeName(TypeConversion::toString($args[0]));
                $value = self::normalizeValue(TypeConversion::toString($args[1]));
                self::appendPair($this_, $name, $value);
                return JsUndefined::instance();
            }, 2),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('delete', PropertyDescriptor::data(
            JsFunction::fromCallable('delete', function (JsValue $this_, array $args): JsValue {
                self::assertReceiver($this_);
                if (count($args) < 1) {
                    throw new TypeError("Failed to execute 'delete' on 'Headers': 1 argument required");
                }
                // Respect the guard. "immutable" forbids any mutation;
                // other guards may forbid specific names (set-cookie on
                // response guard, etc).
                $guard = $this_ instanceof JsObject
                    ? ($this_->getInternalProperty(self::SLOT_GUARD) ?? 'none')
                    : 'none';
                if ($guard === 'immutable') {
                    throw new TypeError('Headers are immutable');
                }
                $name = self::normalizeName(TypeConversion::toString($args[0]));
                if (self::guardForbidsName(is_string($guard) ? $guard : 'none', $name)) {
                    return JsUndefined::instance();
                }
                $list = self::listOf($this_);
                $filtered = [];
                foreach ($list as $pair) {
                    if ($pair[0] !== $name) {
                        $filtered[] = $pair;
                    }
                }
                self::setList($this_, $filtered);
                return JsUndefined::instance();
            }, 1),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('get', PropertyDescriptor::data(
            JsFunction::fromCallable('get', function (JsValue $this_, array $args): JsValue {
                self::assertReceiver($this_);
                if (count($args) < 1) {
                    throw new TypeError("Failed to execute 'get' on 'Headers': 1 argument required");
                }
                $name = self::normalizeName(TypeConversion::toString($args[0]));
                $list = self::listOf($this_);
                // Per Fetch spec, `get` combines ALL matching values via
                // ", " regardless of header name. set-cookie has its own
                // dedicated `getSetCookie()` accessor for the split form.
                $matches = [];
                foreach ($list as $pair) {
                    if ($pair[0] === $name) {
                        $matches[] = $pair[1];
                    }
                }
                if ($matches === []) {
                    return JsNull::instance();
                }
                return new JsString(implode(', ', $matches));
            }, 1),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('getSetCookie', PropertyDescriptor::data(
            JsFunction::fromCallable('getSetCookie', function (JsValue $this_, array $args): JsValue {
                self::assertReceiver($this_);
                $out = [];
                foreach (self::listOf($this_) as $pair) {
                    if ($pair[0] === 'set-cookie') {
                        $out[] = new JsString($pair[1]);
                    }
                }
                return JsArray::fromArray($out);
            }, 0),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('has', PropertyDescriptor::data(
            JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
                self::assertReceiver($this_);
                if (count($args) < 1) {
                    throw new TypeError("Failed to execute 'has' on 'Headers': 1 argument required");
                }
                $name = self::normalizeName(TypeConversion::toString($args[0]));
                foreach (self::listOf($this_) as $pair) {
                    if ($pair[0] === $name) {
                        return new JsBoolean(true);
                    }
                }
                return new JsBoolean(false);
            }, 1),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('set', PropertyDescriptor::data(
            JsFunction::fromCallable('set', function (JsValue $this_, array $args): JsValue {
                self::assertReceiver($this_);
                if (count($args) < 2) {
                    throw new TypeError("Failed to execute 'set' on 'Headers': 2 arguments required");
                }
                $name = self::normalizeName(TypeConversion::toString($args[0]));
                $value = self::normalizeValue(TypeConversion::toString($args[1]));
                self::setPair($this_, $name, $value);
                return JsUndefined::instance();
            }, 2),
            true,
            false,
            true,
        ));

        // forEach(callback, thisArg?)
        $proto->defineOwnProperty('forEach', PropertyDescriptor::data(
            JsFunction::fromCallable('forEach', function (JsValue $this_, array $args): JsValue {
                self::assertReceiver($this_);
                $cb = $args[0] ?? JsUndefined::instance();
                if (!$cb instanceof JsFunction) {
                    throw new TypeError("Failed to execute 'forEach' on 'Headers': callback must be a function");
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                // Iteration follows the spec: sorted-by-lowercase-name snapshot,
                // combined values per name (set-cookie kept separate).
                $entries = self::sortedEntries($this_);
                foreach ($entries as $entry) {
                    $cb->call($thisArg, [
                        new JsString($entry[1]),
                        new JsString($entry[0]),
                        $this_,
                    ]);
                }
                return JsUndefined::instance();
            }, 1),
            true,
            false,
            true,
        ));

        $entriesFn = JsFunction::fromCallable('entries', function (JsValue $this_, array $args): JsValue {
            self::assertReceiver($this_);
            return self::createIterator($this_, 'entries');
        }, 0);
        $proto->defineOwnProperty('entries', PropertyDescriptor::data($entriesFn, true, false, true));

        $proto->defineOwnProperty('keys', PropertyDescriptor::data(
            JsFunction::fromCallable('keys', function (JsValue $this_, array $args): JsValue {
                self::assertReceiver($this_);
                return self::createIterator($this_, 'keys');
            }, 0),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('values', PropertyDescriptor::data(
            JsFunction::fromCallable('values', function (JsValue $this_, array $args): JsValue {
                self::assertReceiver($this_);
                return self::createIterator($this_, 'values');
            }, 0),
            true,
            false,
            true,
        ));

        // @@iterator -> entries.
        $proto->definePropertyBySymbol(
            SymbolConstructor::iterator(),
            PropertyDescriptor::data($entriesFn, true, false, true),
        );

        // @@toStringTag = "Headers".
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Headers'), false, false, true),
        );

        return $proto;
    }

    private static function buildIteratorPrototype(?JsObject $intrinsic): JsObject
    {
        $proto = new JsObject($intrinsic);

        $nextFn = JsFunction::fromCallable('next', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Headers Iterator.next called on incompatible receiver');
            }
            $stateDesc = $this_->getOwnPropertyDescriptor('[[HeadersIterState]]');
            if ($stateDesc === null) {
                throw new TypeError('Headers Iterator.next called on incompatible receiver');
            }
            $state = $stateDesc->value;
            if (!$state instanceof JsObject) {
                return self::doneResult();
            }
            $target = $state->get('target');
            if (!$target instanceof JsObject) {
                return self::doneResult();
            }
            $indexV = $state->get('index');
            $index = $indexV instanceof JsNumber ? (int) $indexV->value : 0;
            $kindV = $state->get('kind');
            $kind = $kindV instanceof JsString ? $kindV->value : 'entries';

            // Snapshot the sorted entries each call so concurrent mutations
            // affect later iterations as the WHATWG iterator algorithm
            // specifies (it walks the list with a counter and re-reads).
            $entries = self::sortedEntries($target);
            if ($index >= count($entries)) {
                return self::doneResult();
            }
            $entry = $entries[$index];
            $state->set('index', JsNumber::of((float) ($index + 1)));

            $result = new JsObject();
            $result->set('done', new JsBoolean(false));
            $result->set('value', match ($kind) {
                'keys' => new JsString($entry[0]),
                'values' => new JsString($entry[1]),
                default => JsArray::fromArray([
                    new JsString($entry[0]),
                    new JsString($entry[1]),
                ]),
            });
            return $result;
        }, 0);
        $nextFn->setNonConstructable();
        // Per the WHATWG infra "iterator prototype" object: the `next` method
        // is writable, enumerable, configurable. WPT headers-basic checks
        // these three flags explicitly.
        $proto->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, true, true));
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Headers Iterator'), false, false, true),
        );

        return $proto;
    }

    private static function doneResult(): JsObject
    {
        $r = new JsObject();
        $r->set('value', JsUndefined::instance());
        $r->set('done', new JsBoolean(true));
        return $r;
    }

    private static function createIterator(JsValue $self, string $kind): JsObject
    {
        $proto = self::$iteratorPrototype ?? self::buildIteratorPrototype(null);
        $iter = new JsObject($proto);
        $state = new JsObject();
        $state->set('target', $self);
        $state->set('index', JsNumber::of(0.0));
        $state->set('kind', new JsString($kind));
        $iter->setInternalProperty('[[HeadersIterState]]', $state);
        // Mirror as a non-enumerable own property so the next() function can
        // recover the state via getOwnPropertyDescriptor regardless of
        // internal-slot visibility (matches the URLSearchParams iterator).
        $iter->defineOwnProperty(
            '[[HeadersIterState]]',
            PropertyDescriptor::data($state, false, false, false),
        );
        return $iter;
    }

    // ------------------------------------------------------------------
    // Internal list / mutation helpers
    // ------------------------------------------------------------------

    /**
     * @return list<array{0:string,1:string}>
     */
    private static function listOf(JsValue $self): array
    {
        if (!$self instanceof JsObject) {
            return [];
        }
        $list = $self->getInternalProperty(self::SLOT_LIST);
        if (!is_array($list)) {
            return [];
        }
        /** @var list<array{0:string,1:string}> $list */
        return $list;
    }

    /**
     * @param list<array{0:string,1:string}> $list
     */
    private static function setList(JsValue $self, array $list): void
    {
        if (!$self instanceof JsObject) {
            return;
        }
        $self->setInternalProperty(self::SLOT_LIST, $list);
    }

    private static function appendPair(JsValue $self, string $name, string $value): void
    {
        if (!$self instanceof JsObject) {
            return;
        }
        $guard = $self->getInternalProperty(self::SLOT_GUARD);
        if (!is_string($guard)) {
            $guard = 'none';
        }
        if ($guard === 'immutable') {
            throw new TypeError('Headers are immutable');
        }
        $list = self::listOf($self);
        // Some guards forbid the name entirely. Some forbid based on the
        // *combined* would-be value — for those we project the post-append
        // list and re-test.
        if (self::guardForbidsName($guard, $name)) {
            return;
        }
        if (self::guardForbidsValue($guard, $name, $value)) {
            return;
        }
        $candidate = $list;
        $candidate[] = [$name, $value];
        if (!self::isGuardAcceptable($list, $guard, $name, $value)) {
            return;
        }
        self::setList($self, $candidate);
    }

    /**
     * Spec `set` algorithm: replace the value of the first matching entry,
     * then drop every subsequent matching entry. If no entry matches,
     * append a new one.
     */
    private static function setPair(JsValue $self, string $name, string $value): void
    {
        if (!$self instanceof JsObject) {
            return;
        }
        $guard = $self->getInternalProperty(self::SLOT_GUARD);
        if (!is_string($guard)) {
            $guard = 'none';
        }
        if ($guard === 'immutable') {
            throw new TypeError('Headers are immutable');
        }
        if (self::guardForbidsName($guard, $name)) {
            return;
        }
        if (self::guardForbidsValue($guard, $name, $value)) {
            return;
        }
        // For set, build the candidate list first and then check whether the
        // resulting single combined value passes the guard.
        $list = self::listOf($self);
        $seen = false;
        $newList = [];
        foreach ($list as $pair) {
            if ($pair[0] === $name) {
                if (!$seen) {
                    $newList[] = [$name, $value];
                    $seen = true;
                }
            } else {
                $newList[] = $pair;
            }
        }
        if (!$seen) {
            // Use the "would I be allowed to append this pair?" check.
            if (!self::isGuardAcceptable($list, $guard, $name, $value)) {
                return;
            }
            $newList[] = [$name, $value];
        } else {
            // The single value replaces every prior value for this name;
            // verify the new combined value is acceptable.
            if (!self::isGuardAcceptable([], $guard, $name, $value)) {
                return;
            }
        }
        self::setList($self, $newList);
    }

    // ------------------------------------------------------------------
    // Guard logic (per Fetch spec §2.2)
    // ------------------------------------------------------------------

    /**
     * Does the guard outright forbid the given name? "response" guard
     * forbids the "forbidden response-header names" set; "request-no-cors"
     * is permissive on name (the value check does the rest).
     */
    private static function guardForbidsName(string $guard, string $name): bool
    {
        if ($guard === 'response') {
            // Forbidden response-header names per the Fetch spec.
            return $name === 'set-cookie' || $name === 'set-cookie2';
        }
        if ($guard === 'request') {
            // Forbidden request-header names per the Fetch spec — a subset.
            return in_array($name, self::forbiddenRequestNames(), true)
                || self::isForbiddenRequestPrefix($name);
        }
        return false;
    }

    /**
     * Per Fetch spec §forbidden-request-header: when guard is "request" and
     * the name is one of X-HTTP-Method / X-HTTP-Method-Override /
     * X-Method-Override (byte-case-insensitive), reject if any comma-split
     * segment of the value (after HTTP-whitespace trimming) is a forbidden
     * method (CONNECT / TRACE / TRACK — byte-case-insensitive).
     */
    private static function guardForbidsValue(string $guard, string $name, string $value): bool
    {
        if ($guard !== 'request') {
            return false;
        }
        // Names are already lowercased on entry to this helper.
        if (
            $name !== 'x-http-method' &&
            $name !== 'x-http-method-override' &&
            $name !== 'x-method-override'
        ) {
            return false;
        }
        // Split on `,` and trim each segment of HTTP whitespace, then
        // case-insensitively compare to CONNECT/TRACE/TRACK.
        foreach (explode(',', $value) as $segment) {
            $trimmed = trim($segment, " \t\r\n");
            $lower = strtolower($trimmed);
            if ($lower === 'connect' || $lower === 'trace' || $lower === 'track') {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function forbiddenRequestNames(): array
    {
        return [
            'accept-charset', 'accept-encoding', 'access-control-request-headers',
            'access-control-request-method', 'connection', 'content-length',
            'cookie', 'cookie2', 'date', 'dnt', 'expect', 'host', 'keep-alive',
            'origin', 'referer', 'set-cookie', 'te', 'trailer', 'transfer-encoding',
            'upgrade', 'via',
        ];
    }

    private static function isForbiddenRequestPrefix(string $name): bool
    {
        return str_starts_with($name, 'proxy-') || str_starts_with($name, 'sec-');
    }

    /**
     * For the no-cors guard, determine whether appending [name, value] on
     * top of $existing produces an acceptable "CORS-safelisted request-
     * header". Returns true for guards that don't care about the value.
     *
     * @param list<array{0:string,1:string}> $existing
     */
    private static function isGuardAcceptable(
        array $existing,
        string $guard,
        string $name,
        string $value
    ): bool {
        if ($guard !== 'request-no-cors') {
            // Other guards either forbid the name outright (handled
            // separately) or impose no value check.
            return true;
        }
        // Only names in the safelisted set are allowed at all.
        $allowed = ['accept', 'accept-language', 'content-language', 'content-type', 'range'];
        if (!in_array($name, $allowed, true)) {
            return false;
        }
        // Compute the combined value as get() would: comma-join existing
        // matches with the new value appended.
        $values = [];
        foreach ($existing as $pair) {
            if ($pair[0] === $name) {
                $values[] = $pair[1];
            }
        }
        $values[] = $value;
        $combined = implode(', ', $values);

        if ($name === 'accept' || $name === 'accept-language' || $name === 'content-language') {
            if (strlen($combined) > 128) {
                return false;
            }
            // Disallow CORS-unsafe bytes — anything outside 0x20-0x7E
            // (except %09 HTAB) is rejected. The spec list excludes
            // 0x22 ("), 0x28 ((), 0x29 ()), 0x3A (:), 0x3C (<),
            // 0x3E (>), 0x3F (?), 0x40 (@), 0x5B ([), 0x5C (\),
            // 0x5D (]), 0x7B ({), 0x7D (}), 0x7F. accept-language /
            // content-language additionally restrict to a tiny set.
            $len = strlen($combined);
            for ($i = 0; $i < $len; $i++) {
                $b = ord($combined[$i]);
                if ($b < 0x20 && $b !== 0x09) {
                    return false;
                }
                if ($b > 0x7E) {
                    return false;
                }
                if (in_array($b, [0x22, 0x28, 0x29, 0x3A, 0x3C, 0x3E, 0x3F, 0x40, 0x5B, 0x5C, 0x5D, 0x7B, 0x7D, 0x7F], true)) {
                    return false;
                }
            }
            return true;
        }

        if ($name === 'content-type') {
            if (strlen($combined) > 128) {
                return false;
            }
            // Same byte-set restriction as above.
            $len = strlen($combined);
            for ($i = 0; $i < $len; $i++) {
                $b = ord($combined[$i]);
                if ($b < 0x20 && $b !== 0x09) {
                    return false;
                }
                if ($b > 0x7E) {
                    return false;
                }
                if (in_array($b, [0x22, 0x28, 0x29, 0x3A, 0x3C, 0x3E, 0x3F, 0x40, 0x5B, 0x5C, 0x5D, 0x7B, 0x7D, 0x7F], true)) {
                    return false;
                }
            }
            $essence = self::extractMimeEssence($combined);
            return in_array($essence, [
                'application/x-www-form-urlencoded',
                'multipart/form-data',
                'text/plain',
            ], true);
        }

        // The only remaining safelisted name reaches here: range.
        // Simple "bytes=N-M" form only.
        return (bool) preg_match('/^bytes=\d+-\d*$/', $combined);
    }

    /**
     * Extract the lowercase "type/subtype" essence of a possibly-parametered
     * MIME type. Returns empty string on parse failure.
     */
    private static function extractMimeEssence(string $value): string
    {
        $semi = strpos($value, ';');
        $essence = $semi === false ? $value : substr($value, 0, $semi);
        return strtolower(trim($essence, " \t"));
    }

    /**
     * Build the iteration view per spec §2.2.6 "Iterate over headers":
     * sort the headers by lowercase name, then for each unique name emit
     * either its comma-joined values (general case) or one entry per
     * value (set-cookie only).
     *
     * @return list<array{0:string,1:string}>
     */
    private static function sortedEntries(JsValue $self): array
    {
        $list = self::listOf($self);
        if ($list === []) {
            return [];
        }
        // Stable sort by lowercase name. Names are already lowercased on
        // store, so a plain byte-compare is byte-equal to the spec's
        // code-unit compare on ASCII (the only valid header-name charset).
        $indexed = [];
        foreach ($list as $i => $pair) {
            $indexed[] = [$i, $pair];
        }
        usort($indexed, function ($a, $b) {
            $r = strcmp($a[1][0], $b[1][0]);
            if ($r !== 0) {
                return $r;
            }
            return $a[0] <=> $b[0];
        });

        // Group: contiguous runs of identical names. Combine via ", "
        // except for set-cookie which stays uncombined.
        $entries = [];
        $i = 0;
        $n = count($indexed);
        while ($i < $n) {
            $name = $indexed[$i][1][0];
            if ($name === 'set-cookie') {
                $entries[] = [$name, $indexed[$i][1][1]];
                $i++;
                continue;
            }
            $values = [];
            while ($i < $n && $indexed[$i][1][0] === $name) {
                $values[] = $indexed[$i][1][1];
                $i++;
            }
            $entries[] = [$name, implode(', ', $values)];
        }
        return $entries;
    }

    // ------------------------------------------------------------------
    // Validation / normalization (per RFC 7230 §3.2.6 + Fetch spec)
    // ------------------------------------------------------------------

    /**
     * Validate + lowercase a header name. Per the Fetch spec a name is a
     * non-empty sequence of HTTP token chars (RFC 7230 §3.2.6).
     */
    private static function normalizeName(string $name): string
    {
        if ($name === '' || !self::isValidHeaderName($name)) {
            throw new TypeError("Invalid header name: '$name'");
        }
        return strtolower($name);
    }

    /**
     * Validate + strip a header value. The input MUST first be a valid
     * ByteString (every UTF-16 code unit ≤ 0xFF) — that's the WebIDL
     * conversion. Then leading/trailing HTTP whitespace (SPACE, TAB, CR,
     * LF) is removed; the result must contain no raw CR, LF, or NUL.
     *
     * The string we receive here is already the PHP UTF-8 representation
     * of the JS string. We check the original code-unit range by walking
     * the UTF-8 bytes — any non-ASCII multi-byte sequence whose decoded
     * code point exceeds 0xFF triggers the ByteString check.
     */
    private static function normalizeValue(string $value): string
    {
        if (!self::isValidByteString($value)) {
            throw new TypeError('Invalid header value: not a valid ByteString');
        }
        // HTTP whitespace per the spec: HTAB, LF, CR, SPACE.
        $trimmed = trim($value, " \t\r\n");
        if (!self::isValidHeaderValue($trimmed)) {
            throw new TypeError('Invalid header value (contains CR, LF, or NUL)');
        }
        return $trimmed;
    }

    /**
     * True iff every JS UTF-16 code unit of $value is ≤ 0xFF (i.e. the
     * value is a valid WebIDL ByteString). Phasis strings are stored as
     * UTF-8 internally, so we decode and check each code point.
     */
    private static function isValidByteString(string $value): bool
    {
        $len = strlen($value);
        $i = 0;
        while ($i < $len) {
            $b = ord($value[$i]);
            if ($b < 0x80) {
                $i++;
                continue;
            }
            if (($b & 0xE0) === 0xC0) {
                // 2-byte UTF-8 sequence: max code point 0x7FF, which can
                // exceed 0xFF (e.g. ÿ is C3 BF). Decode and check.
                if ($i + 1 >= $len) {
                    return false;
                }
                $b2 = ord($value[$i + 1]);
                $cp = (($b & 0x1F) << 6) | ($b2 & 0x3F);
                if ($cp > 0xFF) {
                    return false;
                }
                $i += 2;
                continue;
            }
            // 3- or 4-byte sequence — automatic > 0xFF.
            return false;
        }
        return true;
    }

    private static function isValidHeaderName(string $name): bool
    {
        // RFC 7230 token: 1*tchar where tchar is
        //   "!" / "#" / "$" / "%" / "&" / "'" / "*" / "+" / "-" / "."
        //   "^" / "_" / "`" / "|" / "~" / DIGIT / ALPHA
        $len = strlen($name);
        for ($i = 0; $i < $len; $i++) {
            $c = $name[$i];
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

    private static function isValidHeaderValue(string $value): bool
    {
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $o = ord($value[$i]);
            if ($o === 0x00 || $o === 0x0a || $o === 0x0d) {
                return false;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Init handling: Headers / pair-array / record
    // ------------------------------------------------------------------

    private static function fillFromInit(JsObject $headers, JsValue $init): void
    {
        // `undefined` is missing-init; treat as no-op per WebIDL "optional".
        if ($init instanceof JsUndefined) {
            return;
        }
        // `null` is NOT a valid HeadersInit per the WHATWG IDL — the union
        // (sequence<sequence<ByteString>> or record<ByteString, ByteString>)
        // doesn't include nullable. WebIDL conversion rejects null with
        // TypeError. WPT explicitly tests this.
        if ($init instanceof JsNull) {
            throw new TypeError(
                "Failed to construct 'Headers': The provided value is null."
            );
        }
        if (!$init instanceof JsObject) {
            throw new TypeError(
                "Failed to construct 'Headers': init must be a Headers, a sequence of pairs, or a record"
            );
        }

        // Per WebIDL overload resolution: check for Symbol.iterator FIRST.
        // Any object (including a Headers instance) with a custom iterator
        // is treated as a sequence — that's the WPT
        // "Create headers with existing headers with custom iterator" case.
        $iterSym = SymbolConstructor::iterator();
        $iteratorMethod = $init->getBySymbol($iterSym);
        if ($iteratorMethod instanceof JsFunction) {
            self::fillFromPairIterable($headers, $init, $iteratorMethod);
            return;
        }

        // Case 1b: another Headers instance with no custom iterator — copy
        // its list verbatim (preserves multi-values and set-cookie
        // multiplicity). This branch is rarely hit because Headers.prototype
        // exposes a Symbol.iterator method, but defining it as `undefined`
        // on the instance lands us here.
        if ($init->getInternalProperty(self::SLOT_BRAND) === true) {
            foreach (self::listOf($init) as $pair) {
                self::appendPair($headers, $pair[0], $pair[1]);
            }
            return;
        }

        // Case 3: record / plain object. Per WebIDL es-to-record:
        //   For each key in [[OwnPropertyKeys]](init):  ← includes Symbols
        //     1. let desc = [[GetOwnProperty]](key)
        //     2. If desc !== undefined and desc.enumerable:
        //        a. Convert key to ByteString (throws on Symbol or out-of-
        //           range chars).
        //        b. [[Get]](init, key) (now that the key is known valid).
        //        c. Convert value to ByteString.
        //        d. record[byteKey] = byteValue.
        //   The order matters for Proxy traps — WPT headers-record verifies
        //   the exact sequence of trap invocations.
        $allKeys = $init->ordinaryOwnPropertyKeys();
        foreach ($allKeys as $key) {
            if ($key instanceof \Phasis\Value\JsSymbol) {
                // Spec: still do the [[GetOwnProperty]] (so Proxy trap fires).
                $desc = $init->getSymbolPropertyDescriptor($key);
                if ($desc === null || !($desc->enumerable ?? false)) {
                    continue;
                }
                // Symbol cannot be converted to a ByteString — throws.
                throw new TypeError(
                    "Cannot convert a Symbol value to a string"
                );
            }
            if (!$key instanceof JsString) {
                // ordinaryOwnPropertyKeys returns JsString | JsSymbol; the
                // symbol branch above handled the latter so anything else
                // is unreachable. Defence in depth.
                continue;
            }
            $keyStr = $key->value;
            $desc = $init->getOwnPropertyDescriptor($keyStr);
            if ($desc === null || !($desc->enumerable ?? false)) {
                continue;
            }
            // Convert key to a valid ByteString header name before the [[Get]].
            $name = self::normalizeName($keyStr);
            $val = $init->get($keyStr);
            $value = self::normalizeValue(TypeConversion::toString($val));
            self::appendPair($headers, $name, $value);
        }
    }

    private static function fillFromPairIterable(
        JsObject $headers,
        JsObject $iterable,
        JsFunction $iteratorMethod
    ): void {
        $iterator = $iteratorMethod->call($iterable, []);
        if (!$iterator instanceof JsObject) {
            throw new TypeError('Headers init: iterator must return an object');
        }
        $next = $iterator->get('next');
        if (!$next instanceof JsFunction) {
            throw new TypeError('Headers init: iterator missing next()');
        }
        while (true) {
            $step = $next->call($iterator, []);
            if (!$step instanceof JsObject) {
                throw new TypeError('Headers init: iterator result must be an object');
            }
            if (TypeConversion::toBoolean($step->get('done'))) {
                break;
            }
            $entry = $step->get('value');
            if (!$entry instanceof JsObject) {
                throw new TypeError('Headers init: pair must be an iterable of two items');
            }
            $entryIter = $entry->getBySymbol(SymbolConstructor::iterator());
            if (!$entryIter instanceof JsFunction) {
                throw new TypeError('Headers init: pair is not iterable');
            }
            $sub = $entryIter->call($entry, []);
            if (!$sub instanceof JsObject) {
                throw new TypeError('Headers init: pair iterator must return an object');
            }
            $subNext = $sub->get('next');
            if (!$subNext instanceof JsFunction) {
                throw new TypeError('Headers init: pair iterator missing next()');
            }
            $items = [];
            for ($i = 0; $i < 3; $i++) {
                $st = $subNext->call($sub, []);
                if (!$st instanceof JsObject) {
                    throw new TypeError('Headers init: iterator step must return an object');
                }
                if (TypeConversion::toBoolean($st->get('done'))) {
                    break;
                }
                $items[] = $st->get('value');
            }
            if (count($items) !== 2) {
                throw new TypeError(
                    "Failed to construct 'Headers': each header pair must have exactly 2 elements"
                );
            }
            $name = self::normalizeName(TypeConversion::toString($items[0]));
            $value = self::normalizeValue(TypeConversion::toString($items[1]));
            self::appendPair($headers, $name, $value);
        }
    }

    private static function assertReceiver(JsValue $v): void
    {
        if (!$v instanceof JsObject || $v->getInternalProperty(self::SLOT_BRAND) !== true) {
            throw new TypeError('Headers method called on incompatible receiver');
        }
    }
}
