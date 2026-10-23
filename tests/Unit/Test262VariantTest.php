<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit;

use Phasis\Tests\Oracle\Test262Runner;
use Phasis\Tests\Oracle\TestResult;
use Phasis\Tests\Oracle\TestStatus;
use PHPUnit\Framework\TestCase;

final class Test262VariantTest extends TestCase
{
    public function testAnUnflaggedScriptMustPassItsStrictVariant(): void
    {
        $result = $this->runFixture(<<<'JS'
            /*---
            description: Validate both script variants.
            ---*/
            if ((function () { return this; })() === undefined) {
                throw new Error('strict variant reached');
            }
            JS);

        self::assertSame(TestStatus::Fail, $result->status);
        self::assertStringContainsString('strict variant reached', $result->message);
    }

    public function testNoStrictFlagSelectsOnlyTheSloppyVariant(): void
    {
        $result = $this->runFixture(<<<'JS'
            /*---
            flags: [noStrict]
            ---*/
            if ((function () { return this; })() === undefined) {
                throw new Error('unexpected strict execution');
            }
            JS);

        self::assertSame(TestStatus::Pass, $result->status, $result->message);
    }

    public function testRawSourceRetainsCommentsAndDoesNotLoadTheHarness(): void
    {
        $result = $this->runFixture(<<<'JS'
            /*---
            flags: [raw]
            ---*/
            function marker() { /*--- retained ---*/ }
            var expected = '/*' + '--- retained ---' + '*/';
            if (!marker.toString().includes(expected)) throw new Error('raw source was changed');
            if (typeof assert !== 'undefined') throw new Error('raw harness was loaded');
            if ((function () { return this; })() === undefined) throw new Error('raw source became strict');
            JS);

        self::assertSame(TestStatus::Pass, $result->status, $result->message);
    }

    public function testAsyncTestsMustSignalCompletion(): void
    {
        $result = $this->runFixture(<<<'JS'
            /*---
            flags: [async]
            ---*/
            Promise.resolve();
            JS);

        self::assertSame(TestStatus::Fail, $result->status);
        self::assertStringContainsString('did not signal completion', $result->message);
    }

    public function testRawModulesKeepTheirModuleParseGoalWithoutHarness(): void
    {
        $result = $this->runFixture(<<<'JS'
            /*---
            flags: [module, raw]
            ---*/
            export const value = 1;
            if (typeof assert !== 'undefined') throw new Error('raw harness was loaded');
            if (this !== undefined) throw new Error('module has script this binding');
            JS);

        self::assertSame(TestStatus::Pass, $result->status, $result->message);
    }

    public function testAsyncCompletionUsesTheOfficialHarness(): void
    {
        $result = $this->runFixture(<<<'JS'
            /*---
            flags: [async]
            ---*/
            Promise.resolve().then(function () { $DONE(); });
            JS);

        self::assertSame(TestStatus::Pass, $result->status, $result->message);
    }

    public function testAsyncFailureCannotSatisfyANegativeExpectation(): void
    {
        $result = $this->runFixture(<<<'JS'
            /*---
            flags: [async]
            negative:
              phase: runtime
              type: TypeError
            ---*/
            $DONE(new TypeError('test callback failed'));
            JS);

        self::assertSame(TestStatus::Fail, $result->status);
        self::assertStringContainsString('test callback failed', $result->message);
    }

    public function testRuntimeErrorsCannotSatisfyParseOrResolutionExpectations(): void
    {
        foreach (['parse' => '', 'resolution' => 'flags: [module]'] as $phase => $flags) {
            $result = $this->runFixture("/*---\n{$flags}\nnegative:\n  phase: {$phase}\n  type: SyntaxError\n---*/\n"
                . "throw new SyntaxError('runtime');");
            self::assertSame(TestStatus::Fail, $result->status, $phase);
        }
    }

    public function testAThrownExpectedErrorCannotEraseAnAsyncFailure(): void
    {
        $result = $this->runFixture(<<<'JS'
            /*---
            flags: [async]
            negative:
              phase: runtime
              type: TypeError
            ---*/
            $DONE(new Error('callback failed first'));
            throw new TypeError('expected runtime error');
            JS);

        self::assertSame(TestStatus::Fail, $result->status);
        self::assertStringContainsString('callback failed first', $result->message);
    }

    public function testParseErrorsCannotSatisfyRuntimeExpectations(): void
    {
        $result = $this->runFixture(<<<'JS'
            /*---
            negative:
              phase: runtime
              type: SyntaxError
            ---*/
            const = ;
            JS);

        self::assertSame(TestStatus::Fail, $result->status);
    }

    public function testNegativeErrorsMatchConstructorNamesExactly(): void
    {
        $result = $this->runFixture(<<<'JS'
            /*---
            negative:
              phase: runtime
              type: Error
            ---*/
            const error = new TypeError('wrong constructor');
            error.name = 'Error';
            throw error;
            JS);

        self::assertSame(TestStatus::Fail, $result->status);
    }

    private function runFixture(string $source): TestResult
    {
        $path = tempnam(sys_get_temp_dir(), 'phasis-variants-');
        if ($path === false) {
            self::fail('Cannot create temporary test fixture');
        }
        try {
            file_put_contents($path, $source);
            return (new Test262Runner(dirname(__DIR__, 2) . '/test262'))->run($path);
        } finally {
            unlink($path);
        }
    }
}
