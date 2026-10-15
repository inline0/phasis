<?php

declare(strict_types=1);

namespace Phasis\Tests\Unit\Runtime;

use Phasis\Engine;
use PHPUnit\Framework\TestCase;

/**
 * A call in a try Block is never in tail position (HasCallInTailPosition),
 * so strict code must still catch its exception. React's server renderer
 * depends on this: renderNode is `try { return renderNodeDestructive(...) }
 * catch (x) { ... }`.
 */
final class TailCallInTryTest extends TestCase
{
    public function testCatchSeesTheReturnedCallsException(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
            'use strict';
            function thrower() { throw new Error('x'); }
            function viaCatch() { try { return thrower(); } catch (e) { return 'caught ' + e.message; } }
            function viaFinally() { try { return thrower(); } finally { console.log('finally ran'); } }
            console.log(viaCatch());
            try { viaFinally(); } catch (e) { console.log('outer'); }
            JS);
        $this->assertSame("caught x\nfinally ran\nouter", $engine->getConsoleOutput());
    }

    public function testCatchBlockKeepsProperTailCalls(): void
    {
        $engine = new Engine();
        $engine->eval(<<<'JS'
            'use strict';
            function count(n) { try { throw null; } catch (e) { return n === 0 ? 'done' : count(n - 1); } }
            console.log(count(20000));
            JS);
        $this->assertSame('done', $engine->getConsoleOutput());
    }
}
