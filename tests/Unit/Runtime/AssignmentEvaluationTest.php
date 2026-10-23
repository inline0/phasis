<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

final class AssignmentEvaluationTest extends TestCase
{
    public function testStrictUnresolvableAssignmentEvaluatesRightSideBeforePutValue(): void
    {
        self::assertSame('[true,true,1,true,7]', (new Engine())->eval(<<<'JS'
            'use strict';
            const results = [];
            let effects = 0;
            try { absentNumber = NaN.toFixed(Infinity); }
            catch (error) { results.push(error instanceof RangeError); }
            try { (absentParenthesized) = (effects++, 1); }
            catch (error) { results.push(error instanceof ReferenceError); }
            results.push(effects);
            try { absentCreated = (globalThis.absentCreated = 7, 8); }
            catch (error) { results.push(error instanceof ReferenceError); }
            results.push(globalThis.absentCreated);
            JSON.stringify(results);
            JS));
    }

    public function testUnresolvableCompoundAssignmentDoesNotEvaluateRightSide(): void
    {
        self::assertSame('[true,true,true,0]', (new Engine())->eval(<<<'JS'
            'use strict';
            let effects = 0;
            const results = [];
            try { absentAdd += (effects++, 1); }
            catch (error) { results.push(error instanceof ReferenceError); }
            try { absentOr ||= (effects++, 1); }
            catch (error) { results.push(error instanceof ReferenceError); }
            try { absentNull ??= (effects++, 1); }
            catch (error) { results.push(error instanceof ReferenceError); }
            results.push(effects);
            JSON.stringify(results);
            JS));
    }
}
