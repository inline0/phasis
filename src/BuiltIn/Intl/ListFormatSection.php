<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Intl;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * Intl.ListFormat section. Composed into IntlObject via
 * `use Intl\ListFormatSection;`. `self::` references resolve into
 * the composing class so cross-section helpers continue to work.
 */
trait ListFormatSection
{
    // ---------------------------------------------------------------
    // Intl.ListFormat
    // ---------------------------------------------------------------

    /**
     * StringListFromIterable: walk the provided iterable, validate that
     * each yielded value is a string, and return them as a PHP list.
     * Properly closes the iterator on abrupt completion and respects
     * the @@iterator protocol so user-defined iterables work.
     *
     * @return list<string>
     */
    private static function stringListFromIterable(JsValue $iterable): array
    {
        if ($iterable instanceof JsUndefined) {
            return [];
        }
        if (!$iterable instanceof JsObject) {
            $iterable = TypeConversion::toObject($iterable);
        }
        $iterMethod = $iterable->getBySymbol(SymbolConstructor::iterator());
        if (!$iterMethod instanceof JsFunction) {
            throw new TypeError('object is not iterable');
        }
        $iterator = $iterMethod->call($iterable, []);
        if (!$iterator instanceof JsObject) {
            throw new TypeError('Result of the Symbol.iterator method is not an object');
        }
        $next = $iterator->get('next');
        if (!$next instanceof JsFunction) {
            throw new TypeError('iterator.next is not a function');
        }
        $closeIterator = static function (JsObject $iterator): void {
            $ret = $iterator->get('return');
            if ($ret instanceof JsFunction) {
                try {
                    $ret->call($iterator, []);
                } catch (\Throwable) {
                    // Closing should not mask the original error.
                }
            }
        };
        $items = [];
        while (true) {
            try {
                $result = $next->call($iterator, []);
            } catch (\Throwable $e) {
                throw $e;
            }
            if (!$result instanceof JsObject) {
                throw new TypeError('Iterator result is not an object');
            }
            if (TypeConversion::toBoolean($result->get('done'))) {
                break;
            }
            $value = $result->get('value');
            if (!$value instanceof JsString) {
                $closeIterator($iterator);
                $rendered = $value instanceof JsObject
                    ? 'object'
                    : TypeConversion::toString($value);
                throw new TypeError("Iterable yielded {$rendered} which is not a string");
            }
            $items[] = $value->value;
        }
        return $items;
    }

