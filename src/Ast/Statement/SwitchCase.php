<?php

declare(strict_types=1);

namespace PhpJs\Ast\Statement;

use PhpJs\Ast\Node;
use PhpJs\Lexer\SourceLocation;

readonly class SwitchCase extends Node
{
    /**
     * @param Node[] $consequent
     */
    public function __construct(
        SourceLocation $location,
        public ?Node $test,
        public array $consequent,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'SwitchCase';
    }
}
