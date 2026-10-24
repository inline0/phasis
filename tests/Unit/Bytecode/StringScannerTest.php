<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\Bailout;
use Phasis\Bytecode\JsToPhp;
use Phasis\Engine;
use Phasis\Exceptions\BudgetExceeded;
use Phasis\Exceptions\InternalError;
use Phasis\Parser\Parser;
use Phasis\Runtime\CallStack;
use Phasis\Runtime\Interpreter;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use PHPUnit\Framework\TestCase;

final class StringScannerTest extends TestCase
{
    private const SOURCE = <<<'JS'
let separator = code => code === 32 || code === 9 || code === 10 || code === 13;
function forward(text, offset) {
    while (offset < text.length) {
        if (!separator(text.charCodeAt(offset))) break;
        offset++;
    }
    return offset;
}
function reverse(text, offset) {
    while (offset > 0) {
        if (!separator(text.charCodeAt(offset - 1))) break;
        offset--;
    }
    return offset;
}
forward(' x ', 0);
reverse(' x ', 3);
JS;

    public function testForwardAndReverseScansUseCompiledBodies(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        $forward = $this->compiledFunction($engine, 'forward');
        $reverse = $this->compiledFunction($engine, 'reverse');
        self::assertNull($forward->phpCompiledNumeric);
        foreach ([['x', 0.0, 0.0], [" \t\nx", 0.0, 3.0], ['', 0.0, 0.0], ['  x', -0.5, 2.5]] as [$text, $offset, $expected]) {
            self::assertSame($expected, $this->runCompiled($engine, $forward, [new JsString($text), JsNumber::of($offset)])->value);
        }
        self::assertSame(1.0, $this->runCompiled($engine, $reverse, [new JsString("x \t\n"), JsNumber::of(4)])->value);
        self::assertTrue(is_nan($this->runCompiled($engine, $forward, [new JsString(' '), JsNumber::of(NAN)])->value));
        self::assertSame(INF, $this->runCompiled($engine, $forward, [new JsString(' '), JsNumber::of(INF)])->value);
        self::assertSame(-INF, $this->runCompiled($engine, $forward, [new JsString(' '), JsNumber::of(-INF)])->value);
    }

    public function testNumericLocalsAndPurePredicateArgumentsKeepEvaluationOrder(): void
    {
        $engine = new Engine();
        self::assertSame(9, $engine->eval(<<<'JS'
const pair = (left, right) => left === 97 && right === 98;
function count(text, offset) {
    let total = 0;
    for (let i = offset; i + 1 < text.length; i += 2) {
        const code = text.charCodeAt(i);
        if (pair(code, text.charCodeAt(i + 1))) total += i + 1;
    }
    return total;
}
count('ababab', 0);
JS));
        $function = $this->compiledFunction($engine, 'count');
        self::assertSame(9.0, $this->runCompiled($engine, $function, [new JsString('ababab'), JsNumber::of(0)])->value);
    }

    public function testNonAsciiAndObjectArgumentsFallBackWithoutReplayingEffects(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        self::assertSame([1, 1, [0, 1]], $engine->eval(<<<'JS'
const calls = [];
const object = { length: 2, charCodeAt(index) { calls.push(index); return index === 0 ? 32 : 120; } };
[forward(' é😀', 0), forward(object, 0), calls];
JS));
        $this->expectException(Bailout::class);
        $this->runCompiled($engine, $this->compiledFunction($engine, 'forward'), [new JsString('é'), JsNumber::of(0)]);
    }

    public function testChangedPredicateAndMethodGettersAreObservedOnce(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        self::assertSame([1, 1, [32, 120], 2, true], $engine->eval(<<<'JS'
const calls = [];
separator = function (code) { calls.push(code); return code === 32; };
const first = forward(' x', 0);
const original = String.prototype.charCodeAt;
let getters = 0;
Object.defineProperty(String.prototype, 'charCodeAt', { configurable: true, get() { getters++; return original; } });
const empty = forward('', 0);
const second = forward(' x', 0);
Object.defineProperty(String.prototype, 'charCodeAt', { value: original, writable: true, configurable: true });
[first, second, calls.slice(0, 2), getters, empty === 0];
JS));
    }

