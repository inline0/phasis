<?php

declare(strict_types=1);

namespace Phasis\Exceptions;

use Phasis\Lexer\SourceLocation;

class SyntaxError extends \RuntimeException
{
    public readonly ?SourceLocation $location;

    public function __construct(string $message, ?SourceLocation $location = null, ?\Throwable $previous = null)
    {
        $this->location = $location;
        $locationStr = $location !== null ? " at line {$location->line}, column {$location->column}" : '';
        parent::__construct($message . $locationStr, 0, $previous);
    }
}
