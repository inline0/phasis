<?php

declare(strict_types=1);

namespace Phasis\Runtime;

use Phasis\Exceptions\InternalError;

/**
 * Call-stack bookkeeping for stack-overflow detection and Error.stack
 * trace materialisation.
 *
 * Stores frame metadata in parallel int / string arrays rather than as
 * CallFrame objects. The hot push/pop paths (one each per JS call) skip
 * the CallFrame allocation entirely; getFrames() materialises objects
 * on demand, which only matters when an Error is actually constructed.
 */
class CallStack
{
    /** @var list<string> Frame names parallel to $lines and $depth. */
    private array $names = [];
    /** @var list<int> */
    private array $lines = [];
    private int $depth = 0;

    public function __construct(
        // The VM's custom-callstack inline path keeps the PHP stack
        // at a single frame across JS calls (`Op::CALL` / `RET` swap
        // state inside `VM::execute` instead of recursing into PHP),
        // so the engine's own CallStack limit is the only ceiling
        // for inlined recursion.
        //
        // 4096 is the sweet spot: well above legitimate JS recursion
        // depth (every test262 test we've seen needs <2k frames) and
        // low enough that pathological infinite recursion like
        // staging/sm/extensions/recursion.js (`function eval(){eval();}`)
        // fails fast instead of grinding through tens of thousands of
        // frames under the test runner's per-shard timeout. The
        // earlier 65536 number was set assuming "deeper is more
        // V8-like," but in practice test262 only tests THAT a limit
        // exists, not how high — and the high limit cost us 12 tests
        // when pathological cases stopped failing fast enough.
        private readonly int $maxDepth = 4096,
    ) {
    }

    /** Push a new frame onto the call stack. Throws if the stack depth limit is exceeded. */
    public function push(string $name, int $line): void
    {
        if ($this->depth >= $this->maxDepth) {
            throw new InternalError('Maximum call stack size exceeded');
        }
        $this->names[$this->depth] = $name;
        $this->lines[$this->depth] = $line;
        $this->depth++;
    }

    public function pop(): void
    {
        $this->depth--;
    }

    public function depth(): int
    {
        return $this->depth;
    }

    /** @return list<CallFrame> */
    public function getFrames(): array
    {
        $result = [];
        for ($i = 0; $i < $this->depth; $i++) {
            $result[] = new CallFrame($this->names[$i], $this->lines[$i]);
        }
        return $result;
    }
}