    public function testGetterErrorsKeepIdentityAndEarlyReturnsDoNotReadTheMethod(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        self::assertSame([0, true, 1], $engine->eval(<<<'JS'
const original = String.prototype.charCodeAt;
const sentinel = {};
let accesses = 0;
Object.defineProperty(String.prototype, 'charCodeAt', { configurable: true, get() { accesses++; throw sentinel; } });
const empty = forward('', 0);
let same = false;
try { forward(' ', 0); } catch (error) { same = error === sentinel; }
Object.defineProperty(String.prototype, 'charCodeAt', { value: original, configurable: true, writable: true });
[empty, same, accesses];
JS));
    }

    public function testFactorySpecializationsDoNotSharePredicateOrEnvironmentState(): void
    {
        $engine = new Engine();
        self::assertSame([1, 1, 2], $engine->eval(<<<'JS'
function factory(test) {
    return function scan(text, offset) {
        while (offset < text.length) {
            if (!test(text.charCodeAt(offset))) break;
            offset++;
        }
        return offset;
    };
}
const first = factory(code => code === 32);
const second = factory(code => code === 9);
let effects = 0;
const rejected = factory(code => { effects++; return code === 32; });
rejected(' x', 0);
[first(' x', 0), second('\tx', 0), effects];
JS));
        $first = $this->compiledFunction($engine, 'first');
        $second = $this->compiledFunction($engine, 'second');
        self::assertSame(1.0, $this->runCompiled($engine, $first, [new JsString(' x'), JsNumber::of(0)])->value);
        self::assertSame(0.0, $this->runCompiled($engine, $second, [new JsString(' x'), JsNumber::of(0)])->value);
        self::assertSame(2, $engine->eval('effects'));
    }

    public function testHostBindingReplacementInvalidatesAnAlreadyCompiledPredicate(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        $function = $this->compiledFunction($engine, 'forward');
        $engine->eval('const replacement = code => code === 120;');
        $replacement = $this->rawFunction($engine, 'replacement');
        $this->runCompiled($engine, $function, [new JsString(' x'), JsNumber::of(0)]);
        $function->getClosure()->defineDeletable('separator', $replacement);
        $this->expectException(Bailout::class);
        $this->runCompiled($engine, $function, [new JsString(' x'), JsNumber::of(0)]);
    }

    public function testForeignCompiledEntryBailsAndFreshEnginesKeepTheirOwnIntrinsics(): void
    {
        $first = new Engine();
        $first->eval(self::SOURCE);
        $second = new Engine();
        $second->eval(self::SOURCE);
        $foreign = $this->compiledFunction($first, 'forward');
        try {
            $this->runCompiled($second, $foreign, [new JsString(' x'), JsNumber::of(0)]);
            self::fail('A compiled scanner crossed an interpreter realm');
        } catch (Bailout) {
        }
        self::assertSame(0, $second->eval("String.prototype.charCodeAt = () => 120; forward(' x', 0)"));
        self::assertSame(1.0, $this->runCompiled($first, $this->compiledFunction($first, 'forward'), [new JsString(' x'), JsNumber::of(0)])->value);
    }

