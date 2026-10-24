<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumericTruthinessTest extends TestCase
{
    private const SOURCE = <<<'JS'
function scalarNot(value) { return !value; }
function scalarDoubleNot(value) { return !!value; }
function scalarIf(value) { if (value) return 1; return 0; }
function scalarConditional(value) { return value ? 1 : 0; }
function scalarAnd(value) { return value && 7; }
function scalarOr(value) { return value || 7; }
function scalarWhile(value) { let n = 0; while (value) { n++; break; } return n; }
function scalarFor(value) { let n = 0; for (; value;) { n++; break; } return n; }
function scalarDoWhile(value) { let n = 0; do { n++; if (n === 2) break; } while (value); return n; }
function scalarOnceConditional(value) { let n = 0; return n++ + value ? n * 10 + 1 : n * 10; }
function scalarOnceNot(value) { let n = 0; return !(n++ + value) ? n * 10 + 1 : n * 10; }
function scalarOnceAnd(value) {
    let n = 0;
    let result = (n++ + value) && (n = 2);
    return result === result ? result * 10 + n : 100 + n;
}
function scalarOnceOr(value) {
    let n = 0;
    let result = (n++ + value) || (n = 2);
    return result === result ? n : 100 + n;
}
JS;

    /** @return iterable<string, array{string, bool}> */
    public static function numbers(): iterable
    {
        foreach (['NaN', '0', '-0'] as $value) {
            yield $value => [$value, false];
        }
        foreach (['1', '-1', 'Infinity', '-Infinity', '5e-324', '-5e-324', '1e100'] as $value) {
            yield $value => [$value, true];
        }
    }

    #[DataProvider('numbers')]
    public function testCompiledConditionsUseNumberToBooleanExactlyOnce(string $value, bool $truth): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        $boolean = $truth ? 'true' : 'false';
        $number = $truth ? '1' : '0';
        $onceAnd = $truth ? 22 : ($value === 'NaN' ? 101 : 1);
        $checks = 'var value = ' . $value . '; ['
            . 'scalarNot(value) === !' . $boolean . ','
            . 'scalarDoubleNot(value) === ' . $boolean . ','
            . 'scalarIf(value) === ' . $number . ','
            . 'scalarConditional(value) === ' . $number . ','
            . 'Object.is(scalarAnd(value), ' . ($truth ? '7' : 'value') . '),'
            . 'Object.is(scalarOr(value), ' . ($truth ? 'value' : '7') . '),'
            . 'scalarWhile(value) === ' . $number . ','
            . 'scalarFor(value) === ' . $number . ','
            . 'scalarDoWhile(value) === ' . ($truth ? '2' : '1') . ','
            . 'scalarOnceConditional(value) === ' . ($truth ? '11' : '10') . ','
            . 'scalarOnceNot(value) === ' . ($truth ? '10' : '11') . ','
            . 'scalarOnceAnd(value) === ' . $onceAnd . ','
            . 'scalarOnceOr(value) === ' . ($truth ? '1' : '2') . ']';
        for ($pass = 0; $pass < 2; $pass++) {
            self::assertSame(array_fill(0, 13, true), $engine->eval($checks));
        }
        foreach ($engine->getGlobalEnv()->allBindings() as $name => $function) {
            if (str_starts_with($name, 'scalar')) {
                self::assertInstanceOf(JsFunction::class, $function);
                self::assertInstanceOf(\Closure::class, $function->phpCompiled, $name);
            }
        }
    }

    public function testProvenBooleanScannerAndRegionConditionsRemainCompiled(): void
    {
        $engine = new Engine();
        self::assertSame([1, [0, 1]], $engine->eval(<<<'JS'
function prime(text) { return [text.charCodeAt(0)]; }
prime('x');
function scanner(text, start) {
    let count = 0;
    while (start > 0) { count++; start = 0; }
    return count + text.length;
}
function region(text, start) {
    let count = 0;
    while (start > 0) {
        const code = text.charCodeAt(0);
        count++;
        start = 0;
    }
    return [count, text.length];
}
[scanner('x', NaN), region('x', NaN)];
JS));
        $scanner = $engine->getGlobalEnv()->get('scanner');
        self::assertInstanceOf(JsFunction::class, $scanner);
        self::assertInstanceOf(\Closure::class, $scanner->phpCompiled);
        $region = $engine->getGlobalEnv()->get('region');
        self::assertInstanceOf(JsFunction::class, $region);
        self::assertNotNull($region->compiled);
        self::assertCount(1, array_filter($region->compiled->loopRegions));
    }
}
