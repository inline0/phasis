<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\Op;
use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class UpdateMemberTest extends TestCase
{
    public function testCompiledPropertyUpdatesMatchTheNodeOracle(): void
    {
        $engine = $this->checkOracle(false);
        $members = [
            'memberPostIncrement', 'memberPreIncrement', 'memberPostDecrement', 'memberPreDecrement',
            'strictMemberIncrement', 'updateGlobalMember',
            'primitiveMemberPost', 'primitiveMemberStrict',
        ];
        foreach ($members as $name) {
            $this->assertUsesOpcode($engine, $name, Op::UPDATE_MEMBER);
        }
        $computed = [
            'computedPostIncrement', 'computedPreIncrement', 'computedPostDecrement', 'computedPreDecrement',
            'strictComputedIncrement', 'computedExpressionIncrement', 'updateGlobalComputed',
            'primitiveComputedPost', 'primitiveComputedStrict',
        ];
        foreach ($computed as $name) {
            $this->assertUsesOpcode($engine, $name, Op::UPDATE_COMPUTED);
        }
    }

    public function testInterpreterPropertyUpdatesMatchTheSameNodeOracle(): void
    {
        $this->checkOracle(true);
    }

    public function testProgramPropertyUpdatesUseTheLiveGlobalBinding(): void
    {
        self::assertSame([1, 3, 3], (new Engine())->eval(<<<'JS'
var value = 1;
var alias = globalThis;
var first = alias.value++;
var second = ++globalThis.value;
[first, second, value];
JS));
    }

    public function testComputedObjectKeysRetainTheirNormalizedReferenceName(): void
    {
        // Node 26.12 coerces these keys twice. Follow GetValue's normalized
        // Reference name and the pinned test262 S11.*_A6_T3 cases instead.
        $source = <<<'JS'
function specUpdate(base, key) { return base[key]++; }
const symbol = Symbol('key');
const target = {first: 1, second: 20, [symbol]: 10};
let conversions = 0;
const key = {
    [Symbol.toPrimitive]() { return ['first', symbol, 'second'][conversions++]; }
};
const seen = [specUpdate(target, key), target.first, specUpdate(target, key), target[symbol],
    specUpdate(target, key), target.second, conversions];
let throws = 0;
const throwingKey = {toString() { if (throws++) throw new RangeError('later reference'); return 'first'; }};
seen.push(specUpdate(target, throwingKey), target.first);
try { specUpdate(target, throwingKey); } catch (error) { seen.push(error.name); }
seen.push(throws);
const mutableKey = {toString() { return 'first'; }};
const changing = {
    first: {valueOf() { mutableKey.toString = function () { return symbol; }; return 3; }},
    [symbol]: 10
};
seen.push(specUpdate(changing, mutableKey), changing.first, changing[symbol],
    specUpdate(changing, mutableKey), changing[symbol]);
seen;
JS;
        foreach ([false, true] as $budgeted) {
            $engine = new Engine();
            if ($budgeted) {
                $engine->setExecutionBudget(10);
            }
            self::assertSame([1, 2, 10, 11, 20, 21, 3, 2, 3, 'RangeError', 2, 3, 4, 10, 10, 11], $engine->eval($source));
            if (!$budgeted) {
                $this->assertUsesOpcode($engine, 'specUpdate', Op::UPDATE_COMPUTED);
            }
        }
    }

    private function assertUsesOpcode(Engine $engine, string $name, int $opcode): void
    {
        $function = $engine->getGlobalEnv()->get($name);
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled, $name);
        self::assertContains($opcode, $function->compiled->code, $name);
    }

    private function checkOracle(bool $interpreter): Engine
    {
        $engine = new Engine();
        if ($interpreter) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/update-members.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/update-members.json',
            $engine->eval('JSON.stringify(updateMemberResult)'),
        );
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/primitive-reference-updates.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/primitive-reference-updates.json',
            $engine->eval('JSON.stringify(primitiveReferenceUpdateResult)'),
        );

        return $engine;
    }
}
