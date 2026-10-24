<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\CompiledFunction;
use Phasis\Bytecode\Op;
use Phasis\Bytecode\StringLoopRegion;
use Phasis\Engine;
use Phasis\Exceptions\InternalError;
use Phasis\Exceptions\BudgetExceeded;
use Phasis\Value\JsNumber;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use PHPUnit\Framework\TestCase;

final class StringLoopRegionTest extends TestCase
{
    private const SOURCE = <<<'JS'
let creations = 0;
const events = [];
function prime(text) { return [text.charCodeAt(0)]; }
prime('x');
function scan(text, start) {
    const state = { creation: ++creations };
    let index = start;
    while (index < text.length) {
        const code = text.charCodeAt(index);
        if (code === 44) {
            events.push(index);
            index++;
            break;
        }
        index++;
    }
    return [state.creation, index];
}
JS;

    public function testOpaqueExitsDoNotReplayPrefixOrBranchEffects(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        self::assertSame([[1, 4], [2, 3], [3], 2], $engine->eval("[scan('abc,def', 0), scan('abc', 0), events, creations]"));
        $compiled = $this->compiled($engine);
        self::assertCount(1, array_filter($compiled->loopRegions));
        $regionPc = $this->regionPc($compiled);
        $entry = $regionPc + 2;
        self::assertSame(Op::CHECK_BUDGET, $compiled->code[$entry]);
        for ($pc = 0; $pc < count($compiled->code); $pc += 1 + Op::operandCount($compiled->code[$pc])) {
            if ($compiled->code[$pc] === Op::JUMP && $compiled->code[$pc + 1] < 0) {
                self::assertSame($entry, $pc + $compiled->code[$pc + 1]);
            }
        }
    }

    public function testNativeExitRestoresActiveSlotsAndCounterExactlyOnce(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        $engine->eval("scan('a,b', 0)");
        $compiled = $this->compiled($engine);
        [$region, $locals, $counts, $loopId] = $this->prepare($compiled, 'abc,def');
        $exit = $this->execute($engine, $region, $locals, $counts);
        self::assertNotNull($exit);
        self::assertSame(3.0, $locals[array_search('index', $compiled->localNames, true)]->value);
        self::assertSame(44.0, $locals[array_search('code', $compiled->localNames, true)]->value);
        self::assertSame(4, $counts[$loopId]);
        self::assertSame([1], $engine->eval('events'));
        self::assertSame(1, $engine->eval('creations'));
    }

    public function testFinalFalseDoesNotCountAndLimitThrowsCommitTheSameState(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        $engine->eval("scan('ab', 0)");
        $compiled = $this->compiled($engine);
        [$region, $locals, $counts, $loopId] = $this->prepare($compiled, 'ab');
        self::assertNotNull($this->execute($engine, $region, $locals, $counts));
        self::assertSame(2, $counts[$loopId]);
        self::assertSame(2.0, $locals[array_search('index', $compiled->localNames, true)]->value);
        $engine->setLimit('maxLoopIterations', 2);
        [$region, $locals, $counts, $loopId] = $this->prepare($compiled, 'abcd');
        try {
            $this->execute($engine, $region, $locals, $counts);
            self::fail('The region skipped its precondition checkpoint');
        } catch (InternalError) {
        }
        self::assertSame(3, $counts[$loopId]);
        self::assertSame(2.0, $locals[array_search('index', $compiled->localNames, true)]->value);
        self::assertSame(98.0, $locals[array_search('code', $compiled->localNames, true)]->value);
    }

