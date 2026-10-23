<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/** Calendar arithmetic, interval rounding, and shared Intl calendar coverage. */
final class TemporalCalendarTest extends TestCase
{
    public function testPlainDateTimeBoundsApplyToPropertyBagsAndNanosecondEdges(): void
    {
        self::assertSame('[true,true,true,true,true,true]', (new Engine())->eval(<<<'JS'
            const results = [];
            for (const overflow of ['constrain', 'reject']) {
                for (const fields of [
                    {year: -271821, month: 4, day: 19},
                    {year: 275760, month: 9, day: 14},
                ]) {
                    try { Temporal.PlainDateTime.from(fields, {overflow}); results.push(false); }
                    catch (error) { results.push(error instanceof RangeError); }
                }
            }
            const min = Temporal.PlainDateTime.from({year: -271821, month: 4, day: 19, nanosecond: 1});
            const max = Temporal.PlainDateTime.from({
                year: 275760, month: 9, day: 13, hour: 23, minute: 59, second: 59,
                millisecond: 999, microsecond: 999, nanosecond: 999,
            });
            results.push(min.equals('-271821-04-19T00:00:00.000000001'));
            results.push(max.equals('+275760-09-13T23:59:59.999999999'));
            JSON.stringify(results);
            JS));
    }

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

    public function testDateLineGapDisambiguationAndCalendarArithmetic(): void
    {
        self::assertSame('[29,31,31,true,31,23,31,22,29,22]', (new Engine())->eval(<<<'JS'
            const gap = new Temporal.PlainDateTime(2011, 12, 30, 22);
            const zone = 'Pacific/Apia';
            const earlier = gap.toZonedDateTime(zone, {disambiguation: 'earlier'});
            const later = gap.toZonedDateTime(zone, {disambiguation: 'later'});
            const compatible = gap.toZonedDateTime(zone);
            let rejected = false;
            try { gap.toZonedDateTime(zone, {disambiguation: 'reject'}); }
            catch (error) { rejected = error instanceof RangeError; }
            const before = new Temporal.PlainDateTime(2011, 12, 29, 22).toZonedDateTime(zone);
            const after = new Temporal.PlainDateTime(2011, 12, 31, 23).toZonedDateTime(zone);
            const added = before.add({days: 1, hours: 1});
            const subtracted = after.subtract({days: 1, hours: 1});
            const twoDays = after.subtract({days: 2, hours: 1});
            JSON.stringify([
                earlier.day, later.day, compatible.day, rejected,
                added.day, added.hour, subtracted.day, subtracted.hour, twoDays.day, twoDays.hour,
            ]);
            JS));
    }

    public function testChineseCyclicalYearsUseTheRelatedGregorianYear(): void
    {
        self::assertTrue((new Engine())->eval(<<<'JS'
            let matches = true;
            for (const calendar of ['chinese', 'dangi']) {
                const formatter = new Intl.DateTimeFormat('zh', {
                    calendar, year: 'numeric', timeZone: 'UTC',
                });
                for (const [year, cycle] of [[2019, '己亥'], [2020, '庚子'], [2022, '壬寅']]) {
                    const date = new Date(Date.UTC(year, 5, 1));
                    const parts = formatter.formatToParts(date);
                    if (parts.find(part => part.type === 'yearName').value !== cycle) matches = false;
                    if (formatter.format(date) !== parts.map(part => part.value).join('')) matches = false;
                }
            }
            matches;
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