    public function testSharedSyntaxFactoriesKeepIndependentRealmGuards(): void
    {
        $first = new Engine();
        $first->eval(self::SOURCE);
        $second = new Engine();
        $second->eval(self::SOURCE);
        $program = (new Parser(<<<'JS'
function scan(text, offset) {
    while (offset < text.length) {
        if (text.charCodeAt(offset) !== 32) break;
        offset++;
    }
    return offset;
}
JS))->parse();
        $declaration = $program->body[0];
        self::assertInstanceOf(\Phasis\Ast\Declaration\FunctionDeclaration::class, $declaration);
        $functions = [];
        foreach ([$first, $second] as $engine) {
            $function = new JsFunction('scan', $declaration->params, $declaration->body, $engine->getGlobalEnv()->createChild());
            $function->realm = $engine;
            $function->phpCompiled = JsToPhp::compile($function);
            self::assertNotNull($function->phpCompiled);
            self::assertSame(1.0, $this->runCompiled($engine, $function, [new JsString(' x'), JsNumber::of(0)])->value);
            $functions[] = $function;
        }
        $this->expectException(Bailout::class);
        $this->runCompiled($second, $functions[0], [new JsString(' x'), JsNumber::of(0)]);
    }

    public function testSameRealmModuleInterpretersUseTheCurrentIntrinsicCacheOwner(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        $function = $this->compiledFunction($engine, 'forward');
        $module = new Interpreter($engine->getGlobalEnv());
        $module->setRealm($engine);
        $previous = Engine::getCurrentInterpreter();
        try {
            Engine::setCurrentInterpreter($module);
            self::assertFalse($module->hasCachedStringIntrinsic('charCodeAt', 'string.charCodeAt'));
            Engine::setCurrentInterpreter($engine->getInterpreter());
            self::assertTrue($module->hasCachedStringIntrinsic('charCodeAt', 'string.charCodeAt'));
            $result = ($function->phpCompiled)(
                [new JsString(' x'), JsNumber::of(0)],
                JsUndefined::instance(),
                $function->getClosure(),
                $module,
                [],
            );
            self::assertInstanceOf(JsNumber::class, $result);
            self::assertSame(1.0, $result->value);
        } finally {
            Engine::setCurrentInterpreter($previous);
        }
    }

    public function testLoopLimitAndActivatedBudgetRemainEffective(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        $engine->setLimit('maxLoopIterations', 2);
        try {
            $this->runCompiled($engine, $this->compiledFunction($engine, 'forward'), [new JsString('    x'), JsNumber::of(0)]);
            self::fail('The scanner ignored the loop limit');
        } catch (InternalError $error) {
            self::assertStringContainsString('Maximum loop iterations', $error->getMessage());
        }
        $engine->setExecutionBudget(-1);
        $this->expectException(BudgetExceeded::class);
        $engine->eval("forward(' x', 0)");
    }

