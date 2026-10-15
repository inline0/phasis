<?php

declare(strict_types=1);

namespace Phasis\Runtime;

/**
 * The deadline an embedder set with Engine::setExecutionBudget(), shared by
 * every interpreter the evaluation starts, so a run past it is seen by all.
 */
final class ExecutionBudget
{
    /** Whether evaluation has run past the deadline, which stays true once it has. */
    public bool $exceeded = false;

    public function __construct(
        /** The hrtime, in nanoseconds, past which evaluation stops. */
        public readonly int $deadline,
    ) {
    }
}
