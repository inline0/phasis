<?php

declare(strict_types=1);

namespace Phasis\Ast\Statement;

use Phasis\Ast\Node;
use Phasis\Lexer\SourceLocation;

class SwitchCase extends Node
{
    /**
     * @param Node[] $consequent
     */
    public function __construct(
        SourceLocation $location,
        public readonly ?Node $test,
        public readonly array $consequent,
    ) {
        parent::__construct($location);
    }

    public function type(): string
    {
        return 'SwitchCase';
    }
}
