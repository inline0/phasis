<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class LexicalThisTest extends TestCase
{
    public function testCallsAndBoundFunctionsPreserveLexicalReceivers(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/lexical-this.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/lexical-this.json',
            $engine->eval('JSON.stringify(lexicalThisResult)'),
        );
        foreach (['direct', 'capture', 'captureBlock', 'nestedCapture', 'first', 'second', 'block', 'nested'] as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNull($function->phpCompiled, $name . ' must preserve its lexical this binding.');
        }
        $ordinary = $engine->getGlobalEnv()->get('ordinary');
        self::assertInstanceOf(JsFunction::class, $ordinary);
        self::assertNotNull($ordinary->phpCompiled, 'Ordinary receiver reads remain eligible for PHP emission.');
    }
}
