<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

class ForStatement extends Node
{
    /**
     * Memoised: true when body / update / test contains any closure
     * (function/arrow/class expression) that could capture a per-
     * iteration let/const binding. When false, the for-loop runner
     * skips the per-iteration env clone since no closure can observe
     * the binding's iteration identity.
     */
    public ?bool $bodyHasClosure = null;

    public function __construct(
        SourceLocation $location,
        public readonly ?Node $init,
        public readonly ?Node $test,
        public readonly ?Node $update,
        public readonly Node $body,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'ForStatement';
    }
}
