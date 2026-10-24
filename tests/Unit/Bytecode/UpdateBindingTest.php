<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\Compiler;
use Phasis\Bytecode\Op;
use Phasis\Engine;
use Phasis\Parser\Parser;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\TestCase;

final class UpdateBindingTest extends TestCase
{
    public function testProgramCountersUseLiveGlobalBindings(): void
    {
        $source = <<<'JS'
var counter = 0;
var seen = [];
function observe() { seen.push(counter); }
for (var index = 0; index < 5; index++) { counter++; observe(); }
[counter++, ++counter, counter--, --counter, counter, seen];
JS;
        $compiled = (new Compiler())->compileProgram((new Parser($source))->parse());
        self::assertContains(Op::UPDATE_NAME, $compiled->code);
        self::assertSame([5, 7, 7, 5, 5, [1, 2, 3, 4, 5]], (new Engine())->eval($source));
    }

    public function testCompiledBindingUpdatesMatchTheNodeOracle(): void
    {
        $engine = $this->checkOracle(false);
        $names = [
            'postIncrement', 'preIncrement', 'postDecrement', 'preDecrement', 'strictIncrement',
            'unknownIncrement', 'updateCaptured', 'updateConstant', 'updateDeadZone',
        ];
        foreach ($names as $name) {
            $function = $engine->getGlobalEnv()->get($name);
            self::assertInstanceOf(JsFunction::class, $function);
            self::assertNotNull($function->compiled, $name . ' must exercise the bytecode path.');
            self::assertContains(Op::UPDATE_NAME, $function->compiled->code, $name);
        }
    }

    public function testInterpreterBindingUpdatesMatchTheSameNodeOracle(): void
    {
        $engine = $this->checkOracle(true);
        $function = $engine->getGlobalEnv()->get('postIncrement');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNull($function->compiled);
    }

    private function checkOracle(bool $interpreter): Engine
    {
        $engine = new Engine();
        if ($interpreter) {
            $engine->setExecutionBudget(10);
        }
        $engine->eval((string) file_get_contents(__DIR__ . '/fixtures/update-bindings.js'));
        self::assertJsonStringEqualsJsonFile(
            __DIR__ . '/fixtures/update-bindings.json',
            $engine->eval('JSON.stringify(updateBindingResult)'),
        );

        return $engine;
    }
}
