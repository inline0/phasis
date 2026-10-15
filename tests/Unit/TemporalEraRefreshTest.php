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
}
