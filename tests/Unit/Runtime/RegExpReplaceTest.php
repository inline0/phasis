<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class RegExpReplaceTest extends TestCase
{
    public function testReplacementResultsAndObservableHooksMatchTheNodeOracle(): void
    {
        $this->checkOracle(false);
    }

    public function testBudgetedReplacementMatchesTheSameOracle(): void
    {
        $this->checkOracle(true);
    }

    public function testFlagGettersRunInTheSpecificationOrder(): void
    {
        self::assertSame([
            'hasIndices', 'global', 'ignoreCase', 'multiline', 'dotAll', 'unicode', 'unicodeSets', 'sticky',
        ], (new Engine())->eval(<<<'JS'
const seen = [];
const target = new Proxy({}, {get(target, name) { seen.push(name); return false; }});
Object.getOwnPropertyDescriptor(RegExp.prototype, 'flags').get.call(target);
seen;
JS));
    }

    private function checkOracle(bool $budgeted): void
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/regexp-replace.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/regexp-replace.json',
            $engine->eval('JSON.stringify(regexpReplaceResult)'),
        );
    }
}
