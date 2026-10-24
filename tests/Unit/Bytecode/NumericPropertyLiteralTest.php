<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumericPropertyLiteralTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function literals(): iterable
    {
        foreach (
            ['0', '-0', '0.30000000000000004', '1.0000000000000002', '0.9999999999999999',
            '5e-324', '1.7976931348623157e308', '9007199254740991', '9007199254740992',
            '9223372036854775808', '9223372036854777856', '-9223372036854777856',
            'NaN', 'Infinity', '-Infinity'] as $literal
        ) {
            yield 'object-' . $literal => [$literal, false];
            yield 'nested-array-' . $literal => [$literal, true];
        }
    }

    #[DataProvider('literals')]
    public function testCompiledPropertiesPreserveOriginalNumberBits(string $literal, bool $array): void
    {
        $engine = new Engine();
        $source = $array
            ? 'var saved; Object.prototype.captureLiteral = function () { saved = this.items[0]; };'
                . 'function literal() { const object = {items: [' . $literal . ']}; object.captureLiteral(); return 0; }'
                . 'literal(); Object.is(saved, (' . $literal . '));'
            : 'function literal() { const object = {value: ' . $literal . '}; return object.value; }'
                . 'Object.is(literal(), (' . $literal . '));';
        self::assertTrue($engine->eval($source), $literal);
        $function = $engine->getGlobalEnv()->get('literal');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertInstanceOf(\Closure::class, $function->phpCompiled);
    }

    public function testPropertyLiteralsIgnorePhpDisplayPrecision(): void
    {
        $before = ini_get('precision');
        ini_set('precision', '3');
        try {
            $this->testCompiledPropertiesPreserveOriginalNumberBits('0.30000000000000004', false);
            $this->testCompiledPropertiesPreserveOriginalNumberBits('0.30000000000000004', true);
        } finally {
            ini_set('precision', $before);
        }
    }
}
