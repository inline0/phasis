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
 * Intl.Locale section. Composed into IntlObject via
 * `use Intl\LocaleSection;`. `self::` references resolve into
 * the composing class so cross-section helpers continue to work.
 */
trait LocaleSection
{
    // ---------------------------------------------------------------
    // Intl.Locale
    // ---------------------------------------------------------------

    private static function installLocale(JsObject $intl): void
    {
        $proto = new JsObject();

        $constructor = JsFunction::fromCallable(
            'Locale',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor Intl.Locale requires \'new\'');
                }

                $tagArg = $args[0] ?? JsUndefined::instance();
                $optionsArg = $args[1] ?? JsUndefined::instance();

                if ($tagArg instanceof JsUndefined) {
                    throw new TypeError('First argument to Intl.Locale must be a string or Locale object');
                }
                // Per spec step 7: only String or Object are accepted;
                // null / undefined / number / boolean / symbol throw TypeError.
                if (
                    !$tagArg instanceof JsString
                    && !$tagArg instanceof JsObject
                ) {
                    throw new TypeError('First argument to Intl.Locale must be a string or Locale object');
                }
                // Per spec step 11.a, ToObject(null) throws TypeError when an
                // explicit null options argument is supplied.
                if ($optionsArg instanceof JsNull) {
                    throw new TypeError('Cannot convert null to Locale options object');
                }

                // If tagArg is already a Locale-like object, extract its string.
                $tag = TypeConversion::toString($tagArg);
                if ($tag === '') {
                    throw new RangeError('Invalid language tag: ');
                }

                $options = self::getOptionsObject($optionsArg);

                // Apply options overrides. Each subtag must match its
                // BCP47 production exactly; ICU's parser is permissive
                // so we validate up-front.
                $language = null;
                $langVal = $options->get('language');
                if (!$langVal instanceof JsUndefined) {
                    $language = TypeConversion::toString($langVal);
                    $langLen = strlen($language);
                    if (
                        !ctype_alpha($language)
                        || !($langLen === 2 || $langLen === 3 || ($langLen >= 5 && $langLen <= 8))
                    ) {
                        throw new RangeError("Invalid language: {$language}");
                    }
                }
                $script = null;
                $scriptVal = $options->get('script');
                if (!$scriptVal instanceof JsUndefined) {
                    $script = TypeConversion::toString($scriptVal);
                    if (strlen($script) !== 4 || !ctype_alpha($script)) {
                        throw new RangeError("Invalid script: {$script}");
                    }
                }
                $region = null;
                $regionVal = $options->get('region');
                if (!$regionVal instanceof JsUndefined) {
                    $region = TypeConversion::toString($regionVal);
                    $regionLen = strlen($region);
                    $isAlpha2 = ($regionLen === 2 && ctype_alpha($region));
                    $isDigit3 = ($regionLen === 3 && ctype_digit($region));
                    if (!$isAlpha2 && !$isDigit3) {
                        throw new RangeError("Invalid region: {$region}");
                    }
                }

                // Parse the tag.
                $parsed = self::parseLocaleTag($tag);
                if ($parsed === null) {
                    throw new RangeError("Invalid language tag: {$tag}");
                }

                // Override with options.
                if ($language !== null) {
                    $parsed['language'] = strtolower($language);
                }
                if ($script !== null) {
                    $parsed['script'] = ucfirst(strtolower($script));
                }
                if ($region !== null) {
                    $parsed['region'] = strtoupper($region);
                    // Re-apply CLDR region canonicalisation: M.49
                    // numeric codes such as "554" canonicalise to
                    // their alpha-2 equivalent (NZ).
                    $parsed = self::applyRegionAlias($parsed);
                }

                // Unicode extension keywords from options. Each value
                // must satisfy the BCP47 type production:
                // alphanum{3,8}(-alphanum{3,8})*  for calendar /
                // collation / numberingSystem; the others are fixed
                // enumerations.
                $isValidUnicodeType = static function (string $value): bool {
                    if ($value === '') {
                        return false;
                    }
                    foreach (explode('-', $value) as $part) {
                        $partLen = strlen($part);
                        if ($partLen < 3 || $partLen > 8 || !ctype_alnum($part)) {
                            return false;
                        }
                    }
                    return true;
                };
                $calendar = null;
                $calVal = $options->get('calendar');
                if (!$calVal instanceof JsUndefined) {
                    $calendar = TypeConversion::toString($calVal);
                    if (!$isValidUnicodeType($calendar)) {
                        throw new RangeError("Invalid calendar: {$calendar}");
                    }
                    // UTS35 canonical form for a small set of CLDR-aliased
                    // calendar identifiers.
                    static $calendarAliases = [
                        'islamicc' => 'islamic-civil',
                        'ethiopic-amete-alem' => 'ethioaa',
                        'gregorian' => 'gregory',
                    ];
                    $calLower = strtolower($calendar);
                    $calendar = $calendarAliases[$calLower] ?? $calLower;
                    $parsed['calendar'] = $calendar;
                }
                $collation = null;
                $collVal = $options->get('collation');
                if (!$collVal instanceof JsUndefined) {
                    $collation = TypeConversion::toString($collVal);
                    if (!$isValidUnicodeType($collation)) {
                        throw new RangeError("Invalid collation: {$collation}");
                    }
                    $parsed['collation'] = $collation;
                }
                $hourCycle = null;
                $hcVal = $options->get('hourCycle');
                if (!$hcVal instanceof JsUndefined) {
                    $hourCycle = TypeConversion::toString($hcVal);
                    if (!in_array($hourCycle, ['h11', 'h12', 'h23', 'h24'], true)) {
                        throw new RangeError("Invalid hourCycle: {$hourCycle}");
                    }
                    $parsed['hourCycle'] = $hourCycle;
                }
                $caseFirst = null;
                $cfVal = $options->get('caseFirst');
                if (!$cfVal instanceof JsUndefined) {
                    $caseFirst = TypeConversion::toString($cfVal);
                    if (!in_array($caseFirst, ['upper', 'lower', 'false'], true)) {
                        throw new RangeError("Invalid caseFirst: {$caseFirst}");
                    }
                    $parsed['caseFirst'] = $caseFirst;
                }
                $numeric = null;
                $numVal = $options->get('numeric');
                if (!$numVal instanceof JsUndefined) {
                    $numeric = TypeConversion::toBoolean($numVal);
                    $parsed['numeric'] = $numeric;
                }
                $numberingSystem = null;
                $nsVal = $options->get('numberingSystem');
                if (!$nsVal instanceof JsUndefined) {
                    $numberingSystem = TypeConversion::toString($nsVal);
                    if (!$isValidUnicodeType($numberingSystem)) {
                        throw new RangeError("Invalid numberingSystem: {$numberingSystem}");
                    }
                    $parsed['numberingSystem'] = $numberingSystem;
                }
                // firstDayOfWeek: numeric forms 0-7 are mapped to the
                // canonical short weekday names (0 and 7 both alias to
                // sun per UTS35); other strings just need to satisfy
                // the BCP47 "type" production
                // (alphanum{3,8}(-alphanum{3,8})*).
                $firstDayOfWeek = null;
                $fwVal = $options->get('firstDayOfWeek');
                if (!$fwVal instanceof JsUndefined) {
                    $fw = TypeConversion::toString($fwVal);
                    static $weekdayMap = [
                        '0' => 'sun', '1' => 'mon', '2' => 'tue', '3' => 'wed',
                        '4' => 'thu', '5' => 'fri', '6' => 'sat', '7' => 'sun',
                    ];
                    if (isset($weekdayMap[$fw])) {
                        $fw = $weekdayMap[$fw];
                    }
                    $fwLower = strtolower($fw);
                    // The boolean primitive `true` canonicalises to the bare
                    // key with no value subtag.
                    if ($fwLower === 'true') {
                        $firstDayOfWeek = '';
                    } else {
                        if (!$isValidUnicodeType($fwLower)) {
                            throw new RangeError("Invalid firstDayOfWeek: {$fw}");
                        }
                        $firstDayOfWeek = $fwLower;
                    }
                    $parsed['firstDayOfWeek'] = $firstDayOfWeek;
                }

                $obj = self::instanceFromConstructor($this_, $proto, 'Locale');

                // Store parsed components as internal slots.
                foreach ($parsed as $key => $val) {
                    if ($val === null) {
                        continue;
                    }
                    if (is_bool($val)) {
                        $jsVal = new JsBoolean($val);
                    } elseif (is_array($val)) {
                        // Skip extension bookkeeping (unicodeAttributes /
                        // unicodeKeywords) — they're plain PHP storage and
                        // don't need to be exposed as JS internal slots.
                        if ($key === 'unicodeAttributes' || $key === 'unicodeKeywords') {
                            continue;
                        }
                        $arr = new JsArray();
                        $idx = 0;
                        foreach (array_values($val) as $item) {
                            $arr->set((string) $idx++, new JsString((string) $item));
                        }
                        $arr->set('length', JsNumber::of((float) $idx));
                        $jsVal = $arr;
                    } else {
                        $jsVal = new JsString((string) $val);
                    }
                    $obj->defineOwnProperty("[[{$key}]]", PropertyDescriptor::data(
                        $jsVal,
                        false,
                        false,
                        false,
                    ));
                }

                // Store the full canonical tag.
                $canonTag = self::reconstructLocaleTag($parsed);
                $obj->defineOwnProperty('[[LocaleTag]]', PropertyDescriptor::data(
                    new JsString($canonTag),
                    false,
                    false,
                    false,
                ));

