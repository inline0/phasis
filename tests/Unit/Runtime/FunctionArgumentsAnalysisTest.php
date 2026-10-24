<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class FunctionArgumentsAnalysisTest extends TestCase
{
    public function testRepeatedClosuresKeepLexicalArrowAndOwnFunctionArgumentsSeparate(): void
    {
        $engine = new Engine();
        self::assertSame('first:second:inner', $engine->eval(<<<'JS'
function factory() {
  return function capture() {
    return [() => arguments[0], function own() { return arguments[0]; }];
  };
}
const first = factory()('first');
const second = factory()('second');
[first[0](), second[0](), second[1]('inner')].join(':');
JS));
    }

    public function testRepeatedDefaultParametersAndEvalCanStillObserveArguments(): void
    {
        $engine = new Engine();
        self::assertSame('two:3:4:undefined', $engine->eval(<<<'JS'
function factory() {
  return function capture(value, previous = arguments[0]) {
    return previous + ':' + eval('arguments[2]');
  };
}
[factory()('one', 'two', 3), factory()(4)].join(':');
JS));
    }
}