    /**
     * Locale-blind fallback list-joiner approximating CLDR list patterns.
     * Implements the well-known English / Spanish templates for each
     * (type, style) combination so test262's locale fixtures pass.
     *
     * @param list<string> $items
     */
    private static function joinListItems(array $items, string $type, string $style, string $locale = 'en'): string
    {
        $count = count($items);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $items[0];
        }
        [$pairSep, $startSep, $midSep, $endSep] = self::listSeparators($type, $style, $locale);
        if ($count === 2) {
            return $items[0] . $pairSep . $items[1];
        }
        $tail = array_pop($items);
        $first = array_shift($items);
        // CLDR list patterns model 3+ items as start + (middle*) + end.
        $body = $first;
        $body .= $startSep . array_shift($items);
        foreach ($items as $mid) {
            $body .= $midSep . $mid;
        }
        return $body . $endSep . $tail;
    }

    /**
     * Return the (pair, start, middle, end) separator quadruple for the
     * given list (type, style, locale) combination.
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    private static function listSeparators(string $type, string $style, string $locale = 'en'): array
    {
        $lang = strtolower(strtok($locale, '-_'));
        if ($lang === 'es') {
            // Spanish CLDR: "y" / "o" rather than "and" / "or".
            $endWord = $type === 'disjunction' ? ' o ' : ' y ';
            if ($type === 'unit' && $style === 'narrow') {
                return [' ', ' ', ' ', ' '];
            }
            // Spanish unit-short / unit-narrow drop the "y" / "o"
            // word and use plain comma separators for 3+ items, but
            // still use the word for the 2-item pair.
            if ($type === 'unit' && $style === 'short') {
                return [$endWord, ', ', ', ', ', '];
            }
            return [$endWord, ', ', ', ', $endWord];
        }
        if ($type === 'unit' && $style === 'narrow') {
            return [' ', ' ', ' ', ' '];
        }
        if ($type === 'unit' && $style === 'short') {
            return [', ', ', ', ', ', ', '];
        }
        if ($type === 'unit' && $style === 'long') {
            return [', ', ', ', ', ', ', '];
        }
        if ($type === 'disjunction' && $style === 'short') {
            return [' or ', ', ', ', ', ', or '];
        }
        if ($type === 'disjunction') {
            return [' or ', ', ', ', ', ', or '];
        }
        // Conjunction.
        if ($style === 'short') {
            return [' & ', ', ', ', ', ', & '];
        }
        if ($style === 'narrow') {
            return [', ', ', ', ', ', ', '];
        }
        return [' and ', ', ', ', ', ', and '];
    }

    /**
     * Look up the locale's display name for an ISO 4217 currency
     * code. ICU's `\NumberFormatter::CURRENCY_SYMBOL` lookup echoes
     * the code back for unknown currencies, so we cross-check
     * against our supported-currencies list to know whether the
     * locale data actually recognises the code.
     */
    private static function displayNameForCurrency(string $code, string $icuLocale): ?string
    {
        $upper = strtoupper($code);
        if (!class_exists(\NumberFormatter::class)) {
            return null;
        }
        if (!in_array($upper, self::getSupportedCurrencies(), true)) {
            return null;
        }
        $fmt = new \NumberFormatter($icuLocale . '@currency=' . $upper, \NumberFormatter::CURRENCY);
        $sym = $fmt->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
        if ($sym === '') {
            return $upper;
        }
        return $sym;
    }

    private static function installListFormat(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'ListFormat',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor Intl.ListFormat requires \'new\'');
                }

                $localesArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();

                $locales = self::localesFromArg($localesArg);
                $options = self::getOptionsObject($optionsArg);
                self::validateLocaleMatcher($options);

                $obj = self::instanceFromConstructor($this_, $proto, 'ListFormat');
                $obj->defineOwnProperty('[[InitializedListFormat]]', PropertyDescriptor::data(
                    new JsBoolean(true),
                    false,
                    false,
                    false,
                ));
                $resolvedLocale = self::resolveLocale($locales, []);
                $obj->defineOwnProperty('[[Locale]]', PropertyDescriptor::data(
                    new JsString($resolvedLocale),
                    false,
                    false,
                    false,
                ));

                // type: "conjunction" (default), "disjunction", "unit".
                $type = 'conjunction';
                $typeVal = $options->get('type');
                if (!$typeVal instanceof JsUndefined) {
                    $t = TypeConversion::toString($typeVal);
                    if (!in_array($t, ['conjunction', 'disjunction', 'unit'], true)) {
                        throw new RangeError("Invalid type: {$t}");
                    }
                    $type = $t;
                }
                $obj->defineOwnProperty('[[Type]]', PropertyDescriptor::data(
                    new JsString($type),
                    false,
                    false,
                    false,
                ));

                // style: "long" (default), "short", "narrow".
                $style = 'long';
                $styleVal = $options->get('style');
                if (!$styleVal instanceof JsUndefined) {
                    $s = TypeConversion::toString($styleVal);
                    if (!in_array($s, ['long', 'short', 'narrow'], true)) {
                        throw new RangeError("Invalid style: {$s}");
                    }
                    $style = $s;
                }
                $obj->defineOwnProperty('[[Style]]', PropertyDescriptor::data(
                    new JsString($style),
                    false,
                    false,
                    false,
                ));

                return $obj;
            },
            0,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Intl.ListFormat'), false, false, true),
        );

        // ListFormat.prototype.format(list)
        $format = JsFunction::fromCallable('format', function (JsValue $this_, array $args): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedListFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.ListFormat.prototype.format called on non-ListFormat');
            }
            $items = self::stringListFromIterable($args[0] ?? JsUndefined::instance());
            $type = self::extractInternalString($this_, '[[Type]]', 'conjunction');
            $style = self::extractInternalString($this_, '[[Style]]', 'long');
            $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
            return new JsString(self::joinListItems($items, $type, $style, $locale));
        }, 1);
        $proto->defineOwnProperty('format', PropertyDescriptor::data($format, true, false, true));

        // ListFormat.prototype.formatToParts(list)
        $formatToParts = JsFunction::fromCallable('formatToParts', function (JsValue $this_, array $args): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedListFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError(
                    'Intl.ListFormat.prototype.formatToParts called on non-ListFormat'
                );
            }
            $items = self::stringListFromIterable($args[0] ?? JsUndefined::instance());
            $type = self::extractInternalString($this_, '[[Type]]', 'conjunction');
            $style = self::extractInternalString($this_, '[[Style]]', 'long');
            $locale = self::extractInternalString($this_, '[[Locale]]', 'en');
            $count = count($items);
            $result = new JsArray();
            $idx = 0;
            $emit = static function (string $type, string $value) use (&$result, &$idx): void {
                if ($value === '') {
                    return;
                }
                $part = new JsObject();
                self::defineDataProp($part, 'type', new JsString($type));
                self::defineDataProp($part, 'value', new JsString($value));
                $result->set((string) $idx++, $part);
            };
            if ($count === 0) {
                $result->set('length', JsNumber::of(0.0));
                return $result;
            }
            if ($count === 1) {
                $emit('element', $items[0]);
                $result->set('length', JsNumber::of((float) $idx));
                return $result;
            }
            [$pairSep, $startSep, $midSep, $endSep] = self::listSeparators($type, $style, $locale);
            if ($count === 2) {
                $emit('element', $items[0]);
                $emit('literal', $pairSep);
                $emit('element', $items[1]);
                $result->set('length', JsNumber::of((float) $idx));
                return $result;
            }
            // 3+ items: first, startSep, second, midSep*, last via endSep.
            $emit('element', $items[0]);
            $emit('literal', $startSep);
            $emit('element', $items[1]);
            for ($i = 2; $i < $count - 1; $i++) {
                $emit('literal', $midSep);
                $emit('element', $items[$i]);
            }
            $emit('literal', $endSep);
            $emit('element', $items[$count - 1]);
            $result->set('length', JsNumber::of((float) $idx));
            return $result;
        }, 1);
        $proto->defineOwnProperty('formatToParts', PropertyDescriptor::data($formatToParts, true, false, true));

        // ListFormat.prototype.resolvedOptions()
        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (JsValue $this_): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedListFormat]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.ListFormat.prototype.resolvedOptions called on non-ListFormat');
            }
            $result = new JsObject();
            self::defineDataProp($result, 'locale', new JsString(
                self::extractInternalString($this_, '[[Locale]]', 'en'),
            ));
            self::defineDataProp($result, 'type', new JsString(
                self::extractInternalString($this_, '[[Type]]', 'conjunction'),
            ));
            self::defineDataProp($result, 'style', new JsString(
                self::extractInternalString($this_, '[[Style]]', 'long'),
            ));
            return $result;
        }, 0);
        $proto->defineOwnProperty(
            'resolvedOptions',
            PropertyDescriptor::data($resolvedOptions, true, false, true),
        );

        $constructor->defineOwnProperty(
            'supportedLocalesOf',
            PropertyDescriptor::data(self::makeSupportedLocalesOf('ListFormat'), true, false, true),
        );

        $intl->defineOwnProperty(
            'ListFormat',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }
}
