<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\Op;
use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumericArrayIndexTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function keys(): iterable
    {
        foreach (
            ['NaN', 'Infinity', '-Infinity', '1e100', '-1e100',
            '9223372036854775808', '-9223372036854775808', '4294967295', '0.5', '-1'] as $key
        ) {
            yield $key => [$key, 0];
        }
        yield 'zero' => ['0', 1];
        yield 'negative-zero' => ['-0', 1];
        yield 'one' => ['1', 2];
    }

    #[DataProvider('keys')]
    public function testVmNumericKeysRespectArrayIndexBoundsWithoutPhpWarnings(string $key, int $length): void
    {
        $engine = new Engine();
        self::assertSame('[7,' . $length . ',true,1]', $engine->eval(<<<'JS'
function access(array, key, value) { array[key] = value; return array[key]; }
JS
            . 'var array = [], key = ' . $key . '; var value = access(array, key, 7);'
            . 'JSON.stringify([value, array.length, Object.hasOwn(array, String(key)), Object.keys(array).length]);'));
        $function = $engine->getGlobalEnv()->get('access');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled);
        self::assertContains(Op::LOAD_COMPUTED, $function->compiled->code);
        self::assertContains(Op::STORE_COMPUTED, $function->compiled->code);
        self::assertNull($function->phpCompiled);
    }
}
