<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Url;

use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * URLSearchParams §6 + application/x-www-form-urlencoded parsing /
 * serializing.
 *
 * The constructor returns a JsObject with two internal slots:
 *
 *  - "[[SearchParamsList]]"   list<array{0:string, 1:string}> (insertion order)
 *  - "[[SearchParamsUrl]]"    UrlRecord|null — when set, mutations write
 *                             back to the URL's `query` via syncTo* methods.
 *
 * The shape lets `url.searchParams` return a stable JS object whose
 * mutations live-link back to the parent URL.
 */
final class SearchParams
{
    private static ?JsObject $prototype = null;
    private static ?JsFunction $constructor = null;
    private static ?JsObject $iteratorPrototype = null;
    private static ?JsObject $intrinsicIteratorPrototype = null;

    public static function reset(): void
    {
        self::$prototype = null;
        self::$constructor = null;
        self::$iteratorPrototype = null;
        self::$intrinsicIteratorPrototype = null;
    }

    public static function setIntrinsicIteratorPrototype(?JsObject $proto): void
    {
        self::$intrinsicIteratorPrototype = $proto;
    }

    public static function getPrototype(): JsObject
    {
        if (self::$prototype === null) {
            self::buildPrototype();
        }
        /** @var JsObject $proto */
        $proto = self::$prototype;
        return $proto;
    }

    public static function getConstructor(): JsFunction
    {
        if (self::$constructor === null) {
            self::buildConstructor();
        }
        /** @var JsFunction $ctor */
        $ctor = self::$constructor;
        return $ctor;
    }

    /**
     * Create a fresh URLSearchParams JS object with the given pairs list.
     * Used by the URL `searchParams` getter to wrap the URL's query.
     *
     * @param list<array{0:string,1:string}> $pairs
     */
    public static function createObject(array $pairs, ?UrlRecord $linkedUrl = null): JsObject
    {
        $obj = new JsObject(self::getPrototype());
        $obj->setInternalProperty('[[SearchParamsList]]', $pairs);
        $obj->setInternalProperty('[[SearchParamsUrl]]', $linkedUrl);
        return $obj;
    }

    /**
     * Parse an application/x-www-form-urlencoded string into pairs.
     *
     * Per spec, input is bytes; we treat the string as UTF-8 with `+` →
     * space substitution then percent-decode.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function parseQuery(string $input): array
    {
        if ($input === '') {
            return [];
        }
        $pairs = [];
        $sequences = explode('&', $input);
        foreach ($sequences as $bytes) {
            if ($bytes === '') {
                continue;
            }
            $eq = strpos($bytes, '=');
            if ($eq === false) {
                $name = $bytes;
                $value = '';
            } else {
                $name = substr($bytes, 0, $eq);
                $value = substr($bytes, $eq + 1);
            }
            $name = str_replace('+', ' ', $name);
            $value = str_replace('+', ' ', $value);
            $pairs[] = [UrlParser::percentDecode($name), UrlParser::percentDecode($value)];
        }
        return $pairs;
    }

    /**
     * Serialize pairs back to "k=v&k=v" form, percent-encoding per the
     * form-urlencoded set.
     *
     * @param list<array{0:string,1:string}> $pairs
     */
    public static function serializeQuery(array $pairs): string
    {
        $out = '';
        $first = true;
        foreach ($pairs as $pair) {
            if (!$first) {
                $out .= '&';
            }
            $first = false;
            $out .= self::encodeFormComponent($pair[0]);
            $out .= '=';
            $out .= self::encodeFormComponent($pair[1]);
        }
        return $out;
    }

