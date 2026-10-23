<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/** Temporal grammar, coercion, and observable option-reading regressions. */
class TemporalParsingTest extends TestCase
{
    public function testCompactAndExtendedDatesUseConsistentSeparators(): void
    {
        $engine = new Engine();
        self::assertSame('2020-01-02,2020-01-02,RangeError,RangeError', $engine->eval(<<<'JS'
['20200102T123456.123456789+0130', '2020-01-02T12:34:56.123456789+01:30',
 '2020-0102', '202001-02'].map(value => {
    try { return Temporal.PlainDate.from(value).toString(); }
    catch (error) { return error.name; }
}).join(',')
JS));
    }

    public function testFractionsAndOffsetsAreValidatedBeforeIgnoredFieldsAreDiscarded(): void
    {
        $engine = new Engine();
        self::assertSame('RangeError,RangeError,RangeError,RangeError', $engine->eval(<<<'JS'
['2020-01-02T12:34:56.1234567890', '2020-01-02T12:3456',
 '2020-01-02T12:34+24:00', '2020-01-02T12:34+01:60'].map(value => {
    try { Temporal.PlainDate.from(value); return 'accepted'; }
    catch (error) { return error.name; }
}).join(',')
JS));
    }

    public function testInstantStringOptionsAreReadBeforeUnitGroupValidation(): void
    {
        $engine = new Engine();
        self::assertSame('fractionalSecondDigits,roundingMode,smallestUnit,timeZone:RangeError', $engine->eval(<<<'JS'
(() => {
    const log = [];
    const options = {};
    for (const [key, value] of [
        ['fractionalSecondDigits', 'auto'], ['roundingMode', 'trunc'],
        ['smallestUnit', 'hour'], ['timeZone', 'UTC']
    ]) {
        Object.defineProperty(options, key, { get() { log.push(key); return value; } });
    }
    try { new Temporal.Instant(0n).toString(options); }
    catch (error) { return log.join(',') + ':' + error.name; }
})()
JS));
    }

    public function testEpochConstructorsUseBigIntCoercion(): void
    {
        $engine = new Engine();
        self::assertSame('1,42,0,42', $engine->eval(<<<'JS'
[
    new Temporal.Instant(true).epochNanoseconds,
    new Temporal.Instant({ valueOf() { return '42'; } }).epochNanoseconds,
    new Temporal.ZonedDateTime(false, 'UTC').epochNanoseconds,
    new Temporal.ZonedDateTime({ valueOf() { return '42'; } }, 'UTC').epochNanoseconds
].join(',')
JS));
    }

    public function testCalendarStringsValidateAnnotationsAndNormalizeYearMonthReferenceDay(): void
    {
        $engine = new Engine();
        self::assertSame('gregory,2024-10-01[u-ca=gregory],RangeError,RangeError', $engine->eval(<<<'JS'
(() => {
    const results = [
        new Temporal.PlainDate(2024, 10, 10).withCalendar('12:34[u-ca=gregory]').calendarId,
        Temporal.PlainYearMonth.from('2024-10-10[u-ca=gregory]').toString()
    ];
    try { Temporal.ZonedDateTime.from('2024-10-10T00:00Z[UTC][u-ca=unknown]'); }
    catch (error) { results.push(error.name); }
    try { Temporal.PlainDate.from({ year: 2024, month: 10, day: 10, calendar: '-000000-01-01' }); }
    catch (error) { results.push(error.name); }
    return results.join(',');
})()
JS));
    }
}
