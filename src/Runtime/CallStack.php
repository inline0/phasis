<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Exceptions\InternalError;

class CallStack
{
    /** @var list<CallFrame> */
    private array $frames = [];

    public function __construct(
        private readonly int $maxDepth = 100,
    ) {
    }

    /** Push a new frame onto the call stack. Throws if the stack depth limit is exceeded. */
    public function push(string $name, int $line): void
    {
        if (count($this->frames) >= $this->maxDepth) {
            throw new InternalError('Maximum call stack size exceeded');
        }

        $this->frames[] = new CallFrame($name, $line);
    }

    public function pop(): void
    {
        array_pop($this->frames);
    }

    public function depth(): int
    {
        return count($this->frames);
    }

    /** @return list<CallFrame> */
    public function getFrames(): array
    {
        return $this->frames;
    }
}
