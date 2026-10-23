<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

class CrossRealmExecutionErrorTest extends TestCase
{
    public function testCompletionConversionUsesTheExecutingFunctionsRealm(): void
    {
        $foreign = new Engine();
        $foreign->eval(<<<'JS'
class Example {
    #value = 1;
    read(other) { return other.#value; }
    readInBlock(other) { { return other.#value; } }
    readInLoop(other) { for (let index = 0; index < 1; index++) return other.#value; }
    catchError(other) {
        try { return other.#value; } catch (error) { return error.constructor; }
    }
}
var instance = new Example();
JS);
        $caller = new Engine();
        $caller->setGlobal('instance', $foreign->getGlobalEnv()->get('instance'));
        $caller->setGlobal('ForeignTypeError', $foreign->getGlobalEnv()->get('TypeError'));

        foreach (['read', 'readInBlock', 'readInLoop'] as $method) {
            self::assertTrue($caller->eval('try { instance.' . $method . '({}); } '
                . 'catch (error) { error.constructor === ForeignTypeError; }'));
        }
        self::assertTrue($caller->eval('instance.catchError({}) === ForeignTypeError'));
    }

    public function testAnExplicitThrownValueKeepsItsOriginalRealm(): void
    {
        $foreign = new Engine();
        $foreign->eval('function relay(error) { throw error; }');
        $caller = new Engine();
        $caller->setGlobal('relay', $foreign->getGlobalEnv()->get('relay'));

        self::assertTrue($caller->eval(<<<'JS'
var original = new TypeError('original');
try { relay(original); }
catch (error) { error === original && error.constructor === TypeError; }
JS));
    }

    public function testDerivedConstructorReturnErrorsUseTheCallerRealm(): void
    {
        $foreign = new Engine();
        $foreign->eval('var Constructor = class extends Object { constructor() { return 1; } };');
        $caller = new Engine();
        $caller->setGlobal('ForeignConstructor', $foreign->getGlobalEnv()->get('Constructor'));

        self::assertTrue($caller->eval(<<<'JS'
try { new ForeignConstructor(); }
catch (error) { error.constructor === TypeError; }
JS));
    }
}
