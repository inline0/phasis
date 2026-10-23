<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Intl;

use Phasis\BuiltIn\TemporalObject;
use Phasis\Value\JsObject;

/** Render calendar fields with the same arithmetic used by Temporal. */
trait TemporalCalendarFormattingHelpers
{
    /** @return list<array{type: string, value: string}>|null */
    private static function canonicalCalendarParts(
        \IntlDateFormatter $formatter,
        \DateTimeImmutable $date,
        string $calendar,
        ?string $numberingSystem = null,
    ): ?array {
        if (in_array($calendar, ['gregory', 'iso8601', 'roc'], true)) {
            return null;
        }
        $zone = $formatter->getTimeZone();
        if ($zone !== false) {
            $date = $date->setTimezone($zone->toDateTimeZone());
        }
        $fields = TemporalObject::calendarFieldsForIntl(
            $calendar,
            (int) $date->format('Y'),
            (int) $date->format('n'),
            (int) $date->format('j'),
        );
        if ($fields === null) {
            return null;
        }
        $pattern = $formatter->getPattern();
        if ($pattern === false) {
            return null;
        }
        $locale = $formatter->getLocale(\Locale::VALID_LOCALE);
        if ($numberingSystem !== null) {
            $locale .= (str_contains($locale, '@') ? ';' : '@') . 'numbers=' . $numberingSystem;
        }
        $number = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $number->setAttribute(\NumberFormatter::GROUPING_USED, 0);
        $number->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 0);
        $parts = [];
        $length = strlen($pattern);
        for ($i = 0; $i < $length;) {
            $letter = $pattern[$i];
            if ($letter === "'") {
                if ($i + 1 < $length && $pattern[$i + 1] === "'") {
                    self::appendCanonicalCalendarPart($parts, 'literal', "'");
                    $i += 2;
                    continue;
                }
                $literal = '';
                $i++;
                while ($i < $length) {
                    if ($pattern[$i] === "'") {
                        if ($i + 1 < $length && $pattern[$i + 1] === "'") {
                            $literal .= "'";
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $literal .= $pattern[$i++];
                }
                self::appendCanonicalCalendarPart($parts, 'literal', $literal);
                continue;
            }
            if (!ctype_alpha($letter) || ord($letter) >= 128) {
                self::appendCanonicalCalendarPart($parts, 'literal', $letter);
                $i++;
                continue;
            }
            $end = $i + 1;
            while ($end < $length && $pattern[$end] === $letter) {
                $end++;
            }
            $token = substr($pattern, $i, $end - $i);
            $fieldFormatter = clone $formatter;
            $fieldFormatter->setPattern($token);
            $type = self::patternLetterToPartType($letter);
            if ($letter === 'y' && $calendar === 'japanese' && !in_array($fields['era'], ['ce', 'bce'], true)) {
                $rendered = $fieldFormatter->format($date);
            } elseif (in_array($letter, ['y', 'u', 'r', 'd'], true)) {
                $value = match ($letter) {
                    'd' => (int) $fields['day'],
                    'y' => (int) ($fields['eraYear'] ?? $fields['year']),
                    default => (int) $fields['year'],
                };
                $width = strlen($token) === 2 ? 2 : 1;
                if ($letter === 'y' && $width === 2) {
                    $value = abs($value) % 100;
                }
                $number->setAttribute(\NumberFormatter::MIN_INTEGER_DIGITS, $width);
                $rendered = $number->format($value, \NumberFormatter::TYPE_INT64);
            } elseif (in_array($letter, ['M', 'L', 'U'], true)) {
                $monthFields = $fields;
                if ($letter === 'U') {
                    $monthFields['monthCode'] = 'M01';
                }
                $surrogate = self::calendarMonthFormattingDate($calendar, $monthFields);
                $fieldFormatter->setTimeZone('UTC');
                $rendered = $surrogate === null ? $fieldFormatter->format($date) : $fieldFormatter->format($surrogate);
            } elseif ($letter === 'G') {
                $rendered = self::canonicalCalendarEra($fieldFormatter, $date, $calendar, $fields);
            } else {
                $rendered = $fieldFormatter->format($date);
            }
            if ($rendered !== false) {
                self::appendCanonicalCalendarPart($parts, $type, self::normalizeDateTimeSpaces($rendered));
            }
            $i = $end;
        }
        return $parts;
    }

    /** @param list<array{type: string, value: string}> $parts */
    private static function appendCanonicalCalendarPart(array &$parts, string $type, string $value): void
    {
        if ($value === '') {
            return;
        }
        $last = count($parts) - 1;
        if ($type === 'literal' && $last >= 0 && $parts[$last]['type'] === 'literal') {
            $parts[$last]['value'] .= $value;
        } else {
            $parts[] = ['type' => $type, 'value' => $value];
        }
    }

    /** @param array<string, bool|int|string|null> $fields */
    private static function calendarMonthFormattingDate(string $calendar, array $fields): ?float
    {
        $year = (int) $fields['year'];
        $code = (string) $fields['monthCode'];
        $month = (int) substr($code, 1, 2) - 1;
        $leap = str_ends_with($code, 'L');
        if ($calendar === 'hebrew' && ($month >= 5 || $leap)) {
            $month++;
        }
        $icuName = $calendar === 'ethioaa' ? 'ethiopic-amete-alem' : $calendar;
        $cal = \IntlCalendar::createInstance('UTC', 'en@calendar=' . $icuName);
        if ($cal === null) {
            return null;
        }
        $yearOffset = 0;
        if (in_array($calendar, ['chinese', 'dangi'], true)) {
            // ICU releases use different epochs for extended lunisolar
            // years. Relate the native extended year to its Gregorian
            // related year instead of assuming an epoch or ICU version.
            static $yearOffsets = [];
            if (!isset($yearOffsets[$calendar])) {
                $cal->setTime(0);
                $relatedYear = new \IntlDateFormatter(
                    'en@numbers=latn',
                    \IntlDateFormatter::NONE,
                    \IntlDateFormatter::NONE,
                    'UTC',
                    $cal,
                    'r',
                );
                $yearOffsets[$calendar] = $cal->get(\IntlCalendar::FIELD_EXTENDED_YEAR)
                    - (int) $relatedYear->format(0);
            }
            $yearOffset = $yearOffsets[$calendar];
        }
        $cal->clear();
        $cal->set(\IntlCalendar::FIELD_EXTENDED_YEAR, $year + $yearOffset);
        $cal->set(\IntlCalendar::FIELD_MONTH, $month);
        $cal->set(\IntlCalendar::FIELD_IS_LEAP_MONTH, $leap ? 1 : 0);
        $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $time = $cal->getTime();
        if ($leap && in_array($calendar, ['chinese', 'dangi'], true)) {
            // The localized leap-month name is independent of its year.
            // Find an ICU year containing that month when its astronomical
            // approximation differs from the canonical calendar data.
            for (
                $distance = 0; $distance < 200 && (
                $cal->get(\IntlCalendar::FIELD_MONTH) !== $month
                || $cal->get(\IntlCalendar::FIELD_IS_LEAP_MONTH) !== 1
                ); $distance++
            ) {
                $cal->clear();
                $cal->set(\IntlCalendar::FIELD_EXTENDED_YEAR, 1972 - $distance + $yearOffset);
                $cal->set(\IntlCalendar::FIELD_MONTH, $month);
                $cal->set(\IntlCalendar::FIELD_IS_LEAP_MONTH, 1);
                $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
                $time = $cal->getTime();
            }
        }
        return $time === false ? null : $time / 1000;
    }

    /** @param array<string, bool|int|string|null> $fields */
    private static function canonicalCalendarEra(
        \IntlDateFormatter $formatter,
        \DateTimeImmutable $date,
        string $calendar,
        array $fields,
    ): string|false {
        $era = $fields['era'];
        if ($era === null) {
            return '';
        }
        if ($era === 'bh') {
            // CLDR releases without the pre-Hijra era use its stable code.
            return 'BH';
        }
        if ($calendar === 'japanese' && in_array($era, ['ce', 'bce'], true)) {
            $gregorian = new \IntlGregorianCalendar($formatter->getTimeZone());
            $gregorian->setGregorianChange(-8.64e15);
            $formatter->setCalendar($gregorian);
            return $formatter->format($date);
        }
        if (in_array($calendar, ['coptic', 'ethiopic', 'ethioaa', 'buddhist', 'hebrew', 'indian', 'persian'], true)) {
            $eraCalendar = $calendar === 'ethiopic' && $era === 'aa' ? 'ethiopic-amete-alem' : $calendar;
            if ($eraCalendar === 'ethioaa') {
                $eraCalendar = 'ethiopic-amete-alem';
            }
            $cal = \IntlCalendar::createInstance('UTC', 'en@calendar=' . $eraCalendar);
            if ($cal !== null) {
                $cal->clear();
                $cal->set(\IntlCalendar::FIELD_EXTENDED_YEAR, 2000);
                $cal->set(\IntlCalendar::FIELD_MONTH, 0);
                $cal->set(\IntlCalendar::FIELD_DAY_OF_MONTH, 1);
                $formatter->setCalendar($cal);
                $formatter->setTimeZone('UTC');
                $rendered = $formatter->format($cal->getTime() / 1000);
                if ($rendered !== false) {
                    return self::mapEraToIcu4xCode($eraCalendar, $rendered);
                }
            }
        }
        return $formatter->format($date);
    }

    private static function formatCanonicalCalendarDate(
        \IntlDateFormatter $formatter,
        \DateTimeImmutable $date,
        string $calendar,
        ?string $numberingSystem = null,
    ): string|false {
        $parts = self::canonicalCalendarParts($formatter, $date, $calendar, $numberingSystem);
        return $parts === null ? $formatter->format($date) : implode('', array_column($parts, 'value'));
    }

    private static function calendarFormattingDateTime(?JsObject $temporal, float $timestampMs): \DateTimeImmutable
    {
        if ($temporal === null) {
            return self::dateTimeFromTimestampMs($timestampMs);
        }
        if ($temporal->has('[[EpochNanoseconds]]')) {
            return self::dateTimeFromTimestampMs(self::epochNsToMs(
                self::extractInternalString($temporal, '[[EpochNanoseconds]]', '0'),
            ));
        }
        [$year, $month, $day, $hour, $minute, $second, $millisecond] = self::temporalPlainComponents($temporal);
        $yearText = $year > 9999 ? '+' . $year : sprintf('%04d', $year);
        return new \DateTimeImmutable(sprintf(
            '%s-%02d-%02dT%02d:%02d:%02d.%06dZ',
            $yearText,
            $month,
            $day,
            $hour,
            $minute,
            $second,
            $millisecond * 1000,
        ));
    }
}