                return $obj;
            },
            1,
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Intl.Locale'), false, false, true),
        );

        // Locale.prototype.toString()
        $toString = JsFunction::fromCallable('toString', function (JsValue $this_): JsValue {
            if ($this_ instanceof JsObject && self::isInitializedLocale($this_)) {
                $tag = $this_->get('[[LocaleTag]]');
                if (!$tag instanceof JsUndefined) {
                    return $tag;
                }
            }
            throw new TypeError('Intl.Locale.prototype.toString called on non-Locale');
        }, 0);
        $proto->defineOwnProperty('toString', PropertyDescriptor::data($toString, true, false, true));

        // Accessor properties: language, script, region, baseName, calendar, etc.
        $accessors = [
            'language' => 'language',
            'script' => 'script',
            'region' => 'region',
            'calendar' => 'calendar',
            'caseFirst' => 'caseFirst',
            'collation' => 'collation',
            'hourCycle' => 'hourCycle',
            'numberingSystem' => 'numberingSystem',
            'firstDayOfWeek' => 'firstDayOfWeek',
        ];
        foreach ($accessors as $prop => $internalKey) {
            $getter = JsFunction::fromCallable("get {$prop}", function (JsValue $this_) use ($internalKey): JsValue {
                if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                    throw new TypeError("Intl.Locale.prototype.{$internalKey} called on non-Locale");
                }
                $val = $this_->get("[[{$internalKey}]]");
                return $val instanceof JsUndefined ? JsUndefined::instance() : $val;
            }, 0);
            $proto->defineOwnProperty($prop, PropertyDescriptor::accessor(
                get: $getter,
                set: null,
                enumerable: false,
                configurable: true,
            ));
        }

        // numeric: boolean accessor
        $numericGetter = JsFunction::fromCallable('get numeric', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                throw new TypeError('Intl.Locale.prototype.numeric called on non-Locale');
            }
            $val = $this_->get('[[numeric]]');
            if ($val instanceof JsBoolean) {
                return $val;
            }
            return new JsBoolean(false);
        }, 0);
        $proto->defineOwnProperty('numeric', PropertyDescriptor::accessor(
            get: $numericGetter,
            set: null,
            enumerable: false,
            configurable: true,
        ));

        // baseName: accessor
        $baseNameGetter = JsFunction::fromCallable('get baseName', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                throw new TypeError('Intl.Locale.prototype.baseName called on non-Locale');
            }
            $lang = self::extractInternalString($this_, '[[language]]', '');
            $script = self::extractInternalStringOrNull($this_, '[[script]]');
            $region = self::extractInternalStringOrNull($this_, '[[region]]');
            $variantsVal = $this_->get('[[variants]]');

            $parts = [$lang];
            if ($script !== null) {
                $parts[] = $script;
            }
            if ($region !== null) {
                $parts[] = $region;
            }
            if ($variantsVal instanceof JsArray) {
                $variantLen = (int) \Phasis\Spec\TypeConversion::toNumber($variantsVal->get('length'));
                for ($vi = 0; $vi < $variantLen; $vi++) {
                    $vs = $variantsVal->get((string) $vi);
                    if ($vs instanceof JsString) {
                        $parts[] = $vs->value;
                    }
                }
            }
            return new JsString(implode('-', $parts));
        }, 0);
        $proto->defineOwnProperty('baseName', PropertyDescriptor::accessor(
            get: $baseNameGetter,
            set: null,
            enumerable: false,
            configurable: true,
        ));

        // maximize() and minimize()
        $maximize = JsFunction::fromCallable('maximize', function (JsValue $this_) use ($constructor): JsValue {
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                throw new TypeError('Intl.Locale.prototype.maximize called on non-Locale');
            }
            $tag = self::extractInternalString($this_, '[[LocaleTag]]', 'en');
            $maximized = self::addLikelySubtags($tag);
            // Construct a new Locale object by calling the constructor directly.
            $newObj = new JsObject($this_->getPrototype());
            $newObj->set('[[NewTarget]]', $constructor);
            $result = ($constructor->getNativeCallable())($newObj, [new JsString($maximized)]);
            return $result;
        }, 0);
        $proto->defineOwnProperty('maximize', PropertyDescriptor::data($maximize, true, false, true));

        $minimize = JsFunction::fromCallable('minimize', function (JsValue $this_) use ($constructor): JsValue {
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                throw new TypeError('Intl.Locale.prototype.minimize called on non-Locale');
            }
            $tag = self::extractInternalString($this_, '[[LocaleTag]]', 'en');
            if (extension_loaded('intl')) {
                $minimized = self::removeLikelySubtags($tag);
            } else {
                $minimized = $tag;
            }
            // Construct a new Locale object by calling the constructor directly.
            $newObj = new JsObject($this_->getPrototype());
            $newObj->set('[[NewTarget]]', $constructor);
            $result = ($constructor->getNativeCallable())($newObj, [new JsString($minimized)]);
            return $result;
        }, 0);
        $proto->defineOwnProperty('minimize', PropertyDescriptor::data($minimize, true, false, true));

        // getCalendars(), getCollations(), getHourCycles(), getNumberingSystems(), getTimeZones()
        $infoMethods = [
            'getCalendars' => function () {
                return self::getSupportedCalendars();
            },
            'getCollations' => function () {
                return self::getSupportedCollations();
            },
            'getHourCycles' => function () {
                return ['h11', 'h12', 'h23', 'h24'];
            },
            'getNumberingSystems' => function () {
                return ['latn'];
            },
        ];
        foreach ($infoMethods as $name => $getter) {
            $fn = JsFunction::fromCallable($name, function (JsValue $this_) use ($getter, $name): JsValue {
                if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                    throw new TypeError("Intl.Locale.prototype.{$name} called on non-Locale");
                }
                $values = $getter();
                $result = new JsArray();
                foreach ($values as $i => $v) {
                    $result->set((string) $i, new JsString($v));
                }
                $result->set('length', JsNumber::of((float) count($values)));
                return $result;
            }, 0);
            $proto->defineOwnProperty($name, PropertyDescriptor::data($fn, true, false, true));
        }

        // getTextInfo()
        $getTextInfo = JsFunction::fromCallable('getTextInfo', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                throw new TypeError('Intl.Locale.prototype.getTextInfo called on non-Locale');
            }
            $result = new JsObject();
            $result->set('direction', new JsString('ltr'));
            return $result;
        }, 0);
        $proto->defineOwnProperty('getTextInfo', PropertyDescriptor::data($getTextInfo, true, false, true));

        // getWeekInfo()
        $getWeekInfo = JsFunction::fromCallable('getWeekInfo', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                throw new TypeError('Intl.Locale.prototype.getWeekInfo called on non-Locale');
            }
            // The `fw` extension overrides the locale-derived first day.
            // Map the canonical short weekday name to its ISO 8601 index
            // (mon=1 .. sun=7).
            $firstDay = 1;
            $fwSlot = $this_->get('[[firstDayOfWeek]]');
            if ($fwSlot instanceof JsString) {
                static $weekdayIndex = [
                    'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4,
                    'fri' => 5, 'sat' => 6, 'sun' => 7,
                ];
                $name = strtolower($fwSlot->value);
                if (isset($weekdayIndex[$name])) {
                    $firstDay = $weekdayIndex[$name];
                }
            }
            $result = new JsObject();
            $result->set('firstDay', JsNumber::of((float) $firstDay));
            $weekend = new JsArray();
            $weekend->set('0', JsNumber::of(6.0));
            $weekend->set('1', JsNumber::of(7.0));
            $weekend->set('length', JsNumber::of(2.0));
            $result->set('weekend', $weekend);
            $result->set('minimalDays', JsNumber::of(1.0));
            return $result;
        }, 0);
        $proto->defineOwnProperty('getWeekInfo', PropertyDescriptor::data($getWeekInfo, true, false, true));

        // getTimeZones()
        $getTimeZones = JsFunction::fromCallable('getTimeZones', function (JsValue $this_): JsValue {
            if (!$this_ instanceof JsObject || !self::isInitializedLocale($this_)) {
                throw new TypeError('Intl.Locale.prototype.getTimeZones called on non-Locale');
            }
            // Spec: when the locale has no region subtag, return undefined.
            if ($this_->get('[[region]]') instanceof JsUndefined) {
                return JsUndefined::instance();
            }
            $result = new JsArray();
            $tzs = self::getSupportedTimeZones();
            $limited = array_slice($tzs, 0, 50);
            foreach ($limited as $i => $tz) {
                $result->set((string) $i, new JsString($tz));
            }
            $result->set('length', JsNumber::of((float) count($limited)));
            return $result;
        }, 0);
        $proto->defineOwnProperty('getTimeZones', PropertyDescriptor::data($getTimeZones, true, false, true));

        $intl->defineOwnProperty(
            'Locale',
            PropertyDescriptor::data($constructor, true, false, true),
        );
    }

    /**
     * Parse a BCP 47 language tag into components.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Quick structural rejection of obviously-invalid BCP47 tags before
     * delegating to ICU. Catches the common cases test262 exercises:
     * non-ASCII text, leading singletons, wildcards, duplicate scripts /
     * regions / variants, and duplicate `-u-` / `-x-` extension singletons.
     */
    /**
     * Inverse of utf8ByteToUtf16Index: walk the UTF-8 string and return
     * the byte offset corresponding to a UTF-16 code-unit index.
     * Out-of-range indices clamp to the string length.
     */
    private static function utf16IndexToUtf8Byte(string $str, int $codeUnitIdx): int
    {
        if ($codeUnitIdx <= 0) {
            return 0;
        }
        $i = 0;
        $codeUnits = 0;
        $len = strlen($str);
        while ($i < $len && $codeUnits < $codeUnitIdx) {
            $byte = ord($str[$i]);
            if ($byte < 0x80) {
                $inc = 1;
                $width = 1;
            } elseif ($byte < 0xC0) {
                // Stray continuation byte — count as one without
                // contributing to UTF-16 code units.
                $inc = 0;
                $width = 1;
            } elseif ($byte < 0xE0) {
                $inc = 1;
                $width = 2;
            } elseif ($byte < 0xF0) {
                $inc = 1;
                $width = 3;
            } else {
                // Supplementary plane: 4 bytes UTF-8, 2 UTF-16 code units.
                $inc = 2;
                $width = 4;
            }
            if ($codeUnits + $inc > $codeUnitIdx) {
                // The requested code-unit lands inside a surrogate
                // pair: anchor at the start of the encompassing char.
                return $i;
            }
            $codeUnits += $inc;
            $i += $width;
        }
        return $i;
    }

    /**
     * Compute the [start, end] byte-offset bounds of the segment
     * containing `$byteIdx` for the given granularity. Used by
     * Segmenter.prototype.segment(...).containing(index).
     *
     * @return array{0:int,1:int}
     */
    private static function segmentBoundsAt(string $str, int $byteIdx, string $granularity): array
    {
        if (!extension_loaded('intl')) {
            return [0, strlen($str)];
        }
        // Lone surrogates (CESU-8 sequences for 0xD800-0xDFFF) trip
        // IntlBreakIterator into replacing them with U+FFFD, which
        // would change the segment value. When the ENTIRE input is a
        // single CESU-8 surrogate sequence, treat it as one segment;
        // otherwise fall through to normal segmentation (the test262
        // breakable-input fixture wants surrogate + space to split
        // into two segments).
        if (strlen($str) === 3 && ord($str[0]) === 0xED && (ord($str[1]) & 0xE0) === 0xA0) {
            return [0, 3];
        }
        $bi = match ($granularity) {
            'word' => \IntlBreakIterator::createWordInstance(),
            'sentence' => \IntlBreakIterator::createSentenceInstance(),
            default => \IntlBreakIterator::createCharacterInstance(),
        };
        $bi->setText($str);
        // ICU's preceding() treats positions inside multi-byte chars
        // oddly (it skips the enclosing break). Walk the breaks
        // forward so the bounds stay correct for supplementary-plane
        // characters and surrogate pairs.
        $start = 0;
        $end = strlen($str);
        $prev = 0;
        while (($pos = $bi->next()) !== \IntlBreakIterator::DONE) {
            if ($pos > $byteIdx) {
                $start = $prev;
                $end = $pos;
                break;
            }
            $prev = $pos;
        }
        if ($pos === \IntlBreakIterator::DONE) {
            $start = $prev;
            $end = strlen($str);
        }
        return [$start, $end];
    }

    /**
     * Convert a UTF-8 byte offset into the equivalent UTF-16 code-unit
     * index. JS strings expose UTF-16 indices, so segment results need
     * the byte offset returned by IntlBreakIterator translated before
     * being handed back to userland.
     */
    private static function utf8ByteToUtf16Index(string $str, int $byteOffset): int
    {
        if ($byteOffset <= 0) {
            return 0;
        }
        $byteOffset = min($byteOffset, strlen($str));
        $sub = substr($str, 0, $byteOffset);
        $codeUnits = 0;
        $i = 0;
        $len = strlen($sub);
        while ($i < $len) {
            $byte = ord($sub[$i]);
            if ($byte < 0x80) {
                $codeUnits++;
                $i++;
            } elseif ($byte < 0xC0) {
                // Continuation byte without lead — count as one to avoid
                // an infinite loop on truncated input.
                $i++;
            } elseif ($byte < 0xE0) {
                $codeUnits++;
                $i += 2;
            } elseif ($byte < 0xF0) {
                $codeUnits++;
                $i += 3;
            } else {
                // Supplementary plane decomposes into a UTF-16 surrogate
                // pair (two code units).
                $codeUnits += 2;
                $i += 4;
            }
        }
        return $codeUnits;
    }

    private static function isStructurallyInvalidLanguageTag(string $tag): bool
    {
        if ($tag === '') {
            return true;
        }
        // ASCII-only letters, digits, hyphens.
        if (preg_match('/[^A-Za-z0-9\-]/', $tag)) {
            return true;
        }
        // No leading or trailing hyphens, no consecutive hyphens, no empty
        // subtags.
        if ($tag[0] === '-' || $tag[strlen($tag) - 1] === '-' || str_contains($tag, '--')) {
            return true;
        }
        $parts = explode('-', $tag);
        $first = $parts[0];
        // First subtag must be unicode_language_subtag = alpha{2,3} | alpha{5,8}.
        $firstLen = strlen($first);
        if (
            !ctype_alpha($first)
            || !($firstLen === 2 || $firstLen === 3 || ($firstLen >= 5 && $firstLen <= 8))
        ) {
            return true;
        }
        $sawScript = false;
        $sawRegion = false;
        $variants = [];
        $extensionsSeen = [];
        $i = 1;
        $count = count($parts);
        while ($i < $count) {
            $p = $parts[$i];
            $len = strlen($p);
            if ($len === 0) {
                return true;
            }
            if ($len === 1) {
                // Singleton extension introducer. Must be unique and
                // followed by at least one extension subtag of length
                // 2-8. Singleton 'x' switches to private use; everything
                // after `x-` is alphanum{1,8} until the end of the tag.
                $key = strtolower($p);
                if (isset($extensionsSeen[$key])) {
                    return true;
                }
                $extensionsSeen[$key] = true;
                $i++;
                $isPrivate = $key === 'x';
                $isUnicode = $key === 'u';
                $isTransform = $key === 't';
                $minSubLen = $isPrivate ? 1 : 2;
                $maxSubLen = 8;
                $sawAny = false;
                // Track tlang state for the transformed extension.
                // The first run is the tlang (language, optional
                // script/region/variants); subsequent runs are tfields
                // (tkey tvalue+). A length-2 subtag whose 2nd char is
                // a digit signals the transition from tlang to tfields.
                $tlangSeen = false;
                $inTlang = false;
                $tlangSawScript = false;
                $tlangSawRegion = false;
                $tlangVariants = [];
                // tfield tracking: tkey requires at least one
                // tvalue (alphanum{3,8}) following it.
                $awaitingTvalue = false;
                $sawTvalueForCurrentTkey = false;
                while ($i < $count) {
                    $sub = $parts[$i];
                    $subLen = strlen($sub);
                    // Inside non-private extensions a length-1 subtag
                    // starts a new singleton; private use consumes
                    // length-1 subtags as part of its body.
                    if (!$isPrivate && $subLen === 1) {
                        break;
                    }
                    if ($subLen < $minSubLen || $subLen > $maxSubLen) {
                        return true;
                    }
                    if (!ctype_alnum($sub)) {
                        return true;
                    }
                    // UTS35 ukey = alphanum alpha. A length-2 subtag in
                    // the -u- extension must end with a letter, so e.g.
                    // `en-u-c0` and `en-u-00` are invalid (the second
                    // character is a digit).
                    if ($isUnicode && $subLen === 2 && !ctype_alpha($sub[1])) {
                        return true;
                    }
                    if ($isTransform) {
                        // Detect a tkey: alpha digit (length 2). If we
                        // see one, we're past the tlang.
                        $isTkey = $subLen === 2
                            && ctype_alpha($sub[0])
                            && ctype_digit($sub[1]);
                        if (!$tlangSeen && !$isTkey && !$awaitingTvalue) {
                            // First non-tkey subtag starts the tlang.
                            // Must be a valid language subtag (2-3 or
                            // 5-8 alpha; "root" / 4-letter alpha is
                            // not a valid language).
                            if (
                                !ctype_alpha($sub)
                                || !(
                                    $subLen === 2
                                    || $subLen === 3
                                    || $subLen >= 5
                                )
                            ) {
                                return true;
                            }
                            $tlangSeen = true;
                            $inTlang = true;
                            $sawAny = true;
                            $i++;
                            continue;
                        }
                        if ($inTlang && !$isTkey) {
                            // Within tlang: script, region, or variant.
                            if (
                                $subLen === 4
                                && ctype_alpha($sub)
                                && !$tlangSawScript
                                && !$tlangSawRegion
                                && empty($tlangVariants)
                            ) {
                                $tlangSawScript = true;
                                $sawAny = true;
                                $i++;
                                continue;
                            }
                            if (
                                ((($subLen === 2 && ctype_alpha($sub))
                                    || ($subLen === 3 && ctype_digit($sub))))
                                && !$tlangSawRegion
                                && empty($tlangVariants)
                            ) {
                                $tlangSawRegion = true;
                                $sawAny = true;
                                $i++;
                                continue;
                            }
                            $isLongVar = $subLen >= 5 && ctype_alnum($sub);
                            $isShortNumVar = $subLen === 4
                                && ctype_digit($sub[0])
                                && ctype_alnum($sub);
                            if ($isLongVar || $isShortNumVar) {
                                $vKey = strtolower($sub);
                                if (isset($tlangVariants[$vKey])) {
                                    return true;
                                }
                                $tlangVariants[$vKey] = true;
                                $sawAny = true;
                                $i++;
                                continue;
                            }
                            // Unknown subtag inside tlang — invalid.
                            return true;
                        }
                        if ($isTkey) {
                            // Switching from tlang to tfields. The
                            // previous tkey (if any) must have had at
                            // least one tvalue.
                            if ($awaitingTvalue && !$sawTvalueForCurrentTkey) {
                                return true;
                            }
                            $inTlang = false;
                            $awaitingTvalue = true;
                            $sawTvalueForCurrentTkey = false;
                            $sawAny = true;
                            $i++;
                            continue;
                        }
                        // Inside tfields, a non-tkey subtag is a
                        // tvalue. tvalue = alphanum{3,8}.
                        if ($awaitingTvalue) {
                            if ($subLen < 3 || !ctype_alnum($sub)) {
                                return true;
                            }
                            $sawTvalueForCurrentTkey = true;
                            $sawAny = true;
                            $i++;
                            continue;
                        }
                        // Reached an unexpected token in -t-.
                        return true;
                    }
                    $sawAny = true;
                    $i++;
                }
                if ($isTransform) {
                    // A tkey at the end without a tvalue is invalid.
                    if ($awaitingTvalue && !$sawTvalueForCurrentTkey) {
                        return true;
                    }
                }
                if (!$sawAny) {
                    return true;
                }
                continue;
            }
            // Script: alpha{4} (only one allowed, and only before region/variant).
            if ($len === 4 && ctype_alpha($p) && !$sawScript && !$sawRegion && empty($variants)) {
                $sawScript = true;
                $i++;
                continue;
            }
            // Region: alpha{2} | digit{3}, only one, only before variants.
            if (
                ((($len === 2 && ctype_alpha($p)) || ($len === 3 && ctype_digit($p))))
                && !$sawRegion && empty($variants)
            ) {
                $sawRegion = true;
                $i++;
                continue;
            }
            // Variant: alphanum{5,8} or digit followed by alphanum{3}.
            $isLongVariant = ($len >= 5 && $len <= 8 && ctype_alnum($p));
            $isShortNumericVariant = ($len === 4 && ctype_digit($p[0]) && ctype_alnum($p));
            if ($isLongVariant || $isShortNumericVariant) {
                $vKey = strtolower($p);
                if (isset($variants[$vKey])) {
                    return true;
                }
                $variants[$vKey] = true;
                $i++;
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseLocaleTag(string $tag): ?array
    {
        if (self::isStructurallyInvalidLanguageTag($tag)) {
            return null;
        }
        // CLDR `<languageAlias>` for regular grandfathered tags that map
        // to a single replacement. Apply before structural parsing so the
        // entire tag substitutes wholesale.
        static $grandfathered = [
            'art-lojban' => 'jbo',
            'cel-gaulish' => 'xtg',
            'zh-guoyu' => 'zh',
            'zh-hakka' => 'hak',
            'zh-xiang' => 'hsn',
            'no-bok' => 'nb',
            'no-nyn' => 'nn',
            'zh-min-nan' => 'nan',
            'zh-min' => 'nan-x-zh-min',
        ];
        $lcTag = strtolower($tag);
        if (isset($grandfathered[$lcTag])) {
            $tag = $grandfathered[$lcTag];
        }
        if (extension_loaded('intl')) {
            $icuTag = str_replace('-', '_', $tag);
            $parsed = \Locale::parseLocale($icuTag);
            if ($parsed === null || empty($parsed)) {
                // Try to at least extract the language.
                if (preg_match('/^([a-zA-Z]{2,8})/', $tag, $m)) {
                    $parsed = ['language' => strtolower($m[1])];
                } else {
                    return null;
                }
            }

            $result = ['language' => strtolower($parsed['language'] ?? '')];
            // ICU drops `und` instead of treating it as the explicit
            // undetermined code; keep it so `new Intl.Locale("und")`
            // round-trips to "und".
            if ($result['language'] === '' && preg_match('/^([a-zA-Z]{2,8})/', $tag, $m)) {
                $result['language'] = strtolower($m[1]);
            }
            if (isset($parsed['script']) && $parsed['script'] !== '') {
                $result['script'] = ucfirst(strtolower($parsed['script']));
            }
            if (isset($parsed['region']) && $parsed['region'] !== '') {
                $result['region'] = strtoupper($parsed['region']);
            }
            // Apply CLDR language alias replacement so deprecated
            // subtags (cmn, ji, in, aam, ...) collapse to their preferred
            // form. Single-tag replacements are applied unconditionally;
            // multi-tag replacements (sh -> sr-Latn, cnr -> sr-ME) only
            // contribute the script or region if the source tag did not
            // already specify one. Region-conditioned replacements
            // (sgn-XX → ...) drop the region.
            static $languageAliasCanonical = [
                'aam' => 'aas', 'aar' => 'aa', 'aue' => 'ktz', 'arb' => 'ar',
                'ayr' => 'ay', 'ayx' => 'nun', 'bhk' => 'fbl', 'bjd' => 'drl',
                'ccq' => 'rki', 'cjr' => 'mom', 'cka' => 'cmr', 'cmk' => 'xch',
                'cmn' => 'zh', 'drh' => 'khk', 'drw' => 'prs', 'gav' => 'dev',
                'gfx' => 'vaj', 'ggn' => 'gvr', 'gti' => 'nyc', 'guv' => 'duz',
                'hrr' => 'jal', 'ibi' => 'opa', 'ilw' => 'gal', 'in' => 'id',
                'iw' => 'he', 'jeg' => 'oyb', 'ji' => 'yi', 'jw' => 'jv',
                'kgc' => 'tdf', 'kgh' => 'kml', 'koj' => 'kwv', 'krm' => 'bmf',
                'ktr' => 'dtp', 'kvs' => 'gdj', 'kwq' => 'yam', 'kxe' => 'tvd',
                'kzj' => 'dtp', 'kzt' => 'dtp', 'lii' => 'raq', 'lmm' => 'rmx',
                'meg' => 'cir', 'mo' => 'ro', 'mst' => 'mry', 'mwj' => 'vaj',
                'myt' => 'mry', 'nad' => 'xny', 'ncp' => 'kdz', 'nnx' => 'ngv',
                'no-bok' => 'nb', 'no-nyn' => 'nn', 'nts' => 'pij',
                'oun' => 'vaj', 'pcr' => 'adx', 'pmc' => 'huw', 'pmu' => 'phr',
                'ppa' => 'bfy', 'ppr' => 'lcq', 'pry' => 'prt', 'puz' => 'pub',
                'sca' => 'hle', 'skk' => 'oyb', 'tdu' => 'dtp', 'thc' => 'tpo',
                'thx' => 'oyb', 'tie' => 'ras', 'tkk' => 'twm', 'tl' => 'fil',
                'tlw' => 'weo', 'tmp' => 'tyj', 'tne' => 'kak', 'tnf' => 'prs',
                'tsf' => 'taj', 'uok' => 'ema', 'xba' => 'cax', 'xia' => 'acn',
                'xkh' => 'waw', 'xpe' => 'kpe', 'xsj' => 'suj', 'ybd' => 'rki',
                'yma' => 'lrr', 'ymt' => 'mtm', 'yos' => 'zom', 'yuu' => 'yug',
            ];
            if (isset($languageAliasCanonical[$result['language']])) {
                $result['language'] = $languageAliasCanonical[$result['language']];
            }
            // Multi-tag language replacements: <languageAlias> entries with
            // more than one tag in the replacement value contribute the
            // extra subtags only if the source has none of its own.
            if ($result['language'] === 'sh') {
                $result['language'] = 'sr';
                if (!isset($result['script']) || $result['script'] === '') {
                    $result['script'] = 'Latn';
                }
            } elseif ($result['language'] === 'cnr') {
                $result['language'] = 'sr';
                if (!isset($result['region']) || $result['region'] === '') {
                    $result['region'] = 'ME';
                }
            }
            // sgn-XX language replacements: when the source language is
            // "sgn" and a region subtag is present, the combined
            // (lang, region) pair maps to a specific sign-language code
            // and the region is dropped.
            if ($result['language'] === 'sgn' && isset($result['region']) && $result['region'] !== '') {
                static $sgnAliases = [
                    'AE' => 'ase', 'BR' => 'bzs', 'CO' => 'csn', 'DE' => 'gsg',
                    'DK' => 'dsl', 'ES' => 'ssp', 'FR' => 'fsl', 'GB' => 'bfi',
                    'GR' => 'gss', 'IE' => 'isg', 'IT' => 'ise', 'JP' => 'jsl',
                    'MX' => 'mfs', 'NI' => 'ncs', 'NL' => 'dse', 'NO' => 'nsi',
                    'PT' => 'psr', 'SE' => 'swl', 'US' => 'ase', 'ZA' => 'sfs',
                ];
                if (isset($sgnAliases[$result['region']])) {
                    $result['language'] = $sgnAliases[$result['region']];
                    unset($result['region']);
                }
            }
            if (isset($result['region'])) {
                static $regionAliasCanonical = [
                    'BU' => 'MM', 'DD' => 'DE', 'FX' => 'FR', 'TP' => 'TL',
                    'YD' => 'YE', 'ZR' => 'CD', 'CT' => 'KI', 'NH' => 'VU',
                    'RH' => 'ZW', 'VD' => 'VN', 'AN' => 'CW',
                    // CLDR M.49 numeric -> alpha-2 territoryAlias map.
                    '004' => 'AF', '008' => 'AL', '012' => 'DZ',
                    '016' => 'AS', '020' => 'AD', '024' => 'AO',
                    '028' => 'AG', '031' => 'AZ', '032' => 'AR',
                    '036' => 'AU', '040' => 'AT', '044' => 'BS',
                    '048' => 'BH', '050' => 'BD', '051' => 'AM',
                    '052' => 'BB', '056' => 'BE', '060' => 'BM',
                    '064' => 'BT', '068' => 'BO', '070' => 'BA',
                    '072' => 'BW', '076' => 'BR', '084' => 'BZ',
                    '090' => 'SB', '096' => 'BN', '100' => 'BG',
                    '104' => 'MM', '108' => 'BI', '112' => 'BY',
                    '116' => 'KH', '120' => 'CM', '124' => 'CA',
                    '132' => 'CV', '136' => 'KY', '140' => 'CF',
                    '144' => 'LK', '148' => 'TD', '152' => 'CL',
                    '156' => 'CN', '170' => 'CO', '174' => 'KM',
                    '178' => 'CG', '180' => 'CD', '184' => 'CK',
                    '188' => 'CR', '191' => 'HR', '192' => 'CU',
                    '196' => 'CY', '203' => 'CZ', '204' => 'BJ',
                    '208' => 'DK', '212' => 'DM', '214' => 'DO',
                    '218' => 'EC', '222' => 'SV', '226' => 'GQ',
                    '231' => 'ET', '232' => 'ER', '233' => 'EE',
                    '242' => 'FJ', '246' => 'FI', '250' => 'FR',
                    '258' => 'PF', '262' => 'DJ', '266' => 'GA',
                    '268' => 'GE', '270' => 'GM', '276' => 'DE',
                    '288' => 'GH', '296' => 'KI', '300' => 'GR',
                    '308' => 'GD', '320' => 'GT', '324' => 'GN',
                    '328' => 'GY', '332' => 'HT', '340' => 'HN',
                    '344' => 'HK', '348' => 'HU', '352' => 'IS',
                    '356' => 'IN', '360' => 'ID', '364' => 'IR',
                    '368' => 'IQ', '372' => 'IE', '376' => 'IL',
                    '380' => 'IT', '384' => 'CI', '388' => 'JM',
                    '392' => 'JP', '398' => 'KZ', '400' => 'JO',
                    '404' => 'KE', '408' => 'KP', '410' => 'KR',
                    '414' => 'KW', '417' => 'KG', '418' => 'LA',
                    '422' => 'LB', '426' => 'LS', '428' => 'LV',
                    '430' => 'LR', '434' => 'LY', '438' => 'LI',
                    '440' => 'LT', '442' => 'LU', '446' => 'MO',
                    '450' => 'MG', '454' => 'MW', '458' => 'MY',
                    '462' => 'MV', '466' => 'ML', '470' => 'MT',
                    '478' => 'MR', '480' => 'MU', '484' => 'MX',
                    '492' => 'MC', '496' => 'MN', '498' => 'MD',
                    '499' => 'ME', '500' => 'MS', '504' => 'MA',
                    '508' => 'MZ', '512' => 'OM', '516' => 'NA',
                    '520' => 'NR', '524' => 'NP', '528' => 'NL',
                    '533' => 'AW', '540' => 'NC', '548' => 'VU',
                    '554' => 'NZ', '558' => 'NI', '562' => 'NE',
                    '566' => 'NG', '570' => 'NU', '578' => 'NO',
                    '583' => 'FM', '584' => 'MH', '585' => 'PW',
                    '586' => 'PK', '591' => 'PA', '598' => 'PG',
                    '600' => 'PY', '604' => 'PE', '608' => 'PH',
                    '616' => 'PL', '620' => 'PT', '624' => 'GW',
                    '626' => 'TL', '630' => 'PR', '634' => 'QA',
                    '642' => 'RO', '643' => 'RU', '646' => 'RW',
                    '659' => 'KN', '662' => 'LC', '670' => 'VC',
                    '674' => 'SM', '678' => 'ST', '682' => 'SA',
                    '686' => 'SN', '688' => 'RS', '690' => 'SC',
                    '694' => 'SL', '702' => 'SG', '703' => 'SK',
                    '704' => 'VN', '705' => 'SI', '706' => 'SO',
                    '710' => 'ZA', '716' => 'ZW', '724' => 'ES',
                    '729' => 'SD', '732' => 'EH', '740' => 'SR',
                    '748' => 'SZ', '752' => 'SE', '756' => 'CH',
                    '760' => 'SY', '762' => 'TJ', '764' => 'TH',
                    '768' => 'TG', '776' => 'TO', '780' => 'TT',
                    '784' => 'AE', '788' => 'TN', '792' => 'TR',
                    '795' => 'TM', '798' => 'TV', '800' => 'UG',
                    '804' => 'UA', '807' => 'MK', '818' => 'EG',
                    '826' => 'GB', '834' => 'TZ', '840' => 'US',
                    '854' => 'BF', '858' => 'UY', '860' => 'UZ',
                    '862' => 'VE', '882' => 'WS', '887' => 'YE',
                    '894' => 'ZM',
                ];
                if (isset($regionAliasCanonical[$result['region']])) {
                    $result['region'] = $regionAliasCanonical[$result['region']];
                }
                // CLDR multi-region territoryAlias entries: pick the
                // likely region based on the language (and script,
                // if present). Falls back to the first listed region
                // when no likelySubtags hit applies.
                static $multiRegionAliases = [
                    'SU' => ['RU', 'AM', 'AZ', 'BY', 'EE', 'GE', 'KZ', 'KG',
                        'LV', 'LT', 'MD', 'TJ', 'TM', 'UA', 'UZ'],
                    '810' => ['RU', 'AM', 'AZ', 'BY', 'EE', 'GE', 'KZ', 'KG',
                        'LV', 'LT', 'MD', 'TJ', 'TM', 'UA', 'UZ'],
                    'CS' => ['RS', 'ME'],
                    '891' => ['RS', 'ME'],
                    'NT' => ['SA', 'IQ'],
                    '536' => ['SA', 'IQ'],
                    'PC' => ['FM', 'MH', 'MP', 'PW'],
                ];
                if (isset($multiRegionAliases[$result['region']])) {
                    $candidates = $multiRegionAliases[$result['region']];
                    $likelyRegion = null;
                    $lookupKey = strtolower(($result['language'] ?? 'und'));
                    if (!empty($result['script'])) {
                        $scriptKey = $lookupKey . '-' . strtolower($result['script']);
                        $table = self::likelySubtagsTable();
                        if (isset($table[$scriptKey])) {
                            $likelyRegion = $table[$scriptKey]['region'];
                        }
                    }
                    if ($likelyRegion === null) {
                        $table = self::likelySubtagsTable();
                        if (isset($table[$lookupKey])) {
                            $likelyRegion = $table[$lookupKey]['region'];
                        }
                    }
                    $result['region'] = (
                        $likelyRegion !== null
                        && in_array($likelyRegion, $candidates, true)
                    )
                        ? $likelyRegion
                        : $candidates[0];
                }
            }
            // Extract variants (alphanum{5,8} or digit alphanum{3}) from the
            // original tag. ICU's parseLocale exposes them via numbered
            // variant0..variantN keys but also strips them. Walk the raw
            // tag instead so we preserve order and unique-only variants.
            $variants = [];
            $rawParts = explode('-', $tag);
            $idx = 1;
            // Skip script.
            if (isset($rawParts[$idx]) && strlen($rawParts[$idx]) === 4 && ctype_alpha($rawParts[$idx])) {
                $idx++;
            }
            // Skip region.
            if (
                isset($rawParts[$idx])
                && (
                    (strlen($rawParts[$idx]) === 2 && ctype_alpha($rawParts[$idx]))
                    || (strlen($rawParts[$idx]) === 3 && ctype_digit($rawParts[$idx]))
                )
            ) {
                $idx++;
            }
            while (isset($rawParts[$idx])) {
                $sub = $rawParts[$idx];
                $sLen = strlen($sub);
                if ($sLen === 1) {
                    break;
                }
                $isLong = ($sLen >= 5 && $sLen <= 8 && ctype_alnum($sub));
                $isShortNumeric = ($sLen === 4 && ctype_digit($sub[0]) && ctype_alnum($sub));
                if (!$isLong && !$isShortNumeric) {
                    break;
                }
                $variants[strtolower($sub)] = true;
                $idx++;
            }
            // CLDR variantAlias replacement. Multi-variant sequences are
            // replaced first so `hepburn-heploc` collapses to `alalc97`
            // instead of producing two independent replacements.
            if (!empty($variants)) {
                $variantList = array_keys($variants);
                static $multiVariantAliases = [
                    'hepburn-heploc' => 'alalc97',
                ];
                static $variantAliases = [
                    'heploc' => 'alalc97',
                    'aaland' => '',
                    'arevela' => '',
                    'arevmda' => '',
                ];
                // Some variant aliases promote to a language replacement
                // (CLDR `<variantAlias type=... replacement="<lang>"/>`).
                // When the source language matches, the variant is dropped
                // and the language is rewritten.
                static $variantToLanguageAliases = [
                    'arevmda' => ['hy' => 'hyw'],
                    'lojban' => ['art' => 'jbo'],
                    'gaulish' => ['cel' => 'xtg'],
                    'hakka' => ['zh' => 'hak'],
                    'xiang' => ['zh' => 'hsn'],
                    'guoyu' => ['zh' => 'zh'],
                    'bokmal' => ['no' => 'nb'],
                    'nynorsk' => ['no' => 'nn'],
                    'saaho' => ['aa' => 'ssy'],
                ];
                foreach ($variantToLanguageAliases as $vName => $langMap) {
                    if (
                        in_array($vName, $variantList, true)
                        && isset($langMap[$result['language']])
                    ) {
                        $result['language'] = $langMap[$result['language']];
                        $variantList = array_values(array_filter(
                            $variantList,
                            static fn(string $v): bool => $v !== $vName,
                        ));
                    }
                }
                foreach ($multiVariantAliases as $from => $to) {
                    $fromParts = explode('-', $from);
                    $matches = !array_diff($fromParts, $variantList);
                    if ($matches) {
                        $variantList = array_values(array_diff($variantList, $fromParts));
                        if ($to !== '') {
                            $variantList[] = $to;
                        }
                    }
                }
                $remapped = [];
                foreach ($variantList as $v) {
                    if (array_key_exists($v, $variantAliases)) {
                        $repl = $variantAliases[$v];
                        if ($repl !== '') {
                            $remapped[$repl] = true;
                        }
                    } else {
                        $remapped[$v] = true;
                    }
                }
                $variants = $remapped;
            }
            if (!empty($variants)) {
                ksort($variants);
                $result['variants'] = array_keys($variants);
            }

            // Walk the tag once to split off other-extension singletons
            // (`-a-…`, `-t-…`, etc.) and the `-x-` private-use tail. The
            // unicode extension (`-u-…`) is parsed below for its
            // semantic content; the rest are kept as raw payload strings
            // so the canonical form can re-emit them in singleton order.
            $publicTag = $tag;
            $otherExtensions = [];
            $privateUse = null;
            $structurePattern = '/^([a-zA-Z]{2,8}(?:-[a-zA-Z]{4})?(?:-[a-zA-Z]{2}|-\d{3})?'
                . '(?:-[a-zA-Z0-9]{4,8})*(?:-[a-zA-Z0-9]{5,8})*)'
                . '((?:-[a-zA-Z0-9]-[a-zA-Z0-9-]+)*)$/i';
            if (
                preg_match(
                    $structurePattern,
                    $tag,
                    $structureMatch,
                ) === 1
            ) {
                $publicTag = $structureMatch[1];
                $extensionTail = $structureMatch[2];
                if ($extensionTail !== '') {
                    // Handle the `-x-` private-use boundary specially: once
                    // a `-x-` singleton is seen, everything that follows is
                    // private use and must not be re-parsed as a fresh
                    // extension singleton.
                    $xPos = false;
                    if (preg_match('/-x-/i', $extensionTail, $xMatch, PREG_OFFSET_CAPTURE)) {
                        $xPos = $xMatch[0][1];
                        $privateUse = strtolower(substr($extensionTail, $xPos + 3));
                        $extensionTail = substr($extensionTail, 0, $xPos);
                    }
                    if (
                        $extensionTail !== ''
                        && preg_match_all(
                            '/-([a-zA-Z0-9])-((?:[a-zA-Z0-9]+(?:-[a-zA-Z0-9]+)*?)(?=(?:-[a-zA-Z0-9]-)|$))/',
                            $extensionTail,
                            $matches,
                            PREG_SET_ORDER,
                        )
                    ) {
                        foreach ($matches as $m) {
                            $singleton = strtolower($m[1]);
                            $payload = strtolower($m[2]);
                            if ($singleton === 'u') {
                                $publicTag .= '-u-' . $payload;
                            } else {
                                $otherExtensions[$singleton] = $payload;
                            }
                        }
                    }
                }
            }
            if (preg_match('/-u-(.+?)(?=-[a-wy-z]-|$)/i', $publicTag, $extMatch)) {
                $extStr = strtolower($extMatch[1]);
                $extParts = explode('-', $extStr);
                $i = 0;
                $count = count($extParts);
                $attributes = [];
                $keywords = [];
                $lastKey = null;
                // Leading subtags of length >= 3 are attributes.
                while ($i < $count && strlen($extParts[$i]) >= 3) {
                    $attributes[] = $extParts[$i];
                    $i++;
                }
                while ($i < $count) {
                    $key = $extParts[$i];
                    if (strlen($key) !== 2) {
                        $i++;
                        continue;
                    }
                    $i++;
                    $values = [];
                    while ($i < $count && strlen($extParts[$i]) >= 3) {
                        $values[] = $extParts[$i];
                        $i++;
                    }
                    // Spec keeps the FIRST occurrence of a duplicate
                    // keyword and discards later ones.
                    if (!isset($keywords[$key])) {
                        $keywords[$key] = $values;
                    }
                }
                // Sort attributes and keywords in US-ASCII order.
                sort($attributes, SORT_STRING);
                ksort($keywords);
                if (!empty($attributes)) {
                    $result['unicodeAttributes'] = $attributes;
                }
                if (!empty($keywords)) {
                    $result['unicodeKeywords'] = $keywords;
                }
                // Mirror the well-known keys to the legacy slot names so
                // existing getters continue to function.
                $legacyMap = [
                    'ca' => 'calendar',
                    'co' => 'collation',
                    'fw' => 'firstDayOfWeek',
                    'hc' => 'hourCycle',
                    'kf' => 'caseFirst',
                    'kn' => 'numeric',
                    'nu' => 'numberingSystem',
                ];
                static $calendarAliases = [
                    'islamicc' => 'islamic-civil',
                    'ethiopic-amete-alem' => 'ethioaa',
                    'gregorian' => 'gregory',
                ];
                // UTS35 BCP47 type alias "yes" -> "true" for keyword
                // keys that explicitly list "yes" as an alias of "true".
                // Ordering must be preserved.
                static $yesToTrueKeys = ['kb', 'kc', 'kh', 'kk', 'kn'];
                foreach ($yesToTrueKeys as $yesKey) {
                    if (
                        isset($keywords[$yesKey])
                        && count($keywords[$yesKey]) === 1
                        && $keywords[$yesKey][0] === 'yes'
                    ) {
                        // "true" is the canonical default and renders as
                        // the bare key with no value subtag.
                        $keywords[$yesKey] = [];
                    }
                }
                // CLDR <type alias=...> replacements for -u- extension
                // values. Each table maps deprecated value -> canonical
                // value for a specific key.
                static $unicodeTypeAliases = [
                    // ks (colStrength)
                    'ks' => [
                        'primary' => 'level1',
                        'secondary' => 'level2',
                        'tertiary' => 'level3',
                        'quaternary' => 'level4',
                        'quarternary' => 'level4',
                        'identical' => 'identic',
                    ],
                    // ms (measurement system)
                    'ms' => [
                        'imperial' => 'uksystem',
                    ],
                    // tz (timezone)
                    'tz' => [
                        'cnckg' => 'cnsha',
                        'eire' => 'iedub',
                        'est' => 'papty',
                        'gmt0' => 'gmt',
                        'uct' => 'utc',
                        'zulu' => 'utc',
                    ],
                    // ca (calendar) — same as $calendarAliases above; the
                    // legacy-slot path still applies them for the
                    // corresponding result['calendar'] field.
                    'ca' => [
                        'islamicc' => 'islamic-civil',
                        'ethiopic-amete-alem' => 'ethioaa',
                        'gregorian' => 'gregory',
                    ],
                ];
                static $subdivisionAliases = [
                    'no23' => 'no50', 'cn11' => 'cnbj', 'cz10a' => 'cz110',
                    'fra' => 'frges', 'frg' => 'frges', 'lud' => 'lucl',
                ];
                foreach ($unicodeTypeAliases as $aliasKey => $aliasMap) {
                    if (!isset($keywords[$aliasKey])) {
                        continue;
                    }
                    $combined = implode('-', $keywords[$aliasKey]);
                    if (isset($aliasMap[$combined])) {
                        $canonical = $aliasMap[$combined];
                        $keywords[$aliasKey] = $canonical === '' ? [] : explode('-', $canonical);
                    }
                }
                // Subdivision aliases apply to `sd` and `rg` keys; the
                // value is a single subdivision code.
                foreach (['sd', 'rg'] as $sdKey) {
                    if (!isset($keywords[$sdKey])) {
                        continue;
                    }
                    $val = implode('-', $keywords[$sdKey]);
                    if (isset($subdivisionAliases[$val])) {
                        $keywords[$sdKey] = [$subdivisionAliases[$val]];
                    }
                }
                foreach ($legacyMap as $key => $slot) {
                    if (!isset($keywords[$key])) {
                        continue;
                    }
                    $vals = $keywords[$key];
                    $valStr = empty($vals) ? '' : implode('-', $vals);
                    if ($slot === 'numeric') {
                        $result[$slot] = $valStr === '' || $valStr === 'true';
                    } else {
                        $result[$slot] = $valStr === 'true' ? '' : $valStr;
                        if ($slot === 'calendar' && isset($calendarAliases[$result[$slot]])) {
                            $canonical = $calendarAliases[$result[$slot]];
                            $result[$slot] = $canonical;
                            // Update the keywords list so toString sees the
                            // canonical value too.
                            $keywords[$key] = $canonical === '' ? [] : explode('-', $canonical);
                        }
                    }
                }
                // Re-store keywords in case calendar canonicalization
                // mutated them above.
                if (!empty($keywords)) {
                    $result['unicodeKeywords'] = $keywords;
                }
            }
            if (!empty($otherExtensions)) {
                ksort($otherExtensions);
                $result['otherExtensions'] = $otherExtensions;
            }
            if ($privateUse !== null) {
                $result['privateUse'] = $privateUse;
            }

            return $result;
        }

        // Basic parsing without ICU.
        $parts = explode('-', $tag);
        if (!preg_match('/^[a-zA-Z]{2,8}$/', $parts[0])) {
            return null;
        }

        $result = ['language' => strtolower($parts[0])];
        $i = 1;

        if (isset($parts[$i]) && strlen($parts[$i]) === 4 && ctype_alpha($parts[$i])) {
            $result['script'] = ucfirst(strtolower($parts[$i]));
            $i++;
        }

        if (
            isset($parts[$i]) && (
            (strlen($parts[$i]) === 2 && ctype_alpha($parts[$i])) ||
            (strlen($parts[$i]) === 3 && ctype_digit($parts[$i]))
            )
        ) {
            $result['region'] = strtoupper($parts[$i]);
            $i++;
        }

        return $result;
    }

    /**
     * Reconstruct a BCP 47 tag from parsed components.
     *
     * @param array<string, mixed> $parsed
     */
    /**
     * Apply CLDR territoryAlias replacements to a parsed locale's
     * region field. Mirrors the inline alias logic used by
     * parseLocaleTag so option-supplied regions canonicalise too.
     *
     * @param array<string, mixed> $parsed
     * @return array<string, mixed>
     */
    private static function applyRegionAlias(array $parsed): array
    {
        if (!isset($parsed['region']) || $parsed['region'] === '') {
            return $parsed;
        }
        $region = $parsed['region'];
        static $singleRegionAliases = null;
        if ($singleRegionAliases === null) {
            // Reuse the M.49 -> alpha-2 + legacy alpha-2 map by
            // calling parseLocaleTag on a probe tag and reading
            // back the canonical form.
            $singleRegionAliases = [];
            $probe = self::parseLocaleTag('en-554');
            // We don't actually need to introspect; build the same
            // map inline. (Mirrored from parseLocaleTag.)
            $singleRegionAliases = [
                'BU' => 'MM', 'DD' => 'DE', 'FX' => 'FR', 'TP' => 'TL',
                'YD' => 'YE', 'ZR' => 'CD', 'CT' => 'KI', 'NH' => 'VU',
                'RH' => 'ZW', 'VD' => 'VN', 'AN' => 'CW',
                '004' => 'AF', '008' => 'AL', '012' => 'DZ',
                '016' => 'AS', '020' => 'AD', '024' => 'AO',
                '028' => 'AG', '031' => 'AZ', '032' => 'AR',
                '036' => 'AU', '040' => 'AT', '044' => 'BS',
                '048' => 'BH', '050' => 'BD', '051' => 'AM',
                '052' => 'BB', '056' => 'BE', '060' => 'BM',
                '064' => 'BT', '068' => 'BO', '070' => 'BA',
                '072' => 'BW', '076' => 'BR', '084' => 'BZ',
                '090' => 'SB', '096' => 'BN', '100' => 'BG',
                '104' => 'MM', '108' => 'BI', '112' => 'BY',
                '116' => 'KH', '120' => 'CM', '124' => 'CA',
                '132' => 'CV', '136' => 'KY', '140' => 'CF',
                '144' => 'LK', '148' => 'TD', '152' => 'CL',
                '156' => 'CN', '170' => 'CO', '174' => 'KM',
                '178' => 'CG', '180' => 'CD', '184' => 'CK',
                '188' => 'CR', '191' => 'HR', '192' => 'CU',
                '196' => 'CY', '203' => 'CZ', '204' => 'BJ',
                '208' => 'DK', '212' => 'DM', '214' => 'DO',
                '218' => 'EC', '222' => 'SV', '226' => 'GQ',
                '231' => 'ET', '232' => 'ER', '233' => 'EE',
                '242' => 'FJ', '246' => 'FI', '250' => 'FR',
                '258' => 'PF', '262' => 'DJ', '266' => 'GA',
                '268' => 'GE', '270' => 'GM', '276' => 'DE',
                '288' => 'GH', '296' => 'KI', '300' => 'GR',
                '308' => 'GD', '320' => 'GT', '324' => 'GN',
                '328' => 'GY', '332' => 'HT', '340' => 'HN',
                '344' => 'HK', '348' => 'HU', '352' => 'IS',
                '356' => 'IN', '360' => 'ID', '364' => 'IR',
                '368' => 'IQ', '372' => 'IE', '376' => 'IL',
                '380' => 'IT', '384' => 'CI', '388' => 'JM',
                '392' => 'JP', '398' => 'KZ', '400' => 'JO',
                '404' => 'KE', '408' => 'KP', '410' => 'KR',
                '414' => 'KW', '417' => 'KG', '418' => 'LA',
                '422' => 'LB', '426' => 'LS', '428' => 'LV',
                '430' => 'LR', '434' => 'LY', '438' => 'LI',
                '440' => 'LT', '442' => 'LU', '446' => 'MO',
                '450' => 'MG', '454' => 'MW', '458' => 'MY',
                '462' => 'MV', '466' => 'ML', '470' => 'MT',
                '478' => 'MR', '480' => 'MU', '484' => 'MX',
                '492' => 'MC', '496' => 'MN', '498' => 'MD',
                '499' => 'ME', '500' => 'MS', '504' => 'MA',
                '508' => 'MZ', '512' => 'OM', '516' => 'NA',
                '520' => 'NR', '524' => 'NP', '528' => 'NL',
                '533' => 'AW', '540' => 'NC', '548' => 'VU',
                '554' => 'NZ', '558' => 'NI', '562' => 'NE',
                '566' => 'NG', '570' => 'NU', '578' => 'NO',
                '583' => 'FM', '584' => 'MH', '585' => 'PW',
                '586' => 'PK', '591' => 'PA', '598' => 'PG',
                '600' => 'PY', '604' => 'PE', '608' => 'PH',
                '616' => 'PL', '620' => 'PT', '624' => 'GW',
                '626' => 'TL', '630' => 'PR', '634' => 'QA',
                '642' => 'RO', '643' => 'RU', '646' => 'RW',
                '659' => 'KN', '662' => 'LC', '670' => 'VC',
                '674' => 'SM', '678' => 'ST', '682' => 'SA',
                '686' => 'SN', '688' => 'RS', '690' => 'SC',
                '694' => 'SL', '702' => 'SG', '703' => 'SK',
                '704' => 'VN', '705' => 'SI', '706' => 'SO',
                '710' => 'ZA', '716' => 'ZW', '724' => 'ES',
                '729' => 'SD', '732' => 'EH', '740' => 'SR',
                '748' => 'SZ', '752' => 'SE', '756' => 'CH',
                '760' => 'SY', '762' => 'TJ', '764' => 'TH',
                '768' => 'TG', '776' => 'TO', '780' => 'TT',
                '784' => 'AE', '788' => 'TN', '792' => 'TR',
                '795' => 'TM', '798' => 'TV', '800' => 'UG',
                '804' => 'UA', '807' => 'MK', '818' => 'EG',
                '826' => 'GB', '834' => 'TZ', '840' => 'US',
                '854' => 'BF', '858' => 'UY', '860' => 'UZ',
                '862' => 'VE', '882' => 'WS', '887' => 'YE',
                '894' => 'ZM',
            ];
            unset($probe);
        }
        if (isset($singleRegionAliases[$region])) {
            $parsed['region'] = $singleRegionAliases[$region];
        }
        return $parsed;
    }

    /**
     * Canonicalise the payload of a `-t-` (transformed) extension:
     *   - Detect the optional tlang prefix (language [-script] [-region]
     *     [-variants]*) and its variants.
     *   - Sort tlang variants alphabetically.
     *   - Apply legacy-language aliases inside tlang (iw → he, etc.).
     *   - Sort the tfields (tkey + tvalue runs) alphabetically by tkey
     *     and lowercase everything.
     *
     * Returns the canonical payload (without the leading `t-`).
     */
    private static function canonicalizeTransformedExtension(string $payload): string
    {
        $payload = strtolower($payload);
        $tokens = explode('-', $payload);
        $count = count($tokens);
        $i = 0;
        $tlang = null;
        // Detect tlang prefix: starts with a non-tkey alphabetic
        // token of length 2-3 or 5-8.
        $isTkey = static function (string $sub): bool {
            return strlen($sub) === 2
                && ctype_alpha($sub[0])
                && ctype_digit($sub[1]);
        };
        if (!$isTkey($tokens[$i])) {
            $first = $tokens[$i];
            $firstLen = strlen($first);
            if (
                ctype_alpha($first)
                && ($firstLen === 2 || $firstLen === 3
                    || ($firstLen >= 5 && $firstLen <= 8))
            ) {
                // tlang language.
                $tlangLanguage = $first;
                $i++;
                $tlangScript = null;
                $tlangRegion = null;
                $tlangVariants = [];
                if (
                    $i < $count
                    && !$isTkey($tokens[$i])
                    && strlen($tokens[$i]) === 4
                    && ctype_alpha($tokens[$i])
                ) {
                    $tlangScript = $tokens[$i];
                    $i++;
                }
                if (
                    $i < $count
                    && !$isTkey($tokens[$i])
                    && (
                        (strlen($tokens[$i]) === 2 && ctype_alpha($tokens[$i]))
                        || (strlen($tokens[$i]) === 3 && ctype_digit($tokens[$i]))
                    )
                ) {
                    $tlangRegion = $tokens[$i];
                    $i++;
                }
                while ($i < $count && !$isTkey($tokens[$i])) {
                    $sub = $tokens[$i];
                    $subLen = strlen($sub);
                    $isLong = $subLen >= 5 && $subLen <= 8 && ctype_alnum($sub);
                    $isShortNum = $subLen === 4
                        && ctype_digit($sub[0])
                        && ctype_alnum($sub);
                    if (!$isLong && !$isShortNum) {
                        break;
                    }
                    $tlangVariants[] = $sub;
                    $i++;
                }
                // Apply legacy language aliases.
                static $tlangLangAlias = [
                    'iw' => 'he', 'ji' => 'yi', 'in' => 'id',
                    'mo' => 'ro', 'tl' => 'fil', 'jw' => 'jv',
                    'sh' => 'sr', 'aam' => 'aas', 'aar' => 'aa',
                ];
                if (isset($tlangLangAlias[$tlangLanguage])) {
                    $tlangLanguage = $tlangLangAlias[$tlangLanguage];
                }
                sort($tlangVariants, SORT_STRING);
                $tlangParts = [$tlangLanguage];
                if ($tlangScript !== null) {
                    $tlangParts[] = $tlangScript;
                }
                if ($tlangRegion !== null) {
                    $tlangParts[] = $tlangRegion;
                }
                foreach ($tlangVariants as $v) {
                    $tlangParts[] = $v;
                }
                $tlang = implode('-', $tlangParts);
            }
        }
        // Collect tfields: (tkey tvalue+)*.
        $tfields = [];
        while ($i < $count) {
            if (!$isTkey($tokens[$i])) {
                break;
            }
            $tkey = $tokens[$i];
            $i++;
            $values = [];
            while ($i < $count && !$isTkey($tokens[$i])) {
                $values[] = $tokens[$i];
                $i++;
            }
            // Apply known tvalue aliases (CLDR <transformAlias>):
            //   m0=names → m0=prprname
            static $tfieldAlias = [
                'm0' => ['names' => ['prprname']],
            ];
            if (isset($tfieldAlias[$tkey])) {
                $valueKey = implode('-', $values);
                if (isset($tfieldAlias[$tkey][$valueKey])) {
                    $values = $tfieldAlias[$tkey][$valueKey];
                }
            }
            $tfields[$tkey] = $values;
        }
        ksort($tfields);
        $out = [];
        if ($tlang !== null) {
            $out[] = $tlang;
        }
        foreach ($tfields as $tkey => $values) {
            $out[] = $tkey;
            foreach ($values as $v) {
                $out[] = $v;
            }
        }
        return implode('-', $out);
    }

    /**
     * @param array<mixed> $parsed
     */
    private static function reconstructLocaleTag(array $parsed): string
    {
        $parts = [];
        if (isset($parsed['language'])) {
            $parts[] = strtolower((string) $parsed['language']);
        }
        if (isset($parsed['script'])) {
            $parts[] = ucfirst(strtolower((string) $parsed['script']));
        }
        if (isset($parsed['region'])) {
            $parts[] = strtoupper((string) $parsed['region']);
        }
        if (isset($parsed['variants']) && is_array($parsed['variants'])) {
            foreach ($parsed['variants'] as $variant) {
                $parts[] = strtolower((string) $variant);
            }
        }

        // Build the unicode extension. Start from the parsed
        // attributes/keywords (which preserve unknown keywords in
        // US-ASCII order) and overlay any options that the constructor
        // applied through the legacy slot names.
        $attributes = [];
        if (isset($parsed['unicodeAttributes']) && is_array($parsed['unicodeAttributes'])) {
            $attributes = $parsed['unicodeAttributes'];
        }
        $keywords = [];
        if (isset($parsed['unicodeKeywords']) && is_array($parsed['unicodeKeywords'])) {
            $keywords = $parsed['unicodeKeywords'];
        }
        $legacyMap = [
            'calendar' => 'ca',
            'collation' => 'co',
            'firstDayOfWeek' => 'fw',
            'hourCycle' => 'hc',
            'caseFirst' => 'kf',
            'numeric' => 'kn',
            'numberingSystem' => 'nu',
        ];
        foreach ($legacyMap as $slot => $key) {
            if (!isset($parsed[$slot])) {
                continue;
            }
            $val = $parsed[$slot];
            if (is_bool($val)) {
                $keywords[$key] = $val ? [] : ['false'];
            } else {
                $valStr = (string) $val;
                $keywords[$key] = $valStr === '' ? [] : explode('-', $valStr);
            }
        }
        ksort($keywords);
        sort($attributes, SORT_STRING);
        // Collect every extension keyed by its singleton character,
        // then emit them in US-ASCII order with the `-x-` private use
        // tail forced last per UTS35.
        $extensionsBySingleton = [];
        if (!empty($attributes) || !empty($keywords)) {
            $uPayload = [];
            foreach ($attributes as $attr) {
                $uPayload[] = $attr;
            }
            foreach ($keywords as $key => $vals) {
                $uPayload[] = $key;
                foreach ($vals as $v) {
                    $uPayload[] = $v;
                }
            }
            $extensionsBySingleton['u'] = implode('-', $uPayload);
        }
        if (isset($parsed['otherExtensions']) && is_array($parsed['otherExtensions'])) {
            foreach ($parsed['otherExtensions'] as $singleton => $payload) {
                $singletonStr = (string) $singleton;
                $payloadStr = (string) $payload;
                // Canonicalise the transformed extension's payload:
                // sort tlang variants, sort tfields by tkey, lowercase
                // values, apply known language-tag aliases inside
                // tlang (iw -> he, etc.).
                if ($singletonStr === 't') {
                    $payloadStr = self::canonicalizeTransformedExtension($payloadStr);
                }
                $extensionsBySingleton[$singletonStr] = $payloadStr;
            }
        }
        ksort($extensionsBySingleton);
        foreach ($extensionsBySingleton as $singleton => $payload) {
            $parts[] = $singleton;
            foreach (explode('-', $payload) as $sub) {
                if ($sub !== '') {
                    $parts[] = $sub;
                }
            }
        }
        if (isset($parsed['privateUse']) && $parsed['privateUse'] !== '') {
            $parts[] = 'x';
            foreach (explode('-', (string) $parsed['privateUse']) as $sub) {
                if ($sub !== '') {
                    $parts[] = $sub;
                }
            }
        }

        return implode('-', $parts);
    }

    /**
     * CLDR likelySubtags lookup. Maps a partial language tag (just
     * language, language+script, language+region) to the full
     * language-script-region form expected by Intl.Locale.maximize().
     * Covers the common test262 fixtures plus some popular locales.
     *
     * @return array<string, array{language:string, script:string, region:string}>
     */
    private static function likelySubtagsTable(): array
    {
        static $table = null;
        if ($table !== null) {
            return $table;
        }
        // Each entry maps the input form to the maximized form.
        // Sourced from CLDR's likelySubtags.xml; only the entries
        // we've actually exercised in tests are listed here.
        $raw = [
            'aa' => 'aa-Latn-ET', 'aae' => 'aae-Latn-IT',
            'ab' => 'ab-Cyrl-GE', 'af' => 'af-Latn-ZA',
            'ak' => 'ak-Latn-GH', 'am' => 'am-Ethi-ET', 'ar' => 'ar-Arab-EG',
            'as' => 'as-Beng-IN', 'az' => 'az-Latn-AZ', 'be' => 'be-Cyrl-BY',
            'pap' => 'pap-Latn-CW',
            'und-CW' => 'pap-Latn-CW', 'und-AW' => 'pap-Latn-AW',
            'pap-AW' => 'pap-Latn-AW',
            'bg' => 'bg-Cyrl-BG', 'bm' => 'bm-Latn-ML', 'bn' => 'bn-Beng-BD',
            'bo' => 'bo-Tibt-CN', 'br' => 'br-Latn-FR', 'bs' => 'bs-Latn-BA',
            'ca' => 'ca-Latn-ES', 'ce' => 'ce-Cyrl-RU', 'co' => 'co-Latn-FR',
            'cs' => 'cs-Latn-CZ', 'cy' => 'cy-Latn-GB', 'da' => 'da-Latn-DK',
            'de' => 'de-Latn-DE', 'dz' => 'dz-Tibt-BT', 'ee' => 'ee-Latn-GH',
            'el' => 'el-Grek-GR', 'en' => 'en-Latn-US', 'eo' => 'eo-Latn-001',
            'es' => 'es-Latn-ES', 'et' => 'et-Latn-EE', 'eu' => 'eu-Latn-ES',
            'fa' => 'fa-Arab-IR', 'ff' => 'ff-Latn-SN', 'fi' => 'fi-Latn-FI',
            'fil' => 'fil-Latn-PH', 'fo' => 'fo-Latn-FO', 'fr' => 'fr-Latn-FR',
            'fy' => 'fy-Latn-NL', 'ga' => 'ga-Latn-IE', 'gd' => 'gd-Latn-GB',
            'gl' => 'gl-Latn-ES', 'gn' => 'gn-Latn-PY', 'gu' => 'gu-Gujr-IN',
            'gv' => 'gv-Latn-IM', 'ha' => 'ha-Latn-NG', 'he' => 'he-Hebr-IL',
            'hi' => 'hi-Deva-IN', 'hr' => 'hr-Latn-HR', 'hu' => 'hu-Latn-HU',
            'hy' => 'hy-Armn-AM', 'hyw' => 'hyw-Armn-AM',
            'id' => 'id-Latn-ID', 'ig' => 'ig-Latn-NG', 'ii' => 'ii-Yiii-CN',
            'is' => 'is-Latn-IS', 'it' => 'it-Latn-IT', 'iu' => 'iu-Cans-CA',
            'ja' => 'ja-Jpan-JP', 'jbo' => 'jbo-Latn-001', 'jv' => 'jv-Latn-ID',
            'ka' => 'ka-Geor-GE', 'kk' => 'kk-Cyrl-KZ', 'kl' => 'kl-Latn-GL',
            'km' => 'km-Khmr-KH', 'kn' => 'kn-Knda-IN', 'ko' => 'ko-Kore-KR',
            'ks' => 'ks-Arab-IN', 'ku' => 'ku-Latn-TR', 'kw' => 'kw-Latn-GB',
            'ky' => 'ky-Cyrl-KG', 'la' => 'la-Latn-VA', 'lb' => 'lb-Latn-LU',
            'ln' => 'ln-Latn-CD', 'lo' => 'lo-Laoo-LA', 'lt' => 'lt-Latn-LT',
            'lv' => 'lv-Latn-LV', 'mg' => 'mg-Latn-MG', 'mi' => 'mi-Latn-NZ',
            'mk' => 'mk-Cyrl-MK', 'ml' => 'ml-Mlym-IN', 'mn' => 'mn-Cyrl-MN',
            'mr' => 'mr-Deva-IN', 'ms' => 'ms-Latn-MY', 'mt' => 'mt-Latn-MT',
            'my' => 'my-Mymr-MM', 'nb' => 'nb-Latn-NO', 'ne' => 'ne-Deva-NP',
            'nl' => 'nl-Latn-NL', 'nn' => 'nn-Latn-NO', 'no' => 'no-Latn-NO',
            'or' => 'or-Orya-IN', 'pa' => 'pa-Guru-IN', 'pl' => 'pl-Latn-PL',
            'ps' => 'ps-Arab-AF', 'pt' => 'pt-Latn-BR', 'qu' => 'qu-Latn-PE',
            'rm' => 'rm-Latn-CH', 'rn' => 'rn-Latn-BI', 'ro' => 'ro-Latn-RO',
            'ru' => 'ru-Cyrl-RU', 'rw' => 'rw-Latn-RW', 'sa' => 'sa-Deva-IN',
            'sc' => 'sc-Latn-IT', 'sd' => 'sd-Arab-PK', 'se' => 'se-Latn-NO',
            'sg' => 'sg-Latn-CF', 'si' => 'si-Sinh-LK', 'sk' => 'sk-Latn-SK',
            'sl' => 'sl-Latn-SI', 'sn' => 'sn-Latn-ZW', 'so' => 'so-Latn-SO',
            'sq' => 'sq-Latn-AL', 'sr' => 'sr-Cyrl-RS', 'su' => 'su-Latn-ID',
            'sv' => 'sv-Latn-SE', 'sw' => 'sw-Latn-TZ', 'ta' => 'ta-Taml-IN',
            'te' => 'te-Telu-IN', 'tg' => 'tg-Cyrl-TJ', 'th' => 'th-Thai-TH',
            'ti' => 'ti-Ethi-ET', 'tk' => 'tk-Latn-TM', 'tn' => 'tn-Latn-ZA',
            'to' => 'to-Latn-TO', 'tr' => 'tr-Latn-TR', 'tt' => 'tt-Cyrl-RU',
            'ug' => 'ug-Arab-CN', 'uk' => 'uk-Cyrl-UA', 'und' => 'en-Latn-US',
            'ur' => 'ur-Arab-PK', 'uz' => 'uz-Latn-UZ', 'vi' => 'vi-Latn-VN',
            'wo' => 'wo-Latn-SN', 'xh' => 'xh-Latn-ZA', 'yi' => 'yi-Hebr-001',
            'yo' => 'yo-Latn-NG', 'zh' => 'zh-Hans-CN', 'zu' => 'zu-Latn-ZA',
            'zh-Hant' => 'zh-Hant-TW', 'zh-TW' => 'zh-Hant-TW',
            'und-Hant' => 'zh-Hant-TW', 'und-TW' => 'zh-Hant-TW',
            // Additional Chinese language varieties.
            'hak' => 'hak-Hans-CN', 'nan' => 'nan-Hans-CN',
            'wuu' => 'wuu-Hans-CN', 'hsn' => 'hsn-Hans-CN',
            'gan' => 'gan-Hans-CN', 'cdo' => 'cdo-Hant-CN',
            'cjy' => 'cjy-Hans-CN', 'cmn' => 'cmn-Hans-CN',
            'czh' => 'czh-Hans-CN', 'czo' => 'czo-Hans-CN',
            'mnp' => 'mnp-Hans-CN', 'lzh' => 'lzh-Hans-CN',
            // Language + script that doesn't follow language alone:
            // CLDR defaults each pair to a specific region.
            'en-shaw' => 'en-Shaw-GB',
            'en-arab' => 'en-Arab-US',
            'zh-hant' => 'zh-Hant-TW',
            'zh-hans' => 'zh-Hans-CN',
            'sr-latn' => 'sr-Latn-RS',
            'sr-cyrl' => 'sr-Cyrl-RS',
            'az-arab' => 'az-Arab-IR',
            'az-cyrl' => 'az-Cyrl-RU',
            'mn-mong' => 'mn-Mong-CN',
            // und-Script entries: pick the most-common language for
            // each script.
            'und-arab' => 'ar-Arab-EG',
            'und-armn' => 'hy-Armn-AM',
            'und-beng' => 'bn-Beng-BD',
            'und-cans' => 'cr-Cans-CA',
            'und-cyrl' => 'ru-Cyrl-RU',
            'und-deva' => 'hi-Deva-IN',
            'und-ethi' => 'am-Ethi-ET',
            'und-geor' => 'ka-Geor-GE',
            'und-grek' => 'el-Grek-GR',
            'und-gujr' => 'gu-Gujr-IN',
            'und-guru' => 'pa-Guru-IN',
            'und-hans' => 'zh-Hans-CN',
            'und-hant' => 'zh-Hant-TW',
            'und-hebr' => 'he-Hebr-IL',
            'und-hira' => 'ja-Jpan-JP',
            'und-jpan' => 'ja-Jpan-JP',
            'und-kana' => 'ja-Jpan-JP',
            'und-khmr' => 'km-Khmr-KH',
            'und-knda' => 'kn-Knda-IN',
            'und-kore' => 'ko-Kore-KR',
            'und-laoo' => 'lo-Laoo-LA',
            'und-latn' => 'en-Latn-US',
            'und-mlym' => 'ml-Mlym-IN',
            'und-mong' => 'mn-Mong-CN',
            'und-mymr' => 'my-Mymr-MM',
            'und-orya' => 'or-Orya-IN',
            'und-shaw' => 'en-Shaw-GB',
            'und-sinh' => 'si-Sinh-LK',
            'und-taml' => 'ta-Taml-IN',
            'und-telu' => 'te-Telu-IN',
            'und-thaa' => 'dv-Thaa-MV',
            'und-thai' => 'th-Thai-TH',
            'und-tibt' => 'bo-Tibt-CN',
            'und-yiii' => 'ii-Yiii-CN',
            // und-Region defaults: pick the most-common language for
            // each region (CLDR's likelySubtags emphasises the
            // primary language used).
            'und-419' => 'es-Latn-419',
            'und-001' => 'en-Latn-US',
            'und-150' => 'en-Latn-150',
            'und-AT' => 'de-Latn-AT', 'und-AR' => 'es-Latn-AR',
            'und-AU' => 'en-Latn-AU', 'und-BE' => 'nl-Latn-BE',
            'und-BO' => 'es-Latn-BO', 'und-BR' => 'pt-Latn-BR',
            'und-CA' => 'en-Latn-CA', 'und-CH' => 'de-Latn-CH',
            'und-CL' => 'es-Latn-CL', 'und-CN' => 'zh-Hans-CN',
            'und-CO' => 'es-Latn-CO', 'und-CR' => 'es-Latn-CR',
            'und-DE' => 'de-Latn-DE', 'und-DK' => 'da-Latn-DK',
            'und-DO' => 'es-Latn-DO', 'und-EC' => 'es-Latn-EC',
            'und-EG' => 'ar-Arab-EG', 'und-ES' => 'es-Latn-ES',
            'und-FR' => 'fr-Latn-FR', 'und-GB' => 'en-Latn-GB',
            'und-GR' => 'el-Grek-GR', 'und-GT' => 'es-Latn-GT',
            'und-HK' => 'zh-Hant-HK', 'und-HN' => 'es-Latn-HN',
            'und-IE' => 'en-Latn-IE', 'und-IL' => 'he-Hebr-IL',
            'und-IN' => 'hi-Deva-IN', 'und-IT' => 'it-Latn-IT',
            'und-JP' => 'ja-Jpan-JP', 'und-KR' => 'ko-Kore-KR',
            'und-MX' => 'es-Latn-MX', 'und-NL' => 'nl-Latn-NL',
            'und-NO' => 'nb-Latn-NO', 'und-NZ' => 'en-Latn-NZ',
            'und-PA' => 'es-Latn-PA', 'und-PE' => 'es-Latn-PE',
            'und-PL' => 'pl-Latn-PL', 'und-PT' => 'pt-Latn-PT',
            'und-PY' => 'gn-Latn-PY', 'und-RO' => 'ro-Latn-RO',
            'und-RU' => 'ru-Cyrl-RU', 'und-SE' => 'sv-Latn-SE',
            'und-SG' => 'en-Latn-SG', 'und-TH' => 'th-Thai-TH',
            'und-TR' => 'tr-Latn-TR',
            'und-UA' => 'uk-Cyrl-UA', 'und-US' => 'en-Latn-US',
            'und-UY' => 'es-Latn-UY', 'und-VE' => 'es-Latn-VE',
            'und-VN' => 'vi-Latn-VN',
            // und-Script-Region specifics where the language differs
            // from the script's default (CLDR fixedSubtags).
            'und-cyrl-ro' => 'bg-Cyrl-RO',
            'und-arab-bg' => 'ar-Arab-EG',
        ];
        $table = [];
        foreach ($raw as $key => $val) {
            $parts = explode('-', $val);
            // Lookup is performed with lowercased candidate keys.
            $table[strtolower($key)] = [
                'language' => $parts[0],
                'script' => $parts[1] ?? '',
                'region' => $parts[2] ?? '',
            ];
        }
        return $table;
    }

    /**
     * Add likely subtags to a locale tag.
     */
    private static function addLikelySubtags(string $tag): string
    {
        $parsed = self::parseLocaleTag($tag);
        if ($parsed === null || ($parsed['language'] ?? '') === '') {
            return $tag;
        }
        $lang = $parsed['language'];
        $script = $parsed['script'] ?? '';
        $region = $parsed['region'] ?? '';
        $table = self::likelySubtagsTable();
        // Spec UTS35 likelySubtags lookup order: try the most
        // specific (lang-script-region) first, then drop one
        // component at a time. The "und" fallback only fires when
        // no language-bearing key matched.
        // Build candidate keys all-lowercase so the table lookup is
        // case-insensitive irrespective of how the caller cased the
        // identifier.
        $low = static fn(string $s): string => strtolower($s);
        $candidates = [];
        if ($script !== '' && $region !== '') {
            $candidates[] = $low($lang . '-' . $script . '-' . $region);
        }
        if ($region !== '') {
            $candidates[] = $low($lang . '-' . $region);
        }
        if ($script !== '') {
            $candidates[] = $low($lang . '-' . $script);
        }
        $candidates[] = $low($lang);
        if ($script !== '' && $region !== '') {
            $candidates[] = $low('und-' . $script . '-' . $region);
        }
        if ($script !== '') {
            $candidates[] = $low('und-' . $script);
        }
        if ($region !== '') {
            $candidates[] = $low('und-' . $region);
        }
        // Only fall through to the 'und' bare key when the input
        // language is itself unknown; otherwise unrecognised
        // identifiers (like "posix") are returned unchanged.
        if ($lang === 'und') {
            $candidates[] = 'und';
        }
        foreach ($candidates as $candidate) {
            if (!isset($table[$candidate])) {
                continue;
            }
            $entry = $table[$candidate];
            if ($lang === 'und') {
                $parsed['language'] = $entry['language'];
            }
            if ($script === '') {
                $parsed['script'] = $entry['script'];
            }
            if ($region === '') {
                $parsed['region'] = $entry['region'];
            }
            return self::reconstructLocaleTag($parsed);
        }
        return $tag;
    }

    /**
     * Implements UTS35 RemoveLikelySubtags: maximize the input,
     * then try shorter forms (lang, lang+region, lang+script) and
     * return the first whose maximized language-script-region
     * triple equals the original maximum. Variants / extensions
     * are preserved on the result.
     */
    private static function removeLikelySubtags(string $tag): string
    {
        $maxTag = self::addLikelySubtags($tag);
        $maxParsed = self::parseLocaleTag($maxTag);
        if ($maxParsed === null || ($maxParsed['language'] ?? '') === '') {
            return $tag;
        }
        $lang = $maxParsed['language'];
        $script = $maxParsed['script'] ?? '';
        $region = $maxParsed['region'] ?? '';
        $maxTriple = self::languageScriptRegionTriple($maxParsed);

        $tries = [$lang];
        if ($region !== '') {
            $tries[] = $lang . '-' . $region;
        }
        if ($script !== '') {
            $tries[] = $lang . '-' . $script;
        }
        foreach ($tries as $candidate) {
            $candidateParsed = self::parseLocaleTag(self::addLikelySubtags($candidate));
            if (
                $candidateParsed !== null
                && self::languageScriptRegionTriple($candidateParsed) === $maxTriple
            ) {
                return self::reapplyTrailing($maxParsed, $candidate);
            }
        }
        return $maxTag;
    }

    /** Build a "language-script-region" comparison key. */
    /**
     * @param array<mixed> $parsed
     */
    private static function languageScriptRegionTriple(array $parsed): string
    {
        return ($parsed['language'] ?? '')
            . '|' . ($parsed['script'] ?? '')
            . '|' . ($parsed['region'] ?? '');
    }

    /**
     * Re-attach variants / extensions / private-use tags from the
     * maximized parse onto a shorter base tag string.
     *
     * @param array<mixed> $parsed
     */
    private static function reapplyTrailing(array $parsed, string $base): string
    {
        $baseParsed = self::parseLocaleTag($base);
        if ($baseParsed === null) {
            return $base;
        }
        foreach (
            ['variants', 'unicodeAttributes', 'unicodeKeywords',
            'otherExtensions', 'privateUse', 'calendar', 'collation',
            'firstDayOfWeek', 'hourCycle', 'caseFirst', 'numeric',
            'numberingSystem'] as $key
        ) {
            if (isset($parsed[$key])) {
                $baseParsed[$key] = $parsed[$key];
            }
        }
        return self::reconstructLocaleTag($baseParsed);
    }
}
