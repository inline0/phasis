<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class DefaultBindingTest extends TestCase
{
    public function testCompiledDefaultsRunOnceAndUseUndefinedRatherThanNullishSemantics(): void
    {
        $engine = new Engine();
        self::assertSame([[1, 3, 1], [null, 2, 1], [4, 6, 1], [2, 4, 2]], $engine->eval(<<<'JS'
let defaults = 0;
function nextDefault() { defaults++; return defaults; }
function options(value = {}) {
  const { a = nextDefault(), b = a + 2 } = value;
  return [a, b, defaults];
}
[options(), options({a: null}), options({a: 4}), options({a: undefined})];
JS));
        $function = $engine->getGlobalEnv()->get('options');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNotNull($function->compiled, 'This regression must exercise the bytecode path.');
    }

    public function testParameterClosuresObserveAssignmentButNotBodyVarRedeclaration(): void
    {
        $engine = new Engine();
        self::assertSame([2, 1, 2, 1, 3], $engine->eval(<<<'JS'
function shared(a = 1, read = () => a) { a = 2; return read(); }
function shadowed(a = 1, read = () => a) { var a = 2; return read(); }
function* generator(a = 1, read = () => a) { a = 2; yield read(); }
function* shadowedGenerator(a = 1, read = () => a) { var a = 2; yield read(); }
function argumentBinding(read = () => arguments) { arguments = 3; return read(); }
[shared(), shadowed(), generator().next().value, shadowedGenerator().next().value, argumentBinding()];
JS));
    }

    public function testWarmDefaultsKeepThisArgumentsAndNewTargetBindings(): void
    {
        $engine = new Engine();
        self::assertSame([4, 5, 8, 9, true, true], $engine->eval(<<<'JS'
function receiver(value = this.value) { return value; }
function argument(value = arguments[1]) { return value; }
function constructor(value = new.target) { return { value }; }
[receiver.call({value: 4}), receiver.call({value: 5}), argument(undefined, 8), argument(undefined, 9),
 new constructor().value === constructor, new constructor().value === constructor];
JS));
    }

    public function testDefaultTemporalDeadZonesAndAnonymousNamesRemainObservable(): void
    {
        $engine = new Engine();
        self::assertSame(['ReferenceError', 'ReferenceError', 'ReferenceError', 'named', 'named'], $engine->eval(<<<'JS'
function parameter(x = x) { return x; }
function selfReference() { const {x = x} = {}; return x; }
function forwardReference() { const {x = y, y = 1} = {}; return x; }
function inferred() { const {named = function () {}} = {}; return named.name; }
const results = [];
for (const fn of [parameter, selfReference, forwardReference]) {
  try { fn(); } catch (error) { results.push(error.name); }
}
results.push(inferred(), inferred());
results;
JS));
    }
}
