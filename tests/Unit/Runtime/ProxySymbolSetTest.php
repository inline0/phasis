<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class ProxySymbolSetTest extends TestCase
{
    public function testSymbolWritesPreserveProxyTrapsAndReceiversInBytecode(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/proxy-symbol-set.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/proxy-symbol-set.json',
            $engine->eval('JSON.stringify(proxySymbolSetResult)'),
        );
        $function = $engine->getGlobalEnv()->get('writeSymbol');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled);
    }

    public function testInterpretedSymbolWritesMatchTheSameNodeOracle(): void
    {
        $engine = new Engine();
        $engine->setExecutionBudget(10);
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/proxy-symbol-set.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/proxy-symbol-set.json',
            $engine->eval('JSON.stringify(proxySymbolSetResult)'),
        );
        $function = $engine->getGlobalEnv()->get('writeSymbol');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNull($function->compiled);
    }
}
