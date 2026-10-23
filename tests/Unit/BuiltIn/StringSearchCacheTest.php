<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\BuiltIn;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class StringSearchCacheTest extends TestCase
{
    public function testRepeatedSearchesMatchNodeCodeUnitsCoercionsAndMethodMutation(): void
    {
        $engine = new Engine();
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/string-search-cache.js'));
        self::assertSame(
            trim((string) file_get_contents(__DIR__ . '/fixtures/string-search-cache.json')),
            $engine->eval('JSON.stringify(stringSearchCacheResult)'),
        );
        $function = $engine->getGlobalEnv()->get('cachedSearch');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled, 'The search fixture must also exercise warmed bytecode dispatch.');
    }
}
