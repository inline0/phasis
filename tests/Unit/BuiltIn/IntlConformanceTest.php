<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class IntlConformanceTest extends TestCase
{
    public function testSupportedOptionsOverrideExtensionsOnlyWhenTheirValuesDiffer(): void
    {
        $engine = new Engine();
        self::assertSame('["en-u-nu-arab","arab","en","latn","de-u-co-phonebk","phonebk"]', $engine->eval(<<<'JS'
            const retained = new Intl.NumberFormat('en-u-nu-arab', {numberingSystem: 'invalid'}).resolvedOptions();
            const changed = new Intl.NumberFormat('en-u-nu-arab', {numberingSystem: 'latn'}).resolvedOptions();
            const collation = new Intl.Collator('de-u-co-phonebk', {collation: 'pinyin'}).resolvedOptions();
            JSON.stringify([retained.locale, retained.numberingSystem, changed.locale,
                changed.numberingSystem, collation.locale, collation.collation]);
            JS));
    }

    public function testLegacyIntlConstructorSharesTheRealmSymbolAndUnwrapsThroughProxies(): void
    {
        $engine = new Engine();
        self::assertSame('[true,true,true,"IntlLegacyConstructedSymbol",false,false,false]', $engine->eval(<<<'JS'
            const number = Object.create(Intl.NumberFormat.prototype);
            const date = Object.create(Intl.DateTimeFormat.prototype);
            const same = Intl.NumberFormat.call(number, 'en') === number;
            Intl.DateTimeFormat.call(date, 'en');
            const symbol = Object.getOwnPropertySymbols(number)[0];
            const descriptor = Object.getOwnPropertyDescriptor(number, symbol);
            const proxy = new Proxy(number, {});
            JSON.stringify([same, proxy.resolvedOptions().locale === 'en',
                symbol === Object.getOwnPropertySymbols(date)[0], symbol.description,
                descriptor.writable, descriptor.enumerable, descriptor.configurable]);
            JS));
    }

    public function testPlainTemporalFormattingKeepsWallTimeDefaultsAndExpandedYears(): void
    {
        $engine = new Engine();
        self::assertSame('[true,true,true,true]', $engine->eval(<<<'JS'
            const wall = new Temporal.PlainDateTime(2026, 3, 8, 2, 30);
            const local = wall.toLocaleString('en', {timeZone: 'America/Los_Angeles'});
            const utc = wall.toLocaleString('en', {timeZone: 'UTC'});
            const maximum = new Intl.DateTimeFormat('en').format(new Temporal.PlainDate(275760, 9, 13));
            const midnight = new Temporal.PlainTime().toLocaleString('en', {hour12: false});
            const era = new Intl.DateTimeFormat('en', {era: 'narrow'}).format(new Temporal.PlainDate(2025, 11, 4));
            JSON.stringify([local === utc, maximum.includes('275760'), midnight.includes('00:00:00'), era.startsWith('11')]);
            JS));
    }

    public function testPluralNotationAndLocaleCaseValidation(): void
    {
        $engine = new Engine();
        self::assertSame('["other","many",true,true]', $engine->eval(<<<'JS'
            const normal = new Intl.PluralRules('fr').select(1500000);
            const compact = new Intl.PluralRules('fr', {notation: 'compact'}).select(1500000);
            let rejected = false;
            try { ''.toLocaleLowerCase(['en', 'invalid language tag']); }
            catch (error) { rejected = error instanceof RangeError; }
            const options = new Intl.PluralRules('en').resolvedOptions();
            JSON.stringify([normal, compact, rejected, !Object.hasOwn(options, 'compactDisplay')]);
            JS));
    }
}
