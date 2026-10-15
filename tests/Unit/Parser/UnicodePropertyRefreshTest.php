<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Parser;

use Phasis\Engine;
use Phasis\Regex\UnicodeTables;
use PHPUnit\Framework\TestCase;

final class UnicodePropertyRefreshTest extends TestCase
{
    public function testUnicode17PropertiesWorkWithoutNewHostIcu(): void
    {
        $engine = new Engine();
        self::assertSame('17.0.0', UnicodeTables::VERSION);
        self::assertTrue($engine->eval(<<<'JS'
            /\p{sc=Beria_Erfe}/u.test('\u{16EA0}') &&
            /\p{Script_Extensions=Beria_Erfe}/v.test('\u{16ED3}') &&
            /\p{Script=Tangut}/u.test('\u{187F8}') &&
            /\p{sc=Telugu}/u.test('\u{C5C}') &&
            /\p{Letter}/u.test('\u{16EA0}') &&
            !/\p{sc=Beria_Erfe}/u.test('A') &&
            /\p{Script_Extensions=Unknown}/u.test('\uDC00')
            JS));
    }
}