    public function testPurePredicateDoesNotBypassTheCallDepthLimit(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE);
        $function = $this->compiledFunction($engine, 'forward');
        $property = new \ReflectionProperty($engine->getInterpreter(), 'callStack');
        $stack = new CallStack(0);
        $property->setValue($engine->getInterpreter(), $stack);
        $this->expectException(Bailout::class);
        $this->runCompiled($engine, $function, [new JsString(' '), JsNumber::of(0)]);
    }

    public function testColdAndCachedScannerCallsRespectThePredicateDepthBoundary(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
const check = code => code === 32;
function scan(text, offset) {
    while (offset < text.length) {
        if (!check(text.charCodeAt(offset))) break;
        offset++;
    }
    return offset;
}
JS);
        $property = new \ReflectionProperty($engine->getInterpreter(), 'callStack');
        $property->setValue($engine->getInterpreter(), new CallStack(1));
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $engine->eval("scan(' ', 0)");
                self::fail('An inlined predicate bypassed the call depth boundary');
            } catch (InternalError $error) {
                self::assertStringContainsString('Maximum call stack size', $error->getMessage());
            }
            self::assertNull($this->rawFunction($engine, 'check')->phpCompiled);
            self::assertNotNull($this->rawFunction($engine, 'scan')->phpCompiled);
        }
    }

    public function testCachedScannerCodeDoesNotRetainSyntaxOrLexicalEnvironments(): void
    {
        $engine = new Engine();
        $program = (new Parser(<<<'JS'
function check(value) { return value === 32; }
function scan(text, index) {
    while (index < text.length) {
        if (!check(text.charCodeAt(index))) break;
        index++;
    }
    return index;
}
JS))->parse();
        $scope = $engine->getGlobalEnv()->createChild();
        $predicate = $program->body[0];
        $scanner = $program->body[1];
        self::assertInstanceOf(\Phasis\Ast\Declaration\FunctionDeclaration::class, $predicate);
        self::assertInstanceOf(\Phasis\Ast\Declaration\FunctionDeclaration::class, $scanner);
        $check = new JsFunction('check', $predicate->params, $predicate->body, $scope);
        $check->realm = $engine;
        $scope->defineVar('check', $check);
        $function = new JsFunction('scan', $scanner->params, $scanner->body, $scope);
        $function->realm = $engine;
        $bodyReference = \WeakReference::create($scanner->body);
        $scopeReference = \WeakReference::create($scope);
        $predicateReference = \WeakReference::create($check);
        $compiled = JsToPhp::compile($function);
        self::assertNotNull($compiled);
        unset($program, $predicate, $scanner, $scope, $check, $function);
        gc_collect_cycles();
        self::assertNull($bodyReference->get());
        self::assertNull($scopeReference->get());
        self::assertNull($predicateReference->get());
    }

    public function testUnsupportedCompoundOperatorsKeepJavaScriptNumberSemantics(): void
    {
        $engine = new Engine();
        self::assertSame([1.5, 'Infinity'], $engine->eval(<<<'JS'
function remainder(text, offset) {
    let result = 5.5;
    while (offset < text.length) { result %= 2; offset++; }
    return result;
}
function division(text, offset) {
    let result = 1;
    while (offset < text.length) { result /= 0; offset++; }
    return result;
}
[remainder('x', 0), String(division('x', 0))];
JS));
        self::assertNull($this->rawFunction($engine, 'remainder')->phpCompiled);
        self::assertNull($this->rawFunction($engine, 'division')->phpCompiled);
    }

    public function testEscapingLexicalWritesAreRejectedBeforeExecution(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
function scan(text, offset) {
    'use strict';
    { let escaped = 1; }
    escaped = 2;
    while (offset < text.length) offset++;
    return offset;
}
JS);
        self::assertNull(JsToPhp::compile($this->rawFunction($engine, 'scan')));
    }

    public function testUnprovenReadsAndEffectsRemainOnTheVm(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
let calls = 0;
let external = 0;
function effect(text, offset) { while (offset < text.length) { calls++; offset++; } return offset; }
function uninitialized(text, offset) { let n; while (offset < text.length) { offset++; } return n; }
function mutation(text, offset) { while (offset < text.length) { external = text.charCodeAt(offset); offset++; } return offset; }
JS);
        foreach (['effect', 'uninitialized', 'mutation'] as $name) {
            self::assertNull(JsToPhp::compile($this->rawFunction($engine, $name)));
        }
        self::assertSame([0, 0], $engine->eval('[calls, external]'));
    }

    private function rawFunction(Engine $engine, string $name): JsFunction
    {
        $function = $engine->getGlobalEnv()->get($name);
        self::assertInstanceOf(JsFunction::class, $function);
        return $function;
    }

    private function compiledFunction(Engine $engine, string $name): JsFunction
    {
        $function = $this->rawFunction($engine, $name);
        self::assertNotNull($function->phpCompiled, $name . ' did not compile');
        return $function;
    }

    public function testReplacedIntrinsicGettersAndCallbacksObserveTheTrackedScanner(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE . "forward(' ', 0);");
        self::assertSame([1, true, true, 1], $engine->eval(<<<'JS'
const originalMethod = String.prototype.charCodeAt;
let getterCaller = false, callbackCaller = false, reads = 0;
function replacement(index) {
    callbackCaller = replacement.caller === forward;
    return originalMethod.call(this, index);
}
function methodGetter() {
    reads++;
    getterCaller = methodGetter.caller === forward;
    return replacement;
}
Object.defineProperty(String.prototype, 'charCodeAt', { get: methodGetter, configurable: true });
const result = forward(' ', 0);
Object.defineProperty(String.prototype, 'charCodeAt', {
    value: originalMethod, writable: true, configurable: true
});
[result, getterCaller, callbackCaller, reads];
JS));
    }

    public function testHostStringOverridesAreNotInvokedByCompiledGuards(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE . "forward(' ', 0);");
        $value = new class (' ') extends JsString {
            public int $reads = 0;

            public function isAscii(): bool
            {
                ++$this->reads;
                return true;
            }
        };
        try {
            $this->runCompiled($engine, $this->compiledFunction($engine, 'forward'), [$value, JsNumber::of(0)]);
            self::fail('A host string subclass must stay outside the scanner.');
        } catch (Bailout) {
            self::assertSame(0, $value->reads);
        }
    }

    public function testCachedPredicateClosureReplacementDeclinesBeforeHostMetadata(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE . "forward(' ', 0);");
        $predicate = $engine->getGlobalEnv()->get('separator');
        self::assertInstanceOf(JsFunction::class, $predicate);
        $scope = new class ($engine->getGlobalEnv()) extends \Phasis\Runtime\Environment {
            public int $lookups = 0;

            public function findBindingDepth(string $name): ?array
            {
                ++$this->lookups;
                return parent::findBindingDepth($name);
            }
        };
        $predicate->closure = $scope->createChild();
        try {
            $this->runCompiled($engine, $this->compiledFunction($engine, 'forward'), [new JsString(' '), JsNumber::of(0)]);
            self::fail('A changed predicate host scope must not be inlined.');
        } catch (Bailout) {
            self::assertSame(0, $scope->lookups);
        }
    }

    public function testHostIntrinsicFunctionOverridesAreNotInvokedByPreflight(): void
    {
        $engine = new Engine();
        $engine->eval(self::SOURCE . "forward(' ', 0);");
        $prototype = $engine->getGlobalEnv()->get('String')->get('prototype');
        $original = $prototype->get('charCodeAt');
        $host = new class ('charCodeAt', [], null, $engine->getGlobalEnv()) extends JsFunction {
            public int $reads = 0;

            public function getNativeCallable(): ?\Closure
            {
                ++$this->reads;
                return static fn (): JsNumber => JsNumber::of(32);
            }
        };
        $host->builtinKind = 'string.charCodeAt';
        $previous = Engine::getCurrentInterpreter();
        Engine::setCurrentInterpreter($engine->getInterpreter());
        try {
            $prototype->defineOwnProperty('charCodeAt', \Phasis\Object\PropertyDescriptor::data($host, true, false, true));
            self::assertFalse($engine->getInterpreter()->hasCachedStringIntrinsic('charCodeAt', 'string.charCodeAt'));
            self::assertSame(0, $host->reads);
        } finally {
            $prototype->defineOwnProperty('charCodeAt', \Phasis\Object\PropertyDescriptor::data($original, true, false, true));
            Engine::setCurrentInterpreter($previous);
        }
    }

    /** @param list<JsValue> $arguments */
    private function runCompiled(Engine $engine, JsFunction $function, array $arguments): JsNumber
    {
        $previous = Engine::getCurrentInterpreter();
        Engine::setCurrentInterpreter($engine->getInterpreter());
        try {
            $result = ($function->phpCompiled)($arguments, JsUndefined::instance(), $function->getClosure(), $engine->getInterpreter(), []);
            self::assertInstanceOf(JsNumber::class, $result);
            return $result;
        } finally {
            Engine::setCurrentInterpreter($previous);
        }
    }
}
