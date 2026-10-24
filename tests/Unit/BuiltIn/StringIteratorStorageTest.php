<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\BuiltIn\StringPrototype;
use Phasis\Engine;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsStringIterator;
use PHPUnit\Framework\TestCase;

final class StringIteratorStorageTest extends TestCase
{
    public function testNativeStepsAndProtocolOverridesMatchNode(): void
    {
        foreach ([false, true] as $budgeted) {
            $engine = new Engine();
            if ($budgeted) {
                $engine->setExecutionBudget(10);
            }
            $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/string-iterator-storage.js'));
            self::assertJsonStringEqualsJsonFile(
                __DIR__ . '/fixtures/string-iterator-storage.json',
                $engine->eval('JSON.stringify(stringIteratorStorage)'),
            );
        }
    }

    public function testResultPropertiesBypassInheritedSetters(): void
    {
        $engine = new Engine();
        self::assertSame('["x",false,0,["value","done"]]', $engine->eval(<<<'JS'
(() => {
    let calls = 0;
    Object.defineProperty(Object.prototype, 'value', { __proto__: null, configurable: true, set() { calls++; } });
    Object.defineProperty(Object.prototype, 'done', { __proto__: null, configurable: true, set() { calls++; } });
    const result = 'x'[Symbol.iterator]().next();
    delete Object.prototype.value;
    delete Object.prototype.done;
    return JSON.stringify([result.value, result.done, calls, Object.keys(result)]);
})()
JS));
    }

    public function testForeignFactoriesAndNextMethodsKeepTheirRealm(): void
    {
        $foreign = new Engine();
        $foreign->eval(<<<'JS'
var foreignFactory = String.prototype[Symbol.iterator];
var foreignIteratorPrototype = Object.getPrototypeOf(''[Symbol.iterator]());
var foreignNext = foreignIteratorPrototype.next;
var foreignObjectPrototype = Object.prototype;
JS);
        $caller = new Engine();
        self::assertTrue($foreign->eval("Object.getPrototypeOf('x'[Symbol.iterator]().next()) === Object.prototype"));
        foreach (['foreignFactory', 'foreignIteratorPrototype', 'foreignNext', 'foreignObjectPrototype'] as $name) {
            $caller->getGlobalEnv()->defineVar($name, $foreign->getGlobalEnv()->get($name));
        }
        for ($pass = 0; $pass < 3; $pass++) {
            self::assertSame('[true,true,true,"x","y"]', $caller->eval(<<<'JS'
(() => {
    const iterator = foreignFactory.call('xy');
    const first = iterator.next();
    const second = foreignNext.call(iterator);
    const local = foreignNext.call('z'[Symbol.iterator]());
    return JSON.stringify([Object.getPrototypeOf(iterator) === foreignIteratorPrototype,
        Object.getPrototypeOf(first) === foreignObjectPrototype,
        Object.getPrototypeOf(local) === foreignObjectPrototype, first.value, second.value]);
})()
JS));
        }
    }

    public function testExhaustedIteratorReleasesItsSource(): void
    {
        $engine = new Engine();
        $prototype = $engine->getGlobalEnv()->getIntrinsic('StringIteratorPrototype');
        self::assertInstanceOf(JsObject::class, $prototype);
        $next = $prototype->getInternalProperty('StringIteratorNext');
        self::assertInstanceOf(JsFunction::class, $next);
        $string = new JsString('x');
        $reference = \WeakReference::create($string);
        $iterator = StringPrototype::createStringIterator($string, $prototype);
        self::assertInstanceOf(JsStringIterator::class, $iterator);
        unset($string);
        $done = false;
        self::assertSame('x', $iterator->nextValue($done)->value);
        self::assertFalse($done);
        self::assertNotNull($reference->get());
        $iterator->nextValue($done);
        self::assertTrue($done);
        self::assertNull($reference->get());
        $iterator->nextValue($done);
        self::assertTrue($done);
    }

    public function testHostStringConversionRunsOnceWhenTheIteratorIsCreated(): void
    {
        $engine = new Engine();
        $prototype = $engine->getGlobalEnv()->getIntrinsic('StringIteratorPrototype');
        self::assertInstanceOf(JsObject::class, $prototype);
        $string = new class ('ab') extends JsString {
            public int $calls = 0;

            public function utf16LE(): string
            {
                $this->calls++;
                return parent::utf16LE();
            }
        };
        $iterator = StringPrototype::createStringIterator($string, $prototype);
        self::assertInstanceOf(JsStringIterator::class, $iterator);
        self::assertSame(1, $string->calls);
        $done = false;
        self::assertSame('a', $iterator->nextValue($done)->value);
        self::assertSame('b', $iterator->nextValue($done)->value);
        $iterator->nextValue($done);
        self::assertTrue($done);
        self::assertSame(1, $string->calls);
    }
}
