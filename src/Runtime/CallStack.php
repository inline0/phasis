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
        // state inside `VM::execute` instead of recursing into PHP).
        // So the engine's own CallStack limit is the only ceiling
        // for inlined recursion. 65536 puts us comfortably above
        // V8 / SpiderMonkey practical stack-overflow thresholds
        // (~10–25 k frames) while still bounding pathological
        // runaway recursion in finite time. Non-inlined calls
        // remain bounded by PHP's actual recursion depth, which
        // typically tops out at tens of thousands on default
        // builds — so the higher value is safe there too.
        private readonly int $maxDepth = 65536,
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
