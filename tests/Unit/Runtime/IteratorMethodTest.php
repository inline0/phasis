<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class IteratorMethodTest extends TestCase
{
    public function testIteratorMethodsAndPrimitiveReceiversMatchNode(): void
    {
        foreach ([false, true] as $budgeted) {
            $engine = new Engine();
            if ($budgeted) {
                $engine->setExecutionBudget(10);
            }
            $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/iterator-methods.js'));
            self::assertJsonStringEqualsJsonFile(
                __DIR__ . '/fixtures/iterator-methods.json',
                $engine->eval('JSON.stringify(iteratorMethodsResult)'),
            );
        }
    }
}
