<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class RegExpPredicateTest extends TestCase
{
    public function testPredicatesPreserveLegacyStateAndObservableOperations(): void
    {
        $this->checkOracle(false);
    }

    public function testBudgetedPredicatesMatchTheSameNodeOracle(): void
    {
        $this->checkOracle(true);
    }

    private function checkOracle(bool $budgeted): void
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/regexp-predicate.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/regexp-predicate.json',
            $engine->eval('JSON.stringify(regexpPredicateResult)'),
        );
    }
}
