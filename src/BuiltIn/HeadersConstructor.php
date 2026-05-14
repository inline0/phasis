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
        if ($needle === 'set-cookie') {
            foreach ($list as $pair) {
                if ($pair[0] === $needle) {
                    return $pair[1];
                }
            }
            return null;
        }
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
                $name = self::normalizeName(TypeConversion::toString($args[0]));
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
                if ($name === 'set-cookie') {
                    foreach ($list as $pair) {
                        if ($pair[0] === $name) {
                            return new JsString($pair[1]);
                        }
                    }
                    return JsNull::instance();
                }
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
        $proto->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));
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
        $list = self::listOf($self);
        $list[] = [$name, $value];
        self::setList($self, $list);
    }

    /**
     * Spec `set` algorithm: replace the value of the first matching entry,
     * then drop every subsequent matching entry. If no entry matches,
     * append a new one.
     */
    private static function setPair(JsValue $self, string $name, string $value): void
    {
        $list = self::listOf($self);
        $seen = false;
        $newList = [];
        foreach ($list as $pair) {
            if ($pair[0] === $name) {
                if (!$seen) {
                    $newList[] = [$name, $value];
                    $seen = true;
                }
                // Drop subsequent matches.
            } else {
                $newList[] = $pair;
            }
        }
        if (!$seen) {
            $newList[] = [$name, $value];
        }
        self::setList($self, $newList);
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
     * Validate + strip a header value. Leading/trailing HTTP whitespace
     * (SPACE, TAB, CR, LF) is removed; the resulting body must contain no
     * raw CR, LF, or NUL.
     */
    private static function normalizeValue(string $value): string
    {
        // HTTP whitespace per the spec: HTAB, LF, CR, SPACE.
        $trimmed = trim($value, " \t\r\n");
        if (!self::isValidHeaderValue($trimmed)) {
            throw new TypeError('Invalid header value (contains CR, LF, or NUL)');
        }
        return $trimmed;
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
        if ($init instanceof JsUndefined || $init instanceof JsNull) {
            return;
        }
        if (!$init instanceof JsObject) {
            throw new TypeError(
                "Failed to construct 'Headers': init must be a Headers, a sequence of pairs, or a record"
            );
        }

        // Case 1: another Headers instance — copy its list verbatim
        // (preserves multi-values and set-cookie multiplicity).
        if ($init->getInternalProperty(self::SLOT_BRAND) === true) {
            foreach (self::listOf($init) as $pair) {
                self::appendPair($headers, $pair[0], $pair[1]);
            }
            return;
        }

        // Case 2: pair-sequence (anything iterable). The spec checks for
        // Symbol.iterator before falling through to record handling.
        $iterSym = SymbolConstructor::iterator();
        $iteratorMethod = $init->getBySymbol($iterSym);
        if ($iteratorMethod instanceof JsFunction) {
            self::fillFromPairIterable($headers, $init, $iteratorMethod);
            return;
        }

        // Case 3: record / plain object. Enumerate own enumerable string
        // keys in insertion order; each value is ToString'd.
        foreach ($init->ownKeys() as $key) {
            $desc = $init->getOwnPropertyDescriptor($key);
            if ($desc === null || !($desc->enumerable ?? false)) {
                continue;
            }
            $val = $init->get($key);
            $name = self::normalizeName($key);
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
