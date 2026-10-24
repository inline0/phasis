<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CrossRealmGeneratorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function generatorBodies(): iterable
    {
        yield 'snapshot' => ['yield 1; return 2;'];
        yield 'fiber' => ['try { yield 1; return 2; } finally {}'];
    }

    #[DataProvider('generatorBodies')]
    public function testCreationKeepsForeignAndCustomPrototypeChainsIntact(string $body): void
    {
        $foreign = new Engine();
        $foreign->eval('var generator = function* () { ' . $body . ' }; var originalPrototype = generator.prototype;');
        $caller = new Engine();
        $caller->setGlobalJsValue('foreignGenerator', $foreign->getGlobalEnv()->get('generator'));
        $caller->setGlobalJsValue('ForeignObject', $foreign->getGlobalEnv()->get('Object'));
        self::assertTrue($caller->eval(<<<'JS'
const foreign = foreignGenerator();
Object.getPrototypeOf(ForeignObject.prototype) === null && Object.getPrototypeOf(Object.prototype) === null;
JS));
        self::assertTrue($caller->eval(<<<'JS'
const custom = Object.create(null);
foreignGenerator.prototype = custom;
const customized = foreignGenerator();
Object.getPrototypeOf(customized) === custom && Object.getPrototypeOf(custom) === null;
JS));
        $foreign->eval('generator.prototype = null;');
        self::assertTrue($caller->eval(<<<'JS'
const fallback = foreignGenerator();
Object.getPrototypeOf(Object.getPrototypeOf(Object.getPrototypeOf(fallback))) === ForeignObject.prototype;
JS));
    }

    #[DataProvider('generatorBodies')]
    public function testResultObjectsBelongToInvokedNextAndReturnMethods(string $body): void
    {
        $foreign = new Engine();
        $foreign->eval('var generator = function* () { ' . $body . ' };');
        $caller = new Engine();
        $caller->setGlobalJsValue('foreignGenerator', $foreign->getGlobalEnv()->get('generator'));
        $caller->setGlobalJsValue('ForeignObject', $foreign->getGlobalEnv()->get('Object'));
        self::assertTrue($caller->eval(<<<'JS'
const iterator = foreignGenerator();
const local = (function* () { yield 0; })();
const first = iterator.next();
const second = local.next.call(iterator);
const third = iterator.return(3);
const fourth = local.return.call(iterator, 4);
Object.getPrototypeOf(first) === ForeignObject.prototype && first.value === 1 && !first.done &&
Object.getPrototypeOf(second) === Object.prototype && second.value === 2 && second.done &&
Object.getPrototypeOf(third) === ForeignObject.prototype && third.value === 3 && third.done &&
Object.getPrototypeOf(fourth) === Object.prototype && fourth.value === 4 && fourth.done;
JS));
    }

    public function testIteratorResultsDefineOwnDataPropertiesWithoutPrototypeSetters(): void
    {
        $engine = new Engine();
        self::assertSame('1:true:0', $engine->eval(<<<'JS'
let calls = 0;
Object.defineProperty(Object.prototype, 'value', { __proto__: null, set() { calls++; }, configurable: true });
Object.defineProperty(Object.prototype, 'done', { __proto__: null, set() { calls++; }, configurable: true });
const result = (function* () { return 1; })().next();
delete Object.prototype.value;
delete Object.prototype.done;
[result.value, result.done, calls].join(':');
JS));
    }
}
