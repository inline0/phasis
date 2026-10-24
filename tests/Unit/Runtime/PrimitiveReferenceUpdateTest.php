<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class PrimitiveReferenceUpdateTest extends TestCase
{
    public function testPrimitiveReferencesMatchTheNodeOracle(): void
    {
        $this->checkOracle(false);
    }

    public function testBudgetedPrimitiveReferencesMatchTheSameOracle(): void
    {
        $this->checkOracle(true);
    }

    private function checkOracle(bool $budgeted): void
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/../Bytecode/fixtures/primitive-reference-updates.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/../Bytecode/fixtures/primitive-reference-updates.json',
            $engine->eval('JSON.stringify(primitiveReferenceUpdateResult)'),
        );
    }
}
