<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/** Calendar arithmetic, interval rounding, and shared Intl calendar coverage. */
final class TemporalCalendarTest extends TestCase
{
    public function testNativeFieldsRoundTripAcrossCalendarExtremes(): void
    {
        self::assertTrue((new Engine())->eval(<<<'JS'
            const calendars = ['coptic', 'ethiopic', 'hebrew', 'indian', 'persian', 'islamic-civil'];
            for (const calendar of calendars) {
                for (const iso of ['-271821-04-19', '0000-01-01', '2024-03-30', '+275760-09-13']) {
                    const date = Temporal.PlainDate.from(iso).withCalendar(calendar);
                    const copy = Temporal.PlainDate.from({
                        calendar, year: date.year, monthCode: date.monthCode, day: date.day,
                    });
                    if (!date.equals(copy)) throw new Error(calendar + ': ' + iso);
                }
            }
            true
            JS));
    }

    public function testMonthDayReferenceDatesPreserveNativeMonthAndDay(): void
    {
        self::assertTrue((new Engine())->eval(<<<'JS'
            ['hebrew', 'chinese', 'coptic', 'indian', 'buddhist'].every(calendar => {
                const date = Temporal.PlainDate.from('2024-03-30').withCalendar(calendar);
                const monthDay = date.toPlainMonthDay();
                return monthDay.toPlainDate({ year: date.year }).equals(date);
            })
            JS));
    }

    public function testLargeDurationTimeFieldsUseTheirExactIntegerValues(): void
    {
        self::assertTrue((new Engine())->eval(<<<'JS'
            const milliseconds = 4503599627370497000;
            const microseconds = 4503599627370495000000;
            const nanos = BigInt(milliseconds) * 1000000n + BigInt(microseconds) * 1000n;
            const expected = new Temporal.Instant(nanos % 86400000000000n).toZonedDateTimeISO('UTC').toPlainTime();
            new Temporal.PlainTime().add({ milliseconds, microseconds }).equals(expected) &&
            new Temporal.PlainTime().subtract({ hours: 24 }).equals(new Temporal.PlainTime())
            JS));
    }

    public function testConstrainedCalendarIntervalsKeepExactTotalsAndTies(): void
    {
        self::assertTrue((new Engine())->eval(<<<'JS'
            const january = Temporal.PlainDate.from('2020-01-31');
            const duration = new Temporal.Duration(0, 1, 0, 0, 10);
            const rounded = duration.round({ smallestUnit: 'month', roundingMode: 'expand', relativeTo: january });
            if (rounded.months !== 2 || duration.total({ unit: 'month', relativeTo: january }) !== 1.0134408602150538) {
                throw new Error('constrained month interval');
            }
            const start = Temporal.PlainDate.from('2019-01-01');
            const end = Temporal.PlainDate.from('2020-07-02');
            start.until(end, { smallestUnit: 'year', roundingMode: 'halfTrunc' }).years === 1 &&
            start.since(end, { smallestUnit: 'year', roundingMode: 'halfEven' }).years === -2
            JS));
    }

    public function testTimeArithmeticPreservesTheLaterInstantInAFold(): void
    {
        self::assertTrue((new Engine())->eval(<<<'JS'
            const later = Temporal.ZonedDateTime.from('2025-11-02T01:00:00-08:00[America/Vancouver]');
            const earlier = Temporal.ZonedDateTime.from('2025-11-02T01:01:00-07:00[America/Vancouver]');
            later.add({ nanoseconds: 0 }).equals(later) &&
            later.until(earlier, { largestUnit: 'year' }).minutes === -59 &&
            Temporal.Duration.from({ hours: 2 }).total({ unit: 'day', relativeTo: later }) === 1 / 12
            JS));
    }

    public function testIntlAndTemporalUseTheSameCalendarFields(): void
    {
        self::assertTrue((new Engine())->eval(<<<'JS'
            const date = Temporal.PlainDate.from('2030-03-03').withCalendar('chinese');
            const formatter = new Intl.DateTimeFormat('en', {
                calendar: 'chinese', timeZone: 'UTC', year: 'numeric', month: 'numeric', day: 'numeric',
            });
            const instant = date.toZonedDateTime('UTC').epochMilliseconds;
            const parts = formatter.formatToParts(instant);
            +parts.find(p => p.type === 'day').value === date.day &&
            +parts.find(p => p.type === 'relatedYear').value === date.year &&
            parts.map(p => p.value).join('') === formatter.format(instant)
            JS));
    }
}
