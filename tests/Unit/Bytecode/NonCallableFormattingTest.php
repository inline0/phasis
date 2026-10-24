<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Bytecode;

use Phasis\Bytecode\Op;
use Phasis\Engine;
use Phasis\Value\JsFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NonCallableFormattingTest extends TestCase
{
    /** @return iterable<string, array{string, int, bool, bool}> */
    public static function calls(): iterable
    {
        foreach ([false, true] as $ast) {
            foreach (
                [
                    'call' => ['return target(0);', Op::CALL, false],
                    'tail-call' => ["'use strict'; return target(0);", Op::TAIL_CALL, false],
                    'method' => ['return holder["method"](0);', Op::CALL_METHOD, false],
                    'tail-method' => ["'use strict'; return holder[\"method\"](0);", Op::TAIL_CALL_METHOD, false],
                    'constructor' => ['const ignored = new target(); return 0;', Op::NEW_CALL, false],
                    'arrow-constructor' => ['const ignored = new target(); return 0;', Op::NEW_CALL, true],
                ] as $name => [$body, $opcode, $arrow]
            ) {
                yield ($ast ? 'ast-' : 'vm-') . $name => [$body, $opcode, $arrow, $ast];
            }
        }
    }

    #[DataProvider('calls')]
    public function testRejectionDoesNotInvokePrimitiveConversionHooks(string $body, int $opcode, bool $arrow, bool $ast): void
    {
        $engine = new Engine();
        if ($ast) {
            $engine->setExecutionBudget(10);
        }
        $target = $arrow ? '() => {}' : '{}';
        self::assertSame([[true, 0, 0], [true, 0, 0]], $engine->eval('const target = ' . $target . ';' . <<<'JS'
let reads = 0, conversions = 0;
Object.defineProperty(target, Symbol.toPrimitive, {
    get() {
        reads++;
        return function () { conversions++; return 'target'; };
    }
});
const holder = { method: target };
JS
            . 'function invoke() {' . $body . '}' . <<<'JS'
const results = [];
for (let pass = 0; pass < 2; pass++) {
    let typeError = false;
    try { invoke(); } catch (error) { typeError = error instanceof TypeError; }
    results.push([typeError, reads, conversions]);
}
results;
JS));
        $function = $engine->getGlobalEnv()->get('invoke');
        self::assertInstanceOf(JsFunction::class, $function);
        self::assertNull($function->phpCompiled);
        if (!$ast) {
            self::assertNotNull($function->compiled);
            $opcodes = [];
            $code = $function->compiled->code;
            for ($pc = 0; $pc < count($code); $pc += Op::operandCount($code[$pc]) + 1) {
                $opcodes[] = $code[$pc];
            }
            self::assertContains($opcode, $opcodes);
        }
    }

    public function testPrivateMethodRejectionDoesNotInvokeConversionHooks(): void
    {
        foreach ([false, true] as $ast) {
            $engine = new Engine();
            if ($ast) {
                $engine->setExecutionBudget(10);
            }
            self::assertSame([true, 0, 0], $engine->eval(<<<'JS'
let reads = 0, conversions = 0;
const target = {};
Object.defineProperty(target, Symbol.toPrimitive, {
    get() {
        reads++;
        return function () { conversions++; return 'target'; };
    }
});
class Record {
    #method = target;
    invoke() { return this.#method(); }
}
let typeError = false;
try { new Record().invoke(); } catch (error) { typeError = error instanceof TypeError; }
[typeError, reads, conversions];
JS));
        }
    }
}
