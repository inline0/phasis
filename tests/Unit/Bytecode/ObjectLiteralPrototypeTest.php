<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class ObjectLiteralPrototypeTest extends TestCase
{
    public function testCompiledLiteralInitializationMatchesNode(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/object-literal-prototype.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/object-literal-prototype.json',
            $engine->eval('JSON.stringify(objectLiteralPrototypeResult)'),
        );
        $names = [
            'prototypeLiteral', 'quotedPrototypeLiteral', 'computedLiteral', 'shorthandLiteral',
            'mixedLiteral', 'anonymousPrototypeLiteral', 'orderedLiteral',
        ];
        foreach ($names as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise the bytecode path.');
        }
    }
}
