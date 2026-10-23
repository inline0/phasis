<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AsyncReturnCallTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function executionModes(): iterable
    {
        yield 'normal' => [false];
        yield 'budgeted interpreter' => [true];
    }

    #[DataProvider('executionModes')]
    public function testAsyncReturnsEvaluateCallsBeforeSettlingTheirPromises(bool $budgeted): void
    {
        $engine = $this->engine($budgeted);
        $engine->eval(<<<'JS'
'use strict';
var calls = [];
var result;
function create(name) { calls.push(name); return { body: name }; }
async function immediate() { return create('immediate'); }
async function suspended() { await 0; return create('suspended'); }
async function nested() { return immediate(); }
var arrow = async () => { return create('arrow'); };
var object = { async method() { return create('method'); } };
Promise.all([immediate(), suspended(), nested(), arrow(), object.method()])
    .then(values => { result = values.map(value => value.body); });
JS);

        self::assertSame(
            ['immediate', 'suspended', 'immediate', 'arrow', 'method'],
            $engine->eval('result;'),
        );
        self::assertSame(
            ['immediate', 'immediate', 'arrow', 'method', 'suspended'],
            $engine->eval('calls;'),
        );
    }

    #[DataProvider('executionModes')]
    public function testAsyncReturnedCallFailuresRejectTheOuterPromise(bool $budgeted): void
    {
        $engine = $this->engine($budgeted);
        $engine->eval(<<<'JS'
'use strict';
var result;
var expected = new Error('called');
function fail() { throw expected; }
async function immediate() { return fail(); }
async function suspended() { await 0; return fail(); }
Promise.all([immediate().catch(error => error === expected), suspended().catch(error => error === expected)])
    .then(values => { result = values; });
JS);

        self::assertSame([true, true], $engine->eval('result;'));
    }

    #[DataProvider('executionModes')]
    public function testGeneratorReturnCallsBecomeIteratorCompletionValues(bool $budgeted): void
    {
        $engine = $this->engine($budgeted);
        $engine->eval(<<<'JS'
'use strict';
var asyncResult;
function create() { return { body: 'done' }; }
function* syncGenerator() { yield 'start'; return create(); }
async function* asyncGenerator() { yield 'start'; return create(); }
var sync = syncGenerator();
var first = sync.next();
var last = sync.next();
var async = asyncGenerator();
async.next().then(() => async.next()).then(value => { asyncResult = [value.done, value.value.body]; });
JS);

        self::assertSame([false, 'start', true, 'done'], $engine->eval('[first.done, first.value, last.done, last.value.body];'));
        self::assertSame([true, 'done'], $engine->eval('asyncResult;'));
    }

    private function engine(bool $budgeted): Engine
    {
        $engine = new Engine();
        if ($budgeted) {
            $engine->setExecutionBudget(5);
        }
        return $engine;
    }
}
