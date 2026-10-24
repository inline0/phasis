<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Exceptions\BudgetExceeded;
use Phasis\Value\JsFunction;
use Phasis\Value\JsValue;
use PHPUnit\Framework\TestCase;

final class StringCharacterIntrinsicTest extends TestCase
{
    public function testNumericPositionsKeepUtf16AndToIntegerSemantics(): void
    {
        $engine = new Engine();
        self::assertSame(
            '[null,null,233,233,233,233,233,55357,55357,56832,120,null,null]',
            $engine->eval(<<<'JS'
function codes(text, positions) {
    const values = [];
    for (let i = 0; i < positions.length; i++) values.push(text.charCodeAt(positions[i]));
    return JSON.stringify(values);
}
codes('é😀x', [-Infinity, -1.9, -0.5, -0, NaN, 0, 0.8, 1, 1.9, 2, 3, 4, Infinity]);
JS),
        );
        $function = $engine->getGlobalEnv()->get('codes');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled);
        self::assertSame('[55296,120,56320]', $engine->eval('codes("\uD800x\uDC00", [0, 1, 2])'));
        self::assertSame('[null]', $engine->eval('codes("", [0])'));
    }

    public function testPrimitiveNumericCallsUseTheIntrinsicBody(): void
    {
        $engine = new Engine();
        $function = $engine->getGlobalEnv()->get('String')->get('prototype')->get('charCodeAt');
        self::assertInstanceOf(JsFunction::class, $function);
        $native = $function->getNativeCallable();
        self::assertNotNull($native);
        $calls = 0;
        $property = new \ReflectionProperty(JsFunction::class, 'nativeCallable');
        $property->setValue($function, static function (JsValue $receiver, array $arguments) use ($native, &$calls): JsValue {
            ++$calls;
            return $native($receiver, $arguments);
        });
        try {
            self::assertSame([233, 55357], $engine->eval(<<<'JS'
function read(text) { return [text.charCodeAt(0), text.charCodeAt(1)]; }
read('é😀');
JS));
            self::assertSame(0, $calls);
        } finally {
            $property->setValue($function, $native);
        }
    }

    public function testCustomMethodsAndCoercionAreObservedOnce(): void
    {
        $engine = new Engine();
        self::assertSame([[121], ['receiver', 'index'], ['custom', 'custom'], [0, 1]], $engine->eval(<<<'JS'
function read(text, position) { return [text.charCodeAt(position)]; }
function pair(text) { return [text.charCodeAt(0), text.charCodeAt(1)]; }
const events = [];
const original = String.prototype.charCodeAt;
const receiver = { charCodeAt: original, toString() { events.push('receiver'); return 'xy'; } };
const index = { valueOf() { events.push('index'); return 1; } };
const first = read(receiver, index);
const calls = [];
String.prototype.charCodeAt = function (position) { calls.push(position); return 'custom'; };
const second = pair('abc');
String.prototype.charCodeAt = original;
[first, events, second, calls];
JS));
    }

    public function testBorrowedRealmFunctionRetainsItsErrorIdentity(): void
    {
        $engine = new Engine();
        $foreign = new Engine();
        $function = $foreign->getGlobalEnv()->get('String')->get('prototype')->get('charCodeAt');
        self::assertInstanceOf(JsFunction::class, $function);
        $engine->setGlobalJsValue('foreignChar', $function);
        $engine->setGlobalJsValue('foreignTypeError', $foreign->getGlobalEnv()->get('TypeError'));
        self::assertSame([[55357], true], $engine->eval(<<<'JS'
String.prototype.foreign = foreignChar;
function read(text, index) { return [text.foreign(index)]; }
const result = read('é😀', 1);
let sameRealm = false;
try { read('abc', Symbol()); } catch (error) { sameRealm = error.constructor === foreignTypeError; }
[result, sameRealm];
JS));
        self::assertSame($foreign, $function->realm);
    }

    public function testBudgetEnabledDuringACompiledCallStillStopsBeforeTheIntrinsic(): void
    {
        $engine = new Engine();
        $expired = false;
        $engine->setGlobal('expireBudget', static function () use ($engine, &$expired): void {
            if ($expired) {
                $engine->setExecutionBudget(-1);
            }
        });
        self::assertSame([97], $engine->eval(<<<'JS'
function read(text, index) { expireBudget(); const value = text.charCodeAt(index); return [value]; }
read('abc', 0);
JS));
        $function = $engine->getGlobalEnv()->get('read');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled);
        $expired = true;
        $this->expectException(BudgetExceeded::class);
        $engine->eval("read('abc', 0)");
    }
}
