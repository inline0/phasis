<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Date;

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
use Phasis\BuiltIn\SymbolConstructor;
use Phasis\BuiltIn\TemporalObject;

/**
 * Date trait part: DateAccessors. Composed into DateConstructor via
 * `use Date\DateAccessors;`. `self::`/`$this->` resolve into the
 * composing class so static-property + cross-trait calls work.
 */
trait DateAccessors
{
    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        // The dst-offset-caching harness drives these two methods through
        // the bytecode VM's CALL_METHOD fast path, so tag them with a
        // builtinKind that the VM can recognise without a string
        // comparison on the method name. Other methods are left
        // untagged.
        $tag = static function (string $n, \Closure $fn, int $len, ?string $kind = null) use ($proto): void {
            $jsFn = JsFunction::fromCallable($n, $fn, $len);
            if ($kind !== null) {
                $jsFn->builtinKind = $kind;
            }
            $proto->defineOwnProperty(
                $n,
                PropertyDescriptor::data($jsFn, true, false, true),
            );
        };
        $d = static fn (string $n, \Closure $fn, int $len = 0) => $tag($n, $fn, $len);
        $dKind = static fn (string $n, \Closure $fn, int $len, string $kind) => $tag($n, $fn, $len, $kind);

        // --- Getters (local time) ---

        $d('getTime', function (JsValue $this_): JsValue {
            return JsNumber::of(self::getTimeValue($this_));
        });

