<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Exceptions\InternalError;

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
        // 1024 matches what Node.js exposes via stack-overflow tests well
        // enough — deep super() chains in test262 (built up to ~100 levels)
        // need to clear the engine's bookkeeping plus PHP's own recursion
        // overhead, and 100 was tripping common patterns. PHP's xdebug or
        // ini max_execution_time / memory_limit bound runaway recursion
        // independently.
        private readonly int $maxDepth = 1024,
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
