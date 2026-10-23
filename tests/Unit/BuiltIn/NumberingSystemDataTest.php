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
}