        $d('getFullYear', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) (int) self::localDateTime($tv)->format('Y'));
        });

        $d('getMonth', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            // JS months are 0-based.
            return JsNumber::of((float) ((int) self::localDateTime($tv)->format('n') - 1));
        });

        $d('getDate', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) (int) self::localDateTime($tv)->format('j'));
        });

        $d('getDay', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) (int) self::localDateTime($tv)->format('w'));
        });

        $d('getHours', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) (int) self::localDateTime($tv)->format('G'));
        });

        $d('getMinutes', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) (int) self::localDateTime($tv)->format('i'));
        });

        $d('getSeconds', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) (int) self::localDateTime($tv)->format('s'));
        });

        $d('getMilliseconds', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            $ms = (int) $tv % 1000;
            if ($ms < 0) {
                $ms += 1000;
            }
            return JsNumber::of((float) $ms);
        });

        $dKind('getTimezoneOffset', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            // Avoid the DateTimeImmutable + setTimezone + format('Z') dance:
            // a cached, lazily-expanded transition table answers offset
            // lookups in a single binary search, mirroring SpiderMonkey's
            // per-realm DST offset cache. Decisive for the SM
            // dst-offset-caching stress tests, which would otherwise call
            // this method ~2.6M times per fraction.
            $offsetSec = self::localOffsetSeconds($tv);
            // JS returns minutes with sign inverted.
            return JsNumber::of((float) (-$offsetSec / 60));
        }, 0, 'date.getTimezoneOffset');

        // --- Getters (UTC) ---

        $d('getUTCFullYear', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) (int) self::utcDateTime($tv)->format('Y'));
        });

        $d('getUTCMonth', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) ((int) self::utcDateTime($tv)->format('n') - 1));
        });

        $d('getUTCDate', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) (int) self::utcDateTime($tv)->format('j'));
        });

        $d('getUTCDay', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) (int) self::utcDateTime($tv)->format('w'));
        });

        $d('getUTCHours', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) (int) self::utcDateTime($tv)->format('G'));
        });

        $d('getUTCMinutes', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) (int) self::utcDateTime($tv)->format('i'));
        });

        $d('getUTCSeconds', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            return JsNumber::of((float) (int) self::utcDateTime($tv)->format('s'));
        });

        $d('getUTCMilliseconds', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            $ms = (int) $tv % 1000;
            if ($ms < 0) {
                $ms += 1000;
            }
            return JsNumber::of((float) $ms);
        });

        // --- Setters (local time) ---

        $dKind('setTime', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject || !self::isDateObject($this_)) {
                throw new TypeError('this is not a Date object');
            }
            // Hot path: numeric argument bypasses ToNumber. The SM DST
            // stress harness drives setTime millions of times with plain
            // numeric inputs.
            $arg0 = $args[0] ?? null;
            if ($arg0 instanceof JsNumber) {
                $tv = $arg0->value;
            } elseif ($arg0 === null) {
                $tv = NAN;
            } else {
                $tv = TypeConversion::toNumber($arg0);
            }
            $tv = self::timeClip($tv);
            self::setDateValue($this_, $tv);
            return JsNumber::of($tv);
        }, 1, 'date.setTime');

        $d('setMilliseconds', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'ms');
        }, 1);

        $d('setSeconds', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'sec');
        }, 2);

        $d('setMinutes', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'min');
        }, 3);

        $d('setHours', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'hour');
        }, 4);

        $d('setDate', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'date');
        }, 1);

        $d('setMonth', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'month');
        }, 2);

        $d('setFullYear', function (JsValue $this_, array $args): JsValue {
            return self::setterLocal($this_, $args, 'year');
        }, 3);

        // --- Setters (UTC) ---

        $d('setUTCMilliseconds', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'ms');
        }, 1);

        $d('setUTCSeconds', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'sec');
        }, 2);

        $d('setUTCMinutes', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'min');
        }, 3);

        $d('setUTCHours', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'hour');
        }, 4);

        $d('setUTCDate', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'date');
        }, 1);

        $d('setUTCMonth', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'month');
        }, 2);

        $d('setUTCFullYear', function (JsValue $this_, array $args): JsValue {
            return self::setterUtc($this_, $args, 'year');
        }, 3);

        // --- Conversion methods ---

        $d('toString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            return new JsString(self::toDateString($tv));
        });

        $d('toDateString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            return new JsString(self::toDateOnlyString($tv));
        });

        $d('toTimeString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            return new JsString(self::toTimeString($tv));
        });

        $d('toISOString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            return new JsString(self::toISOString($tv));
        });

        $d('toUTCString', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            return new JsString(self::toUTCString($tv));
        });

        // Annex B: toGMTString is an alias for toUTCString
        $proto->defineOwnProperty(
            'toGMTString',
            PropertyDescriptor::data($proto->get('toUTCString'), true, false, true),
        );

        // Annex B: getYear() returns year - 1900
        $d('getYear', function (JsValue $this_): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return JsNumber::of(NAN);
            }
            $local = self::localDateTime($tv);
            return JsNumber::of((float) ((int) $local->format('Y') - 1900));
        }, 0);

        // Annex B: setYear(year) sets the year (adds 1900 if 0-99)
        $d('setYear', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject || !self::isDateObject($this_)) {
                throw new TypeError('this is not a Date object');
            }
            $yearArg = $args[0] ?? JsUndefined::instance();
            $y = TypeConversion::toNumber($yearArg);
            if (is_nan($y)) {
                self::setDateValue($this_, NAN);
                return JsNumber::of(NAN);
            }
            $yi = (int) $y;
            if ($yi >= 0 && $yi <= 99) {
                $yi += 1900;
            }
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                $tv = 0.0;
            }
            $local = self::localDateTime($tv);
            $ms = (int) $tv % 1000;
            if ($ms < 0) {
                $ms += 1000;
            }
            $newDate = $local->setDate(
                $yi,
                (int) $local->format('n'),
                (int) $local->format('j'),
            );
            $newTv = self::timeClip((float) $newDate->getTimestamp() * 1000 + $ms);
            self::setDateValue($this_, $newTv);
            return JsNumber::of($newTv);
        }, 1);

        $d('toJSON', function (JsValue $this_): JsValue {
            // ES spec 21.4.4.24 Date.prototype.toJSON ( key )
            // 1. Let O be ? ToObject(this value).
            $o = TypeConversion::toObject($this_);

            // 2. Let tv be ? ToPrimitive(O, hint Number).
            $tv = TypeConversion::toPrimitive($o, 'number');

            // 3. If Type(tv) is Number and tv is not finite, return null.
            if ($tv instanceof JsNumber && !is_finite($tv->value)) {
                return JsNull::instance();
            }

            // 4. Return ? Invoke(O, "toISOString").
            $toISO = $o->get('toISOString');
            if (!$toISO instanceof JsFunction) {
                throw new TypeError('toISOString is not a function');
            }
            return $toISO->call($o, []);
        }, 1);

        // The toLocale* methods route locales/options through
        // Intl.DateTimeFormat so they share the same option-validation
        // behaviour: invalid locales (e.g. "de_DE") and invalid options
        // (e.g. {timeZone: "invalid"}) propagate the same RangeError /
        // TypeError as constructing the Intl.DateTimeFormat directly.
        $validateIntlOptions = static function (array $args): void {
            $localesArg = $args[0] ?? JsUndefined::instance();
            $optionsArg = $args[1] ?? JsUndefined::instance();
            $env = \Phasis\Engine::getCurrentInterpreter()?->getGlobalEnv();
            if ($env === null) {
                return;
            }
            $intlObj = $env->get('Intl', false);
            if (!$intlObj instanceof JsObject) {
                return;
            }
            $dtfCtor = $intlObj->get('DateTimeFormat');
            if (!$dtfCtor instanceof JsFunction) {
                return;
            }
            $newObj = new JsObject($dtfCtor->get('prototype') instanceof JsObject
                ? $dtfCtor->get('prototype')
                : null);
            $newObj->set('[[NewTarget]]', $dtfCtor);
            ($dtfCtor->getNativeCallable())($newObj, [$localesArg, $optionsArg]);
        };

        // Build an Intl.DateTimeFormat with sensible component defaults
        // (per ToDateTimeOptions), invoke its format getter, and return
        // the result. Returns null when Intl.DateTimeFormat isn't
        // available, in which case the caller falls back to a manual
        // formatting path.
        $tryFormatViaIntl = static function (
            JsValue $localesArg,
            JsValue $optionsArg,
            string $required,
            float $tv,
        ): ?JsString {
            $env = \Phasis\Engine::getCurrentInterpreter()?->getGlobalEnv();
            if ($env === null) {
                return null;
            }
            $intlObj = $env->get('Intl', false);
            if (!$intlObj instanceof JsObject) {
                return null;
            }
            $dtfCtor = $intlObj->get('DateTimeFormat');
            if (!$dtfCtor instanceof JsFunction) {
                return null;
            }
            // Apply ToDateTimeOptions defaults: when the user didn't
            // supply any explicit component / dateStyle / timeStyle,
            // populate the appropriate skeleton. Required determines
            // which set: "date" -> year/month/day, "time" -> hour/
            // minute/second, "all" -> both.
            $finalOptions = self::toDateTimeOptions($optionsArg, $required);
            $proto = $dtfCtor->get('prototype');
            $newObj = new JsObject($proto instanceof JsObject ? $proto : null);
            $newObj->set('[[NewTarget]]', $dtfCtor);
            ($dtfCtor->getNativeCallable())($newObj, [$localesArg, $finalOptions]);
            $interp = \Phasis\Engine::getCurrentInterpreter();
            $formatGetter = $proto instanceof JsObject
                ? $proto->getOwnPropertyDescriptor('format')
                : null;
            if (
                $formatGetter === null
                || !($formatGetter->get instanceof JsFunction)
                || $interp === null
            ) {
                return null;
            }
            $bound = $interp->callFunction($formatGetter->get, $newObj, []);
            if (!$bound instanceof JsFunction) {
                return null;
            }
            $formatted = $interp->callFunction(
                $bound,
                JsUndefined::instance(),
                [JsNumber::of($tv)],
            );
            return $formatted instanceof JsString ? $formatted : null;
        };

        $d('toLocaleDateString', function (JsValue $this_, array $args = []) use ($validateIntlOptions, $tryFormatViaIntl): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsString('Invalid Date');
            }
            $intl = $tryFormatViaIntl(
                $args[0] ?? JsUndefined::instance(),
                $args[1] ?? JsUndefined::instance(),
                'date',
                $tv,
            );
            if ($intl !== null) {
                return $intl;
            }
            $validateIntlOptions($args);
            $local = self::localDateTime($tv);
            return new JsString($local->format('n/j/Y'));
        });

        $d('toLocaleTimeString', function (JsValue $this_, array $args = []) use ($validateIntlOptions, $tryFormatViaIntl): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsString('Invalid Date');
            }
            $intl = $tryFormatViaIntl(
                $args[0] ?? JsUndefined::instance(),
                $args[1] ?? JsUndefined::instance(),
                'time',
                $tv,
            );
            if ($intl !== null) {
                return $intl;
            }
            $validateIntlOptions($args);
            $local = self::localDateTime($tv);
            $h = (int) $local->format('g');
            $min = $local->format('i');
            $sec = $local->format('s');
            $ampm = $local->format('A');
            return new JsString("{$h}:{$min}:{$sec} {$ampm}");
        });

        $d('toLocaleString', function (JsValue $this_, array $args = []) use ($validateIntlOptions, $tryFormatViaIntl): JsValue {
            $tv = self::getTimeValue($this_);
            if (is_nan($tv)) {
                return new JsString('Invalid Date');
            }
            $intl = $tryFormatViaIntl(
                $args[0] ?? JsUndefined::instance(),
                $args[1] ?? JsUndefined::instance(),
                'all',
                $tv,
            );
            if ($intl !== null) {
                return $intl;
            }
            $validateIntlOptions($args);
            $local = self::localDateTime($tv);
            $date = $local->format('n/j/Y');
            $h = (int) $local->format('g');
            $min = $local->format('i');
            $sec = $local->format('s');
            $ampm = $local->format('A');
            return new JsString("{$date}, {$h}:{$min}:{$sec} {$ampm}");
        });

        $d('valueOf', function (JsValue $this_): JsValue {
            return JsNumber::of(self::getTimeValue($this_));
        });

        // Symbol.toPrimitive for Date: implements ES spec 21.4.4.45.
        // The hint argument must be a string primitive with value "string", "number", or "default".
        // "default" maps to "string" for Date. Then OrdinaryToPrimitive is called.
        $toPrimSym = SymbolConstructor::toPrimitive();
        $toPrimFn = JsFunction::fromCallable('[Symbol.toPrimitive]', function (JsValue $this_, array $args): JsValue {
            // Step 1: Let O be the this value.
            // Step 2: If Type(O) is not Object, throw a TypeError.
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Date.prototype[Symbol.toPrimitive] requires an object');
            }
            // The hint must be a JS string primitive. Do not coerce.
            $hintVal = $args[0] ?? JsUndefined::instance();
            if (!$hintVal instanceof JsString) {
                throw new TypeError('Invalid hint');
            }
            $hint = $hintVal->value;
            // Step 3: If hint is "string" or "default", let tryFirst be "string".
            if ($hint === 'string' || $hint === 'default') {
                $tryFirst = 'string';
            } elseif ($hint === 'number') {
                // Step 4: If hint is "number", let tryFirst be "number".
                $tryFirst = 'number';
            } else {
                // Step 5: Else, throw a TypeError.
                throw new TypeError('Invalid hint');
            }
            // Step 6: Return OrdinaryToPrimitive(O, tryFirst).
            $methodNames = $tryFirst === 'string'
                ? ['toString', 'valueOf']
                : ['valueOf', 'toString'];
            foreach ($methodNames as $methodName) {
                $method = $this_->get($methodName);
                if ($method instanceof JsFunction) {
                    $result = $method->call($this_, []);
                    if (!$result instanceof JsObject) {
                        return $result;
                    }
                }
            }
            throw new TypeError('Cannot convert object to primitive value');
        }, 1);
        $proto->definePropertyBySymbol($toPrimSym, PropertyDescriptor::data($toPrimFn, false, false, true));

        // Date.prototype.toTemporalInstant() per Temporal proposal.
        $proto->defineOwnProperty('toTemporalInstant', PropertyDescriptor::data(
            JsFunction::fromCallable('toTemporalInstant', function (JsValue $this_): JsValue {
                if (!$this_ instanceof JsObject || !self::isDateObject($this_)) {
                    throw new TypeError('this is not a Date object');
                }
                $tv = self::dateValueOf($this_);
                if (is_nan($tv)) {
                    throw new \Phasis\Exceptions\RangeError('Invalid time value');
                }
                $ms = (string) (int) $tv;
                $ns = bcmul($ms, '1000000');
                return TemporalObject::createInstantFromNs($ns);
            }, 0),
            true,
            false,
            true,
        ));

        // Per spec: Date.prototype does NOT have Symbol.toStringTag; the
        // "[object Date]" tag comes from the [[DateValue]]/[[IsDate]]
        // internal slot check in Object.prototype.toString (§20.1.3.6
        // step 15). Do not set @@toStringTag here — doing so would incorrectly
        // surface "Date" when the receiver is a Proxy wrapping a Date.
        return $proto;
    }

    /**
     * Generic setter for local-time-based set methods.
     *
     * Per the ES spec, the order of operations is:
     * 1. Get thisTimeValue
     * 2. Call ToNumber on all present arguments
     * 3. THEN check if time value is NaN (return NaN if so, except for year)
     *
     * @param list<JsValue> $args
     */
    private static function setterLocal(JsValue $this_, array $args, string $field): JsValue
    {
        if (!$this_ instanceof JsObject || !self::isDateObject($this_)) {
            throw new TypeError('this is not a Date object');
        }

        $tv = self::getTimeValue($this_);

        // Per spec: the first argument is always required (ToNumber(undefined) = NaN).
        // Coerce all present arguments to numbers BEFORE the NaN check.
        // If the first arg is missing, treat as ToNumber(undefined) = NaN.
        $coerced = [];
        if (empty($args)) {
            $coerced[] = NAN;
        } else {
            foreach ($args as $arg) {
                $coerced[] = TypeConversion::toNumber($arg);
            }
        }

        // If any coerced argument is NaN, the result is NaN
        foreach ($coerced as $c) {
            if (is_nan($c)) {
                self::setDateValue($this_, NAN);
                return JsNumber::of(NAN);
            }
        }

        // Per spec, "return NaN" without setting [[DateValue]] so that any
        // side effects from ToNumber (e.g. calling setTime) are preserved.
        if (is_nan($tv) && $field !== 'year') {
            return JsNumber::of(NAN);
        }

        // For setFullYear on an invalid date, start from epoch.
        if (is_nan($tv) && $field === 'year') {
            $tv = 0.0;
        }

        $local = self::localDateTime($tv);
        $ms = (int) $tv % 1000;
        if ($ms < 0) {
            $ms += 1000;
        }
        $y = (int) $local->format('Y');
        $m = (int) $local->format('n') - 1; // 0-based
        $dt = (int) $local->format('j');
        $h = (int) $local->format('G');
        $min = (int) $local->format('i');
        $sec = (int) $local->format('s');

        $idx = 0;
        $getArg = static function () use ($coerced, &$idx): ?float {
            if (!isset($coerced[$idx])) {
                return null;
            }
            return $coerced[$idx++];
        };

        switch ($field) {
            case 'ms':
                $ms = (int) ($getArg() ?? $ms);
                break;
            case 'sec':
                $sec = (int) ($getArg() ?? $sec);
                $val = $getArg();
                if ($val !== null) {
                    $ms = (int) $val;
                }
                break;
            case 'min':
                $min = (int) ($getArg() ?? $min);
                $val = $getArg();
                if ($val !== null) {
                    $sec = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $ms = (int) $val;
                }
                break;
            case 'hour':
                $h = (int) ($getArg() ?? $h);
                $val = $getArg();
                if ($val !== null) {
                    $min = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $sec = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $ms = (int) $val;
                }
                break;
            case 'date':
                $dt = (int) ($getArg() ?? $dt);
                break;
            case 'month':
                $m = (int) ($getArg() ?? $m);
                $val = $getArg();
                if ($val !== null) {
                    $dt = (int) $val;
                }
                break;
            case 'year':
                $y = (int) ($getArg() ?? $y);
                $val = $getArg();
                if ($val !== null) {
                    $m = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $dt = (int) $val;
                }
                break;
        }

        // Reconstruct using DateTimeImmutable (local time).
        // We avoid mktime because it misinterprets years 0-99 (adds 1900 or 2000).
        $ts = self::composeLocalTimestamp($y, $m, $dt, $h, $min, $sec);
        if ($ts === null) {
            self::setDateValue($this_, NAN);
            return JsNumber::of(NAN);
        }
        $newTv = self::timeClip((float) $ts * 1000.0 + (float) $ms);
        self::setDateValue($this_, $newTv);
        return JsNumber::of($newTv);
    }

    /**
     * Generic setter for UTC-based set methods.
     *
     * Per the ES spec, the order of operations is:
     * 1. Get thisTimeValue
     * 2. Call ToNumber on all present arguments
     * 3. THEN check if time value is NaN (return NaN if so, except for year)
     *
     * @param list<JsValue> $args
     */
    private static function setterUtc(JsValue $this_, array $args, string $field): JsValue
    {
        if (!$this_ instanceof JsObject || !self::isDateObject($this_)) {
            throw new TypeError('this is not a Date object');
        }

        $tv = self::getTimeValue($this_);

        // Coerce all present arguments to numbers BEFORE the NaN check.
        // This is required by the spec: ToNumber calls happen before the NaN guard.
        $coerced = [];
        foreach ($args as $arg) {
            $coerced[] = TypeConversion::toNumber($arg);
        }

        // Now check if the original time value was NaN (after coercing args).
        // Per spec, "return NaN" without setting [[DateValue]] so that any
        // side effects from ToNumber (e.g. calling setTime) are preserved.
        if (is_nan($tv) && $field !== 'year') {
            return JsNumber::of(NAN);
        }

        if (is_nan($tv) && $field === 'year') {
            $tv = 0.0;
        }

        $utc = self::utcDateTime($tv);
        $ms = (int) $tv % 1000;
        if ($ms < 0) {
            $ms += 1000;
        }
        $y = (int) $utc->format('Y');
        $m = (int) $utc->format('n') - 1;
        $dt = (int) $utc->format('j');
        $h = (int) $utc->format('G');
        $min = (int) $utc->format('i');
        $sec = (int) $utc->format('s');

        $idx = 0;
        $getArg = static function () use ($coerced, &$idx): ?float {
            if (!isset($coerced[$idx])) {
                return null;
            }
            return $coerced[$idx++];
        };

        switch ($field) {
            case 'ms':
                $ms = (int) ($getArg() ?? $ms);
                break;
            case 'sec':
                $sec = (int) ($getArg() ?? $sec);
                $val = $getArg();
                if ($val !== null) {
                    $ms = (int) $val;
                }
                break;
            case 'min':
                $min = (int) ($getArg() ?? $min);
                $val = $getArg();
                if ($val !== null) {
                    $sec = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $ms = (int) $val;
                }
                break;
            case 'hour':
                $h = (int) ($getArg() ?? $h);
                $val = $getArg();
                if ($val !== null) {
                    $min = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $sec = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $ms = (int) $val;
                }
                break;
            case 'date':
                $dt = (int) ($getArg() ?? $dt);
                break;
            case 'month':
                $m = (int) ($getArg() ?? $m);
                $val = $getArg();
                if ($val !== null) {
                    $dt = (int) $val;
                }
                break;
            case 'year':
                $y = (int) ($getArg() ?? $y);
                $val = $getArg();
                if ($val !== null) {
                    $m = (int) $val;
                }
                $val = $getArg();
                if ($val !== null) {
                    $dt = (int) $val;
                }
                break;
        }

        // Per spec MakeDay: ym = year + floor(month/12). If the intermediate
        // sum overflows to ±Infinity even when the inputs are finite (e.g.
        // year and month both Number.MAX_VALUE), the result is NaN per
        // tc39/ecma262#1087. Run the check on the original coerced float
        // values, before they are truncated to PHP int.
        if ($field === 'year' || $field === 'month') {
            $rawYear = (float) ($field === 'year' ? ($coerced[0] ?? $y) : $y);
            $monthIdx = $field === 'year' ? 1 : 0;
            $rawMonth = (float) ($coerced[$monthIdx] ?? $m);
            if (!is_finite($rawYear + floor($rawMonth / 12.0))) {
                self::setDateValue($this_, NAN);
                return JsNumber::of(NAN);
            }
        }
        // Reconstruct using DateTimeImmutable (UTC).
        // We avoid gmmktime because it misinterprets years 0-99 (adds 1900 or 2000).
        $ts = self::composeUtcTimestamp($y, $m, $dt, $h, $min, $sec);
        if ($ts === null) {
            self::setDateValue($this_, NAN);
            return JsNumber::of(NAN);
        }
        $newTv = self::timeClip((float) $ts * 1000.0 + (float) $ms);
        self::setDateValue($this_, $newTv);
        return JsNumber::of($newTv);
    }
}