    public function testAHandlerObservesUpdatesCommittedBeforeTheLimitThrows(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
function prime(text) { return [text.charCodeAt(0)]; }
prime('x');
function observed(text) {
    const state = {};
    let index = 0;
    try {
        while (index < text.length) {
            const code = text.charCodeAt(index);
            if (code === 44) break;
            index++;
        }
    } catch (error) { return [index, String(error).includes('Maximum loop iterations')]; }
    return [index, false];
}
JS);
        $engine->setLimit('maxLoopIterations', 2);
        self::assertSame([2, true], $engine->eval("observed('abcd')"));
    }

    public function testGuardFailureDoesNotReadGettersOrWriteAnySlot(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        $engine->eval("scan('ab', 0)");
        $compiled = $this->compiled($engine);
        [$region, $locals, $counts] = $this->prepare($compiled, 'é');
        $original = $locals;
        self::assertNull($this->execute($engine, $region, $locals, $counts));
        self::assertSame($original, $locals);
        self::assertSame([], $counts);
        $engine->eval(<<<'JS'
let accesses = 0;
const original = String.prototype.charCodeAt;
Object.defineProperty(String.prototype, 'charCodeAt', { configurable: true, get() { accesses++; return original; } });
JS);
        [$region, $locals, $counts] = $this->prepare($compiled, 'ab');
        self::assertNull($this->execute($engine, $region, $locals, $counts));
        self::assertSame(0, $engine->eval('accesses'));
        self::assertSame([2, 2], $engine->eval("[scan('ab', 0)[1], accesses]"));
    }

    public function testOpaqueContinueReturnsToOrdinaryBytecodeAfterMethodMutation(): void
    {
        $engine = new Engine();
        self::assertSame([4, [2, 3]], $engine->eval(<<<'JS'
const native = String.prototype.charCodeAt;
const reads = [];
function prime(text) { return [text.charCodeAt(0)]; }
prime('x');
function scan(text) {
    const state = {};
    let index = 0;
    while (index < text.length) {
        const code = text.charCodeAt(index);
        if (code === 44) {
            String.prototype.charCodeAt = function (position) { reads.push(position); return native.call(this, position); };
            index++;
            continue;
        }
        index++;
    }
    return index;
}
const result = scan('a,bc');
String.prototype.charCodeAt = native;
[result, reads];
JS));
        self::assertNotEmpty(array_filter($this->compiled($engine)->loopRegions));
    }

    public function testBooleanStateAndNumericUpdatesStayTypedAcrossIterations(): void
    {
        $engine = new Engine();
        self::assertSame([4, false], $engine->eval(<<<'JS'
function prime(text) { return [text.charCodeAt(0)]; }
prime('x');
function scan(text) {
    const state = {};
    let index = 0;
    let alternate = false;
    while (index < text.length) {
        const code = text.charCodeAt(index);
        if (alternate && code === 92) index++;
        if (code === 44) break;
        alternate = !alternate;
        index++;
    }
    return [index, alternate];
}
scan('abcd');
JS));
        self::assertNotEmpty(array_filter($this->compiled($engine)->loopRegions));
    }

    public function testNumericObjectCoercionAndPrefixEffectsAreNotReplayed(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        self::assertSame([[1, 3], 3, 1], $engine->eval(<<<'JS'
let coercions = 0;
const offset = { valueOf() { coercions++; return 0; } };
[scan('abc', offset), coercions, creations];
JS));
        self::assertNotEmpty(array_filter($this->compiled($engine)->loopRegions));
    }

    public function testSharedTemplateExecutorsUseTheInvocationRealm(): void
    {
        $first = new Engine();
        $first->eval(self::SOURCE);
        $first->eval("scan('ab', 0)");
        $source = $first->getGlobalEnv()->get('scan');
        self::assertInstanceOf(\Phasis\Value\JsFunction::class, $source);
        $second = new Engine();
        $second->eval(self::SOURCE);
        $shared = new \Phasis\Value\JsFunction('shared', $source->getParams(), $source->getBody(), $second->getGlobalEnv());
        $shared->realm = $second;
        $second->setGlobalJsValue('shared', $shared);
        self::assertSame([1, 2], $second->eval("shared('ab', 0)"));
        self::assertInstanceOf(CompiledFunction::class, $shared->compiled);
        self::assertNotSame($source->compiled, $shared->compiled);
        self::assertSame($source->compiled->loopRegions[0], $shared->compiled->loopRegions[0]);
        $second->eval(<<<'JS'
let reads = 0;
const method = String.prototype.charCodeAt;
Object.defineProperty(String.prototype, 'charCodeAt', { configurable: true, get() { reads++; return method; } });
JS);
        [$region, $locals, $counts] = $this->prepare($this->compiled($first), 'ab');
        self::assertNull($this->execute($second, $region, $locals, $counts));
        self::assertSame(0, $second->eval('reads'));
        self::assertNotNull($this->execute($first, $region, $locals, $counts));
    }

    public function testABudgetActivatedBeforeEntryUsesTheOriginalCheckpoint(): void
    {
        $engine = new Engine();
        $expire = false;
        $hooks = 0;
        $engine->setGlobal('activateBudget', static function () use ($engine, &$expire, &$hooks): void {
            ++$hooks;
            if ($expire) {
                $engine->setExecutionBudget(-1);
            }
        });
        $engine->eval(<<<'JS'
let prefixes = 0;
let swallowed = 0;
function prime(text) { return [text.charCodeAt(0)]; }
prime('x');
function scan(text) {
    const state = { count: ++prefixes };
    let index = 0;
    activateBudget();
    try {
        while (index < text.length) {
            const code = text.charCodeAt(index);
            if (code === 44) break;
            index++;
        }
    } catch (error) { swallowed++; }
    return index;
}
scan('ab');
JS);
        self::assertNotEmpty(array_filter($this->compiled($engine)->loopRegions));
        $expire = true;
        try {
            $engine->eval("scan('ab')");
            self::fail('An expired budget entered a pure region');
        } catch (BudgetExceeded) {
        }
        $engine->setExecutionBudget(null);
        self::assertSame([2, 0], $engine->eval('[prefixes, swallowed]'));
        self::assertSame(2, $hooks);
    }

    private function compiled(Engine $engine): CompiledFunction
    {
        $function = $engine->getGlobalEnv()->get('scan');
        self::assertInstanceOf(\Phasis\Value\JsFunction::class, $function);
        self::assertNull($function->phpCompiled);
        self::assertInstanceOf(CompiledFunction::class, $function->compiled);
        return $function->compiled;
    }

    private function regionPc(CompiledFunction $compiled): int
    {
        for ($pc = 0; $pc < count($compiled->code); $pc += 1 + Op::operandCount($compiled->code[$pc])) {
            if ($compiled->code[$pc] === Op::STRING_LOOP_REGION) {
                return $pc;
            }
        }
        self::fail('The function did not emit a region entry');
    }

    /** @return array{StringLoopRegion, list<\Phasis\Value\JsValue>, array<int, int>, int} */
    private function prepare(CompiledFunction $compiled, string $text): array
    {
        $pc = $this->regionPc($compiled);
        $region = $compiled->loopRegions[$compiled->code[$pc + 1]];
        self::assertInstanceOf(StringLoopRegion::class, $region);
        $locals = array_fill(0, $compiled->slotCount, JsUndefined::instance());
        $locals[array_search('text', $compiled->localNames, true)] = new JsString($text);
        $locals[array_search('index', $compiled->localNames, true)] = JsNumber::of(0);
        for ($tick = $pc + 2; $tick < count($compiled->code); $tick += 1 + Op::operandCount($compiled->code[$tick])) {
            if ($compiled->code[$tick] === Op::LOOP_TICK) {
                return [$region, $locals, [], $compiled->code[$tick + 1]];
            }
        }
        self::fail('The region has no iteration checkpoint');
    }

    /** @param list<\Phasis\Value\JsValue> $locals @param array<int, int> $counts */
    private function execute(Engine $engine, StringLoopRegion $region, array &$locals, array &$counts): ?float
    {
        $previous = Engine::getCurrentInterpreter();
        Engine::setCurrentInterpreter($engine->getInterpreter());
        try {
            return ($region->execute)($locals, $engine->getInterpreter(), $counts);
        } finally {
            Engine::setCurrentInterpreter($previous);
        }
    }
}
