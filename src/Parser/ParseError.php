<?php

declare(strict_types=1);

namespace Phasis\Parser;

use Phasis\Exceptions\SyntaxError;
use Phasis\Lexer\Token;

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
