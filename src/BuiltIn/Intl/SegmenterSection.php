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
 * Intl.Segmenter section. Composed into IntlObject via
 * `use Intl\SegmenterSection;`. `self::` references resolve into
 * the composing class so cross-section helpers continue to work.
 */
trait SegmenterSection
{
    // ---------------------------------------------------------------
    // Intl.Segmenter
    // ---------------------------------------------------------------

    private static function installSegmenter(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'Segmenter',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor Intl.Segmenter requires \'new\'');
                }

                $localesArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();

                $locales = self::localesFromArg($localesArg);
                $options = self::getOptionsObject($optionsArg);
                self::validateLocaleMatcher($options);

                $obj = self::instanceFromConstructor($this_, $proto, 'Segmenter');
                $obj->defineOwnProperty('[[InitializedSegmenter]]', PropertyDescriptor::data(
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

                // granularity: "grapheme" (default), "word", "sentence".
                $granularity = 'grapheme';
                $gVal = $options->get('granularity');
                if (!$gVal instanceof JsUndefined) {
                    $g = TypeConversion::toString($gVal);
                    if (!in_array($g, ['grapheme', 'word', 'sentence'], true)) {
                        throw new RangeError("Invalid granularity: {$g}");
                    }
                    $granularity = $g;
                }
                $obj->defineOwnProperty('[[Granularity]]', PropertyDescriptor::data(
                    new JsString($granularity),
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
            PropertyDescriptor::data(new JsString('Intl.Segmenter'), false, false, true),
        );

        // Segmenter.prototype.segment(string)
        $segment = JsFunction::fromCallable('segment', function (JsValue $this_, array $args): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedSegmenter]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.Segmenter.prototype.segment called on non-Segmenter');
            }
            // Spec: `Let string be ? ToString(string)`. The default-arg
            // ToString happens unconditionally, so a missing argument
            // (or an explicit `undefined`) becomes the literal string
            // "undefined".
            $str = TypeConversion::toString($args[0] ?? JsUndefined::instance());
            $granularity = self::extractInternalString($this_, '[[Granularity]]', 'grapheme');

            // Return a Segments object (iterable).
            $segments = new JsObject();
            // Store the input and granularity for iteration.
            $segments->defineOwnProperty('[[String]]', PropertyDescriptor::data(
                new JsString($str),
                false,
                false,
                false,
            ));
            $segments->defineOwnProperty('[[Granularity]]', PropertyDescriptor::data(
                new JsString($granularity),
                false,
                false,
                false,
            ));

            // containing(index)
            $containing = JsFunction::fromCallable('containing', function (
                JsValue $this2_,
                array $args,
            ) use (
                $str,
                $granularity
): JsValue {
                if (
                    !$this2_ instanceof JsObject
                    || $this2_->get('[[String]]') instanceof JsUndefined
                ) {
                    throw new TypeError(
                        'Intl.Segmenter%segments%.prototype.containing called on incompatible receiver',
                    );
                }
                $rawIndex = TypeConversion::toNumber($args[0] ?? JsUndefined::instance());
                if (is_nan($rawIndex)) {
                    $rawIndex = 0.0;
                }
                // ToInteger BEFORE the range check, per spec: -0.1
                // becomes -0 (== 0), so it's in range.
                $utf16Length = self::utf8ByteToUtf16Index($str, strlen($str));
                if (!is_finite($rawIndex)) {
                    return JsUndefined::instance();
                }
                $index = (int) ($rawIndex >= 0
                    ? floor($rawIndex)
                    : -floor(-$rawIndex));
                if ($index < 0 || $index >= $utf16Length) {
                    return JsUndefined::instance();
                }
                $byteIdx = self::utf16IndexToUtf8Byte($str, $index);
                [$start, $end] = self::segmentBoundsAt($str, $byteIdx, $granularity);
                $segment = substr($str, $start, $end - $start);
                $result = new JsObject();
                $result->set('segment', new JsString($segment));
                $result->set(
                    'index',
                    JsNumber::of((float) self::utf8ByteToUtf16Index($str, $start)),
                );
                $result->set('input', new JsString($str));
                if ($granularity === 'word') {
                    $result->set('isWordLike', new JsBoolean(
                        preg_match('/\p{L}|\p{N}/u', $segment) === 1,
                    ));
                }
                return $result;
            }, 1);
            $segments->defineOwnProperty('containing', PropertyDescriptor::data($containing, true, false, true));

            // [Symbol.iterator]
            $iterFn = JsFunction::fromCallable('[Symbol.iterator]', function (
                JsValue $this2_,
            ) use (
                $str,
                $granularity
): JsValue {
                // Create an iterator that yields segment objects.
                $chars = [];
                if ($granularity === 'grapheme' && extension_loaded('intl')) {
                    $bi = \IntlBreakIterator::createCharacterInstance();
                    $bi->setText($str);
                    $prev = 0;
                    while (($pos = $bi->next()) !== \IntlBreakIterator::DONE) {
                        // IntlBreakIterator returns BYTE offsets when fed
                        // a PHP string (UTF-8). Use substr-by-byte and
                        // convert the byte offset to a UTF-16 code-unit
                        // index for the JS-facing `index` property.
                        $chars[] = [
                            'segment' => substr($str, $prev, $pos - $prev),
                            'index' => self::utf8ByteToUtf16Index($str, $prev),
                        ];
                        $prev = $pos;
                    }
                } elseif ($granularity === 'word') {
                    if (extension_loaded('intl')) {
                        $bi = \IntlBreakIterator::createWordInstance();
                        $bi->setText($str);
                        $prev = 0;
                        while (($pos = $bi->next()) !== \IntlBreakIterator::DONE) {
                            $chars[] = [
                                'segment' => substr($str, $prev, $pos - $prev),
                                'index' => self::utf8ByteToUtf16Index($str, $prev),
                            ];
                            $prev = $pos;
                        }
                    } else {
                        // Fallback: split on word boundaries.
                        preg_match_all('/\S+|\s+/u', $str, $matches, PREG_OFFSET_CAPTURE);
                        foreach ($matches[0] as $m) {
                            $chars[] = [
                                'segment' => $m[0],
                                'index' => mb_strlen(substr($str, 0, $m[1]), 'UTF-8'),
                            ];
                        }
                    }
                } elseif ($granularity === 'sentence') {
                    if (extension_loaded('intl')) {
                        $bi = \IntlBreakIterator::createSentenceInstance();
                        $bi->setText($str);
                        $prev = 0;
                        while (($pos = $bi->next()) !== \IntlBreakIterator::DONE) {
                            $chars[] = [
                                'segment' => substr($str, $prev, $pos - $prev),
                                'index' => self::utf8ByteToUtf16Index($str, $prev),
                            ];
                            $prev = $pos;
                        }
                    } else {
                        $chars[] = ['segment' => $str, 'index' => 0];
                    }
                } else {
                    // Grapheme fallback without intl.
                    $len = mb_strlen($str, 'UTF-8');
                    for ($i = 0; $i < $len; $i++) {
                        $chars[] = ['segment' => mb_substr($str, $i, 1, 'UTF-8'), 'index' => $i];
                    }
                }

                $idx = 0;
                $total = count($chars);
                $iter = new JsObject();
                $nextCb = function () use (
                    &$idx,
                    $total,
                    &$chars,
                    $str,
                    $granularity,
                ): JsValue {
                    if ($idx >= $total) {
                        $result = new JsObject();
                        $result->set('done', new JsBoolean(true));
                        $result->set('value', JsUndefined::instance());
                        return $result;
                    }
                    $entry = $chars[$idx];
                    $idx++;
                    $segObj = new JsObject();
                    $segObj->set('segment', new JsString($entry['segment']));
                    $segObj->set('index', JsNumber::of((float) $entry['index']));
                    $segObj->set('input', new JsString($str));
                    if ($granularity === 'word') {
                        $segObj->set('isWordLike', new JsBoolean(
                            preg_match('/\w/u', $entry['segment']) === 1,
                        ));
                    }
                    $result = new JsObject();
                    $result->set('done', new JsBoolean(false));
                    $result->set('value', $segObj);
                    return $result;
                };
                $nextFn = JsFunction::fromCallable('next', $nextCb, 0);
                $iter->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));

                // The iterator itself is iterable.
                $selfIter = JsFunction::fromCallable('[Symbol.iterator]', function (JsValue $this3_): JsValue {
                    return $this3_;
                }, 0);
                $iter->definePropertyBySymbol(
                    SymbolConstructor::iterator(),
                    PropertyDescriptor::data($selfIter, true, false, true),
                );
                return $iter;
            }, 0);
            $segments->definePropertyBySymbol(
                SymbolConstructor::iterator(),
                PropertyDescriptor::data($iterFn, true, false, true),
            );

            return $segments;
        }, 1);
        $proto->defineOwnProperty('segment', PropertyDescriptor::data($segment, true, false, true));

        // Segmenter.prototype.resolvedOptions()
        $resolvedOptions = JsFunction::fromCallable('resolvedOptions', function (JsValue $this_): JsValue {
            if (
                !$this_ instanceof JsObject
                || $this_->get('[[InitializedSegmenter]]') instanceof JsUndefined
            ) {
                throw new TypeError('Intl.Segmenter.prototype.resolvedOptions called on non-Segmenter');
            }
            $result = new JsObject();
            self::defineDataProp($result, 'locale', new JsString(
                self::extractInternalString($this_, '[[Locale]]', 'en'),
            ));
            self::defineDataProp($result, 'granularity', new JsString(
                self::extractInternalString($this_, '[[Granularity]]', 'grapheme'),
            ));
            return $result;
        }, 0);
        $proto->defineOwnProperty(
            'resolvedOptions',
            PropertyDescriptor::data($resolvedOptions, true, false, true),
        );

        $constructor->defineOwnProperty(
            'supportedLocalesOf',
            PropertyDescriptor::data(self::makeSupportedLocalesOf('Segmenter'), true, false, true),
        );

        $intl->defineOwnProperty(
            'Segmenter',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }
}
