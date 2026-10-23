<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class LocaleInfoTest extends TestCase
{
    public function testVariantOverridesCanonicalizeAndPreserveExtensions(): void
    {
        $engine = new Engine();
        self::assertSame('en-1996-fonipa-u-ca-gregory|1996-fonipa', $engine->eval(<<<'JS'
var locale = new Intl.Locale('en-oxendict-u-ca-gregory', { variants: 'fonipa-1996' });
locale.toString() + '|' + locale.variants;
JS));
    }

    public function testLocalePreferencesUseRegionOverridesAndReturnFreshArrays(): void
    {
        $engine = new Engine();
        self::assertTrue($engine->eval(<<<'JS'
var locale = new Intl.Locale('en-US-u-rg-thzzzz');
var calendars = locale.getCalendars();
var expected = new Intl.Locale('en-TH').getCalendars();
var same = calendars.join() === expected.join();
calendars.length = 0;
same && locale.getCalendars().length > 0 &&
    new Intl.Locale('en-US').getWeekInfo().firstDay === 7 &&
    Object.keys(locale.getWeekInfo()).join() === 'firstDay,weekend';
JS));
    }

    public function testScriptDirectionAndUnavailableLocaleCollationsAreIndependentOfHostLocale(): void
    {
        $engine = new Engine();
        self::assertSame('rtl|ltr|undefined|emoji,eor', $engine->eval(<<<'JS'
[
    new Intl.Locale('en-Arab').getTextInfo().direction,
    new Intl.Locale('ar-Latn').getTextInfo().direction,
    new Intl.Locale('en-Zzzz').getTextInfo().direction,
    new Intl.Locale('qfz').getCollations().join(),
].map(String).join('|');
JS));
    }
}
