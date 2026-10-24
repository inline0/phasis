<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit;

use Phasis\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InteropRegressionTest extends TestCase
{
    public function testLargeNumericResultsDoNotAttemptOutOfRangeIntegerCasts(): void
    {
        $numbers = [PHP_FLOAT_MAX, -PHP_FLOAT_MAX, (float) PHP_INT_MAX, -(float) PHP_INT_MAX, 42.0, 0.5];
        $engine = new Engine();
        $engine->setGlobal('numbers', $numbers);
        set_error_handler(static function (int $severity, string $message): never {
            throw new \ErrorException($message, 0, $severity);
        });
        try {
            self::assertSame([
                PHP_FLOAT_MAX, -PHP_FLOAT_MAX, (float) PHP_INT_MAX, -(float) PHP_INT_MAX, 42, 0.5,
            ], $engine->eval('numbers'));
        } finally {
            restore_error_handler();
        }
    }

    public function testPublicPropertiesAndMethodsReferToTheOriginalObject(): void
    {
        $engine = new Engine();
        $counter = new class {
            public int $value = 0;
            private string $secret = 'hidden';

            public function increment(): void
            {
                $this->value++;
            }

            public function identity(): object
            {
                return $this;
            }

            private function hidden(): string
            {
                return $this->secret;
            }
        };
        $engine->setGlobal('counter', $counter);
        $engine->setGlobal('alias', $counter);
        $engine->setGlobal('other', new \stdClass());
        self::assertTrue($engine->eval('counter === alias && counter !== other && counter.identity() === counter'));
        self::assertSame(1, $engine->eval('counter.increment(); counter.value'));
        self::assertSame(1, $counter->value);
        self::assertSame(5, $engine->eval('counter.value = 5; counter.value'));
        self::assertSame(5, $counter->value);
        $counter->value = 8;
        self::assertSame(8, $engine->eval('counter.value'));
        self::assertSame(['undefined', 'undefined'], $engine->eval('[typeof counter.secret, typeof counter.hidden]'));
        self::assertSame($counter, $engine->eval('counter'));
        $engine->setGlobal('accept', fn (object $value): bool => $value === $counter);
        self::assertTrue($engine->eval('accept(counter)'));
    }

    public function testStdClassPropertiesStayLiveInBothDirections(): void
    {
        $engine = new Engine();
        $object = (object) ['name' => 'Ada'];
        $object->self = $object;
        $engine->setGlobal('object', $object);
        self::assertTrue($engine->eval('object.self === object'));
        $object->later = 2;
        self::assertSame(2, $engine->eval('object.later'));
        self::assertSame(3, $engine->eval('object.added = 3; object.added'));
        self::assertSame(3, $object->added);
        unset($object->later);
        self::assertFalse($engine->eval('"later" in object'));
        self::assertSame(['name', 'self', 'added'], $engine->eval('Object.keys(object)'));
    }

    public function testTypedAndReadonlyPropertiesRetainTheirPhpRules(): void
    {
        $engine = new Engine();
        $object = new class {
            public int $count = 1;
            public readonly int $fixed;

            public function __construct()
            {
                $this->fixed = 9;
            }
        };
        $engine->setGlobal('object', $object);
        self::assertTrue($engine->eval('try { object.count = "invalid"; false; } catch (e) { e instanceof TypeError && e.name === "TypeError"; }'));
        self::assertSame(1, $object->count);
        self::assertFalse($engine->eval('Reflect.set(object, "fixed", 3)'));
        self::assertSame(9, $object->fixed);
    }

    public function testOrdinaryAndArrowCallbacksCanBeInvokedFromPhp(): void
    {
        $engine = new Engine();
        $engine->setGlobal('apply', fn (callable $callback): mixed => $callback(3, 4));
        self::assertSame(7, $engine->eval('apply(function (a, b) { return a + b; })'));
        self::assertSame(12, $engine->eval('apply((a, b) => a * b)'));
        self::assertSame(['total' => 7], $engine->eval('apply((a, b) => ({total: a + b}))'));
        self::assertSame(15, $engine->eval('const offset = 8; apply((a, b) => a + b + offset)'));
    }

    public function testRetainedCallbacksUseTheirOwnRealm(): void
    {
        $engine = new Engine();
        $callback = null;
        $engine->setGlobal('keep', function (callable $value) use (&$callback): void {
            $callback = $value;
        });
        $engine->eval('var offset = 10; keep(function (n) { return offset + n; });');
        $other = new Engine();
        $other->eval('var offset = 1000;');
        self::assertIsCallable($callback);
        self::assertSame(13, $callback(3));
        self::assertSame(1000, $other->eval('offset'));
    }

    public function testCallbackErrorsReturnToTheJavascriptHandler(): void
    {
        $engine = new Engine();
        $engine->setGlobal('apply', fn (callable $callback): mixed => $callback());
        self::assertSame('callback', $engine->eval('(function () { try { apply(() => { throw new Error("callback"); }); } catch (e) { '
            . 'return e.message; } })()'));
    }

    public function testHostValuesBelongToTheEngineReceivingThem(): void
    {
        $first = new Engine();
        $second = new Engine();
        $object = (object) ['value' => 1];
        $first->setGlobal('object', $object);
        $second->eval('1');
        $first->setGlobal('alias', $object);
        self::assertTrue($first->eval('object === alias'));
        self::assertTrue($first->eval('object instanceof Object'));
        self::assertTrue($first->eval('Object.getPrototypeOf(object) === Object.prototype'));
        $first->setGlobal('boom', static function (): never {
            throw new \TypeError('host');
        });
        self::assertTrue($first->eval('try { boom(); } catch (e) { e instanceof TypeError; }'));
        $first->eval('function same(value) { return value === object; }');
        $second->eval('1');
        self::assertTrue($first->call('same', $object));
    }

    public function testCyclicHostArgumentsTerminateAndPreserveIndependentAliases(): void
    {
        $engine = new Engine();
        $engine->setGlobal('copy', fn (mixed $value): mixed => $value);
        self::assertSame(['value' => 1, 'self' => null], $engine->eval('const object = {value: 1}; object.self = object; copy(object)'));
        self::assertSame([null], $engine->eval('const array = []; array.push(array); copy(array)'));
        self::assertSame([['value' => 2], ['value' => 2]], $engine->eval('const shared = {value: 2}; copy([shared, shared])'));
    }

    /** @return iterable<string, array{\Throwable, string}> */
    public static function hostErrors(): iterable
    {
        yield 'exception' => [new \RuntimeException('plain'), 'RuntimeException'];
        yield 'type' => [new \TypeError('bad type'), 'TypeError'];
        yield 'arguments' => [new \ArgumentCountError('missing'), 'ArgumentCountError'];
        yield 'division' => [new \DivisionByZeroError('zero'), 'DivisionByZeroError'];
    }

    #[DataProvider('hostErrors')]
    public function testHostThrowablesCanBeCaughtByJavascript(\Throwable $error, string $name): void
    {
        $engine = new Engine();
        $engine->setGlobal('boom', static function () use ($error): never {
            throw $error;
        });
        self::assertSame(
            [$name, $error->getMessage(), true],
            $engine->eval('(function () { try { boom(); } catch (e) { return [e.name, e.message, e instanceof '
                . 'Error]; } })()'),
        );
        self::assertSame($name, $engine->eval('try { boom(); } catch (e) { e.name; }'));
        self::assertSame($name, $engine->eval('class Example { run() { try { boom(); } catch (e) { return e.name; } } } new Example().run()'));
        $engine->eval('var result; Promise.resolve().then(() => boom()).catch(e => { result = e.name; });');
        self::assertSame($name, $engine->eval('result'));
    }

    public function testPhpSignatureErrorsAndMethodExceptionsCanBeCaught(): void
    {
        $engine = new Engine();
        $engine->setGlobal('length', fn (string $value): int => strlen($value));
        self::assertSame('ArgumentCountError', $engine->eval('try { length(); } catch (e) { e.name; }'));
        self::assertSame('TypeError', $engine->eval('try { length(42); } catch (e) { e.name; }'));
        $engine->setGlobal('object', new class {
            public function fail(): never
            {
                throw new \LogicException('method');
            }
        });
        self::assertSame(['LogicException', 'method'], $engine->eval('try { object.fail(); } catch (e) { [e.name, e.message]; }'));
    }

    /** @return iterable<string, array{bool}> */
    public static function modes(): iterable
    {
        yield 'lazy' => [false];
        yield 'eager' => [true];
    }

    #[DataProvider('modes')]
    public function testResetRebuildsTheGlobalObjectAndBuiltins(bool $eager): void
    {
        $engine = new Engine(eager: $eager);
        $expression = '[typeof Map, typeof JSON, typeof console, typeof fetch, typeof Uint8Array, globalThis === this, self === this]';
        $before = $engine->eval($expression);
        $engine->eval('var custom = 1; console.log("before");');
        for ($i = 0; $i < 2; $i++) {
            $engine->reset();
            self::assertSame($before, $engine->eval($expression));
            self::assertSame('undefined', $engine->eval('typeof custom'));
            self::assertSame('', $engine->getConsoleOutput());
            self::assertSame(2, $engine->eval('new Map([["value", 2]]).get("value")'));
            self::assertSame([false, false, false], $engine->eval('const descriptor = Object.getOwnPropertyDescriptor(globalThis, "Infinity"); '
                . '[descriptor.writable, descriptor.enumerable, descriptor.configurable]'));
        }
    }
}
