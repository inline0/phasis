<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\BuiltIn\Intl\NumberingSystemData;
use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class NumberingSystemDataTest extends TestCase
{
    public function testDigitSubstitutionPreservesPunctuationAndUnitLabels(): void
    {
        self::assertSame('−๑๒,๓๔๕.๖ m/s', NumberingSystemData::substitute('−12,345.6 m/s', 'thai'));
        self::assertSame('12٬34', NumberingSystemData::substitute('١٢٬٣٤', 'latn'));
    }

    public function testNumberingSystemsBeyondTheHostIcuVersionRemainAvailable(): void
    {
        $engine = new Engine();
        self::assertTrue($engine->eval(<<<'JS'
var system = 'gara';
var formatter = new Intl.NumberFormat('en', { numberingSystem: system, useGrouping: false });
Intl.supportedValuesOf('numberingSystem').includes(system) &&
    formatter.format(123) === '\u{10D41}\u{10D42}\u{10D43}' &&
    formatter.resolvedOptions().numberingSystem === system;
JS));
    }

    public function testUnsupportedHostDigitsRetainLocalePunctuationBeforeSubstitution(): void
    {
        $formatter = NumberingSystemData::createFormatter('de_u_nu_notreal', \NumberFormatter::DECIMAL);
        self::assertSame('1.234,5', $formatter->format(1234.5));
        self::assertSame('๑.๒๓๔,๕', NumberingSystemData::substitute($formatter->format(1234.5), 'thai'));
    }
}
