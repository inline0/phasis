<?php

declare(strict_types=1);

namespace PhpJs\Parser;

use PhpJs\Exceptions\SyntaxError;
use PhpJs\Lexer\Token;

class ParseError extends SyntaxError
{
    public function __construct(string $message, Token $token, ?\Throwable $previous = null)
    {
        parent::__construct(
            $message . " (got {$token->type->value} \"{$token->value}\")",
            $token->location,
            $previous,
        );
    }
}
