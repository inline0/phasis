<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class TemporalEraRefreshTest extends TestCase
{
    public function testCurrentCalendarEraIdentifiersAndYears(): void
    {
        if (!extension_loaded('intl')) {
            self::markTestSkipped('Non-ISO calendar conversion requires Intl.');
        }
        self::assertTrue((new Engine())->eval(<<<'JS'
            const calendars = {
                buddhist: 'be', coptic: 'am', ethiopic: 'am', ethioaa: 'aa',
                gregory: 'ce', hebrew: 'am', indian: 'shaka', persian: 'ap', roc: 'roc',
                'islamic-civil': 'ah', 'islamic-tbla': 'ah', 'islamic-umalqura': 'ah',
            };
            Object.entries(calendars).every(([calendar, era]) => {
                const value = new Temporal.PlainDate(2000, 1, 1, calendar);
                return value.era === era && typeof value.eraYear === 'number';
            }) &&
            new Temporal.PlainDate(0, 1, 1, 'gregory').era === 'bce' &&
            new Temporal.PlainDate(0, 1, 1, 'gregory').eraYear === 1 &&
            new Temporal.PlainDate(1911, 1, 1, 'roc').era === 'broc' &&
            new Temporal.PlainDate(1911, 1, 1, 'roc').eraYear === 1 &&
            new Temporal.PlainDate(1867, 1, 1, 'japanese').era === 'ce' &&
            new Temporal.PlainDate(2000, 1, 1, 'japanese').era === 'heisei' &&
            new Temporal.PlainDate(2000, 1, 1, 'japanese').eraYear === 12 &&
            new Temporal.PlainDate(2000, 1, 1).era === undefined &&
            new Temporal.PlainDate(2000, 1, 1).eraYear === undefined
            JS));
    }

    public function testEraPropertyBagsUseCalendarArithmeticYears(): void
    {
        self::assertTrue((new Engine())->eval(<<<'JS'
            const calendars = ['buddhist', 'coptic', 'ethiopic', 'ethioaa', 'gregory',
                'hebrew', 'indian', 'persian', 'roc', 'japanese',
                'islamic-civil', 'islamic-tbla', 'islamic-umalqura'];
            for (const calendar of calendars) {
                const original = new Temporal.PlainDate(2000, 1, 1, calendar);
                const fields = { calendar, era: original.era, eraYear: original.eraYear,
                    monthCode: original.monthCode, day: original.day };
                for (const Type of [Temporal.PlainDate, Temporal.PlainDateTime, Temporal.PlainYearMonth]) {
                    for (const options of [undefined, { overflow: 'reject' }]) {
                        const value = Type.from(fields, options);
                        if (value.year !== original.year || value.monthCode !== original.monthCode) {
                            throw new Error(calendar + ': era property bag did not round-trip');
                        }
                        const withYear = Type.from({ ...fields, year: original.year }, options);
                        if (withYear.year !== original.year) throw new Error('explicit year');
                        for (const bad of [{ ...fields, year: original.year + 1 },
                            { ...fields, era: 'invalid' }, { ...fields, era: original.era.toUpperCase() }]) {
                            let rejected = false;
                            try { Type.from(bad, options); } catch (e) { rejected = e instanceof RangeError; }
                            if (!rejected) throw new Error(calendar + ': invalid fields accepted');
                        }
                        for (const missing of [{ ...fields, era: undefined }, { ...fields, eraYear: undefined }]) {
                            let rejected = false;
                            try { Type.from({ ...missing, year: original.year }, options); }
                            catch (e) { rejected = e instanceof TypeError; }
                            if (!rejected) throw new Error(calendar + ': incomplete era pair accepted');
                        }
                    }
                }
            }
            const examples = [
                ['gregory', 'ad', 2000, 2000], ['gregory', 'bc', 2, -1],
                ['japanese', 'reiwa', 0, 2018], ['japanese', 'ad', 1000, 1000],
                ['roc', 'broc', 1, 0], ['ethiopic', 'aa', 5500, 0],
            ];
            for (const [calendar, era, eraYear, year] of examples) {
                for (const Type of [Temporal.PlainDate, Temporal.PlainDateTime, Temporal.PlainYearMonth]) {
                    const value = Type.from({ calendar, era, eraYear, month: 1, day: 1 });
                    if (value.year !== year) throw new Error(calendar + ': wrong era arithmetic');
                }
            }
            true
            JS));
    }
}