    private static function encodeFormComponent(string $input): string
    {
        $out = '';
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($input[$i]);
            if ($b === 0x20) {
                $out .= '+';
                continue;
            }
            if (
                $b === 0x2a || $b === 0x2d || $b === 0x2e
                || ($b >= 0x30 && $b <= 0x39)
                || ($b >= 0x41 && $b <= 0x5a)
                || $b === 0x5f
                || ($b >= 0x61 && $b <= 0x7a)
            ) {
                $out .= chr($b);
            } else {
                $hex = strtoupper(dechex($b));
                if (strlen($hex) === 1) {
                    $hex = '0' . $hex;
                }
                $out .= '%' . $hex;
            }
        }
        return $out;
    }

    /**
     * After a mutation on the searchParams JS object, write the serialized
     * query back to the linked URL (when present).
     */
    public static function syncToUrl(JsObject $self): void
    {
        $url = $self->getInternalProperty('[[SearchParamsUrl]]');
        if (!$url instanceof UrlRecord) {
            return;
        }
        /** @var list<array{0:string,1:string}> $pairs */
        $pairs = $self->getInternalProperty('[[SearchParamsList]]') ?? [];
        $serialized = self::serializeQuery($pairs);
        $url->query = $serialized === '' ? null : $serialized;
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private static function listOf(JsValue $self): array
    {
        if (!$self instanceof JsObject) {
            return [];
        }
        $list = $self->getInternalProperty('[[SearchParamsList]]');
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
        $self->setInternalProperty('[[SearchParamsList]]', $list);
        self::syncToUrl($self);
    }

    // ---- prototype build --------------------------------------------------

    private static function buildPrototype(): void
    {
        $proto = new JsObject();
        self::$prototype = $proto;

        $proto->defineOwnProperty('append', PropertyDescriptor::data(
            JsFunction::fromCallable('append', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                if (count($args) < 2) {
                    throw new TypeError('URLSearchParams.append requires 2 arguments');
                }
                $list = self::listOf($this_);
                $list[] = [TypeConversion::toString($args[0]), TypeConversion::toString($args[1])];
                self::setList($this_, $list);
                return JsUndefined::instance();
            }, 2),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('delete', PropertyDescriptor::data(
            JsFunction::fromCallable('delete', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                if (count($args) < 1) {
                    throw new TypeError('URLSearchParams.delete requires 1 argument');
                }
                $name = TypeConversion::toString($args[0]);
                $valueGiven = count($args) >= 2 && !$args[1] instanceof JsUndefined;
                $value = $valueGiven ? TypeConversion::toString($args[1]) : null;
                $filtered = [];
                foreach (self::listOf($this_) as $pair) {
                    if ($pair[0] === $name && ($value === null || $pair[1] === $value)) {
                        continue;
                    }
                    $filtered[] = $pair;
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
                self::assert($this_);
                if (count($args) < 1) {
                    throw new TypeError('URLSearchParams.get requires 1 argument');
                }
                $name = TypeConversion::toString($args[0]);
                foreach (self::listOf($this_) as $pair) {
                    if ($pair[0] === $name) {
                        return new JsString($pair[1]);
                    }
                }
                return \Phasis\Value\JsNull::instance();
            }, 1),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('getAll', PropertyDescriptor::data(
            JsFunction::fromCallable('getAll', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                if (count($args) < 1) {
                    throw new TypeError('URLSearchParams.getAll requires 1 argument');
                }
                $name = TypeConversion::toString($args[0]);
                $matches = [];
                foreach (self::listOf($this_) as $pair) {
                    if ($pair[0] === $name) {
                        $matches[] = new JsString($pair[1]);
                    }
                }
                return JsArray::fromArray($matches);
            }, 1),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('has', PropertyDescriptor::data(
            JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                if (count($args) < 1) {
                    throw new TypeError('URLSearchParams.has requires 1 argument');
                }
                $name = TypeConversion::toString($args[0]);
                $valueGiven = count($args) >= 2 && !$args[1] instanceof JsUndefined;
                $value = $valueGiven ? TypeConversion::toString($args[1]) : null;
                foreach (self::listOf($this_) as $pair) {
                    if ($pair[0] === $name && ($value === null || $pair[1] === $value)) {
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
                self::assert($this_);
                if (count($args) < 2) {
                    throw new TypeError('URLSearchParams.set requires 2 arguments');
                }
                $name = TypeConversion::toString($args[0]);
                $value = TypeConversion::toString($args[1]);
                $list = self::listOf($this_);
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
                self::setList($this_, $newList);
                return JsUndefined::instance();
            }, 2),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('sort', PropertyDescriptor::data(
            JsFunction::fromCallable('sort', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                $list = self::listOf($this_);
                // Spec sort: stable, ordered by code-unit values of the
                // name. UTF-16 code units differ from PHP's byte order
                // only above U+007F; for v1 we rely on JS strings being
                // mb-safe and use mb_strcmp-like behavior via UTF-8 byte
                // compare which agrees in the BMP except for surrogate
                // pairs (rare in practice).
                $indexed = [];
                foreach ($list as $i => $pair) {
                    $indexed[] = [$i, $pair];
                }
                usort($indexed, function ($a, $b) {
                    $r = self::compareUtf16($a[1][0], $b[1][0]);
                    if ($r !== 0) {
                        return $r;
                    }
                    return $a[0] - $b[0];
                });
                $sorted = array_map(fn($e) => $e[1], $indexed);
                self::setList($this_, $sorted);
                return JsUndefined::instance();
            }, 0),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('toString', PropertyDescriptor::data(
            JsFunction::fromCallable('toString', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                return new JsString(self::serializeQuery(self::listOf($this_)));
            }, 0),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('forEach', PropertyDescriptor::data(
            JsFunction::fromCallable('forEach', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                $cb = $args[0] ?? JsUndefined::instance();
                if (!$cb instanceof JsFunction) {
                    throw new TypeError('forEach callback must be a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                $i = 0;
                // Snapshot length but read list live each iteration so
                // mutation during forEach behaves per spec.
                while (true) {
                    $list = self::listOf($this_);
                    if ($i >= count($list)) {
                        break;
                    }
                    $pair = $list[$i];
                    $cb->call($thisArg, [
                        new JsString($pair[1]),
                        new JsString($pair[0]),
                        $this_,
                    ]);
                    $i++;
                }
                return JsUndefined::instance();
            }, 1),
            true,
            false,
            true,
        ));

        $entriesFn = JsFunction::fromCallable('entries', function (JsValue $this_, array $args): JsValue {
            self::assert($this_);
            return self::createIterator($this_, 'entries');
        }, 0);
        $proto->defineOwnProperty('entries', PropertyDescriptor::data($entriesFn, true, false, true));

        $proto->defineOwnProperty('keys', PropertyDescriptor::data(
            JsFunction::fromCallable('keys', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                return self::createIterator($this_, 'keys');
            }, 0),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('values', PropertyDescriptor::data(
            JsFunction::fromCallable('values', function (JsValue $this_, array $args): JsValue {
                self::assert($this_);
                return self::createIterator($this_, 'values');
            }, 0),
            true,
            false,
            true,
        ));

        // size accessor.
        $sizeGetter = JsFunction::fromCallable('get size', function (JsValue $this_): JsValue {
            self::assert($this_);
            return JsNumber::of((float) count(self::listOf($this_)));
        }, 0);
        $proto->defineOwnProperty('size', PropertyDescriptor::accessor(
            get: $sizeGetter,
            set: null,
            enumerable: true,
            configurable: true,
        ));

        // @@iterator → entries.
        $proto->definePropertyBySymbol(
            SymbolConstructor::iterator(),
            PropertyDescriptor::data($entriesFn, true, false, true),
        );

        // @@toStringTag.
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('URLSearchParams'), false, false, true),
        );
    }

    private static function buildConstructor(): void
    {
        $proto = self::getPrototype();
        $ctor = JsFunction::fromCallable(
            'URLSearchParams',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                $init = $args[0] ?? JsUndefined::instance();

                // Allow both `new URLSearchParams()` and bare-call (per
                // legacy compat). The latter is non-standard but harmless.
                if ($this_ instanceof JsObject && $this_->has('[[NewTarget]]')) {
                    $obj = $this_;
                    $newTarget = $this_->get('[[NewTarget]]');
                    if ($newTarget instanceof JsFunction) {
                        $ntProto = $newTarget->get('prototype');
                        if ($ntProto instanceof JsObject) {
                            $obj->setPrototype($ntProto);
                        } else {
                            $obj->setPrototype($proto);
                        }
                    } else {
                        $obj->setPrototype($proto);
                    }
                } else {
                    $obj = new JsObject($proto);
                }

                $pairs = self::pairsFromInit($init);
                $obj->setInternalProperty('[[SearchParamsList]]', $pairs);
                $obj->setInternalProperty('[[SearchParamsUrl]]', null);
                return $obj;
            },
            1,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($ctor, true, false, true));
        self::$constructor = $ctor;
    }

    /**
     * Convert constructor argument into the pair list per spec algorithm.
     *
     * @return list<array{0:string,1:string}>
     */
    private static function pairsFromInit(JsValue $init): array
    {
        if ($init instanceof JsUndefined || $init instanceof \Phasis\Value\JsNull) {
            return [];
        }
        // Another URLSearchParams: clone its list.
        if ($init instanceof JsObject && $init->getInternalProperty('[[SearchParamsList]]') !== null) {
            /** @var list<array{0:string,1:string}> $existing */
            $existing = $init->getInternalProperty('[[SearchParamsList]]');
            $copy = [];
            foreach ($existing as $pair) {
                $copy[] = [$pair[0], $pair[1]];
            }
            return $copy;
        }

        // Array of pairs (any iterable object with Symbol.iterator yielding
        // pair-like entries).
        if ($init instanceof JsObject) {
            $iterSym = SymbolConstructor::iterator();
            $iteratorMethod = $init->getBySymbol($iterSym);
            if ($iteratorMethod instanceof JsFunction) {
                return self::pairsFromIterable($init, $iteratorMethod);
            }

            // Record: own enumerable own-keys → string values.
            $pairs = [];
            foreach ($init->ownKeys() as $key) {
                $desc = $init->getOwnPropertyDescriptor($key);
                if ($desc !== null && ($desc->enumerable ?? false)) {
                    $val = $init->get($key);
                    $pairs[] = [$key, TypeConversion::toString($val)];
                }
            }
            return $pairs;
        }

        // String form.
        $str = TypeConversion::toString($init);
        if ($str !== '' && $str[0] === '?') {
            $str = substr($str, 1);
        }
        return self::parseQuery($str);
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    private static function pairsFromIterable(JsObject $obj, JsFunction $iteratorMethod): array
    {
        $iterator = $iteratorMethod->call($obj, []);
        if (!$iterator instanceof JsObject) {
            throw new TypeError('iterator must return an object');
        }
        $next = $iterator->get('next');
        if (!$next instanceof JsFunction) {
            throw new TypeError('iterator missing next()');
        }
        $pairs = [];
        while (true) {
            $step = $next->call($iterator, []);
            if (!$step instanceof JsObject) {
                throw new TypeError('iterator result must be an object');
            }
            if (TypeConversion::toBoolean($step->get('done'))) {
                break;
            }
            $entry = $step->get('value');
            if (!$entry instanceof JsObject) {
                throw new TypeError('URLSearchParams init pair must be an iterable');
            }
            // Each entry must itself be a 2-element iterable.
            $entryIter = $entry->getBySymbol(SymbolConstructor::iterator());
            if (!$entryIter instanceof JsFunction) {
                throw new TypeError('URLSearchParams init pair is not iterable');
            }
            $sub = $entryIter->call($entry, []);
            if (!$sub instanceof JsObject) {
                throw new TypeError('URLSearchParams init pair iterator must return an object');
            }
            $subNext = $sub->get('next');
            if (!$subNext instanceof JsFunction) {
                throw new TypeError('URLSearchParams init pair iterator missing next()');
            }
            $items = [];
            for ($i = 0; $i < 3; $i++) {
                $st = $subNext->call($sub, []);
                if (!$st instanceof JsObject) {
                    throw new TypeError('iterator step must return an object');
                }
                if (TypeConversion::toBoolean($st->get('done'))) {
                    break;
                }
                $items[] = $st->get('value');
            }
            if (count($items) !== 2) {
                throw new TypeError(
                    'URLSearchParams init: each pair must have exactly 2 elements',
                );
            }
            $pairs[] = [
                TypeConversion::toString($items[0]),
                TypeConversion::toString($items[1]),
            ];
        }
        return $pairs;
    }

    // ---- iterator --------------------------------------------------------

    private static function getIteratorPrototype(): JsObject
    {
        if (self::$iteratorPrototype !== null) {
            return self::$iteratorPrototype;
        }
        // Inherit %IteratorPrototype% so Array.from() / spread can pull
        // entries via the spread protocol. When the intrinsic was not
        // captured (test isolation), fall back to a bare object — direct
        // .next() iteration still works.
        $proto = new JsObject(self::$intrinsicIteratorPrototype);
        $nextFn = JsFunction::fromCallable('next', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('not a URLSearchParams iterator');
            }
            $stateDesc = $this_->getOwnPropertyDescriptor('[[USPIterState]]');
            if ($stateDesc === null) {
                throw new TypeError('not a URLSearchParams iterator');
            }
            $state = $stateDesc->value;
            if (!$state instanceof JsObject) {
                $r = new JsObject();
                $r->set('value', JsUndefined::instance());
                $r->set('done', new JsBoolean(true));
                return $r;
            }
            $target = $state->get('target');
            if (!$target instanceof JsObject) {
                $r = new JsObject();
                $r->set('value', JsUndefined::instance());
                $r->set('done', new JsBoolean(true));
                return $r;
            }
            $indexV = $state->get('index');
            $index = $indexV instanceof JsNumber ? (int) $indexV->value : 0;
            $kindV = $state->get('kind');
            $kind = $kindV instanceof JsString ? $kindV->value : 'entries';

            $list = self::listOf($target);
            if ($index >= count($list)) {
                $r = new JsObject();
                $r->set('value', JsUndefined::instance());
                $r->set('done', new JsBoolean(true));
                return $r;
            }
            $pair = $list[$index];
            $state->set('index', JsNumber::of((float) ($index + 1)));
            $r = new JsObject();
            $r->set('done', new JsBoolean(false));
            $r->set('value', match ($kind) {
                'keys' => new JsString($pair[0]),
                'values' => new JsString($pair[1]),
                default => JsArray::fromArray([new JsString($pair[0]), new JsString($pair[1])]),
            });
            return $r;
        }, 0);
        $nextFn->setNonConstructable();
        $proto->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('URLSearchParams Iterator'), false, false, true),
        );
        self::$iteratorPrototype = $proto;
        return $proto;
    }

    private static function createIterator(JsValue $self, string $kind): JsObject
    {
        $proto = self::getIteratorPrototype();
        $iter = new JsObject($proto);
        $state = new JsObject();
        $state->set('target', $self);
        $state->set('index', JsNumber::of(0.0));
        $state->set('kind', new JsString($kind));
        $iter->setInternalProperty('[[USPIterState]]', $state);
        // Mirror as a hidden descriptor so the next() function picks it up
        // through getOwnPropertyDescriptor (matches Map iterator pattern).
        $iter->defineOwnProperty('[[USPIterState]]', PropertyDescriptor::data($state, false, false, false));
        return $iter;
    }

    private static function assert(JsValue $v): void
    {
        if (
            !$v instanceof JsObject
            || $v->getInternalProperty('[[SearchParamsList]]') === null
        ) {
            throw new TypeError('Method called on incompatible receiver (not URLSearchParams)');
        }
    }

    /**
     * Compare two UTF-8 strings as JS would compare their UTF-16 code-unit
     * sequences. The pragma is straightforward: decode both to UTF-16 code
     * units, then lexicographic compare. We use mb_convert_encoding which
     * ships with phasis's required ext-mbstring.
     */
    private static function compareUtf16(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        // PHP 8.5's mb_convert_encoding signature returns string for
        // string input — no false fallback path needed.
        $au = mb_convert_encoding($a, 'UTF-16BE', 'UTF-8');
        $bu = mb_convert_encoding($b, 'UTF-16BE', 'UTF-8');
        return strcmp($au, $bu);
    }
}
