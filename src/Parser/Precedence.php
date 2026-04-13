<?php

declare(strict_types=1);

namespace PhpJs\Parser;

use PhpJs\Lexer\TokenType;

/**
 * Operator precedence levels for Pratt parsing.
 * Higher number = tighter binding.
 */
final class Precedence
{
    public const NONE = 0;
    public const ASSIGNMENT = 1;      // = += -= etc.
    public const CONDITIONAL = 2;     // ?:
    public const NULLISH = 3;         // ??
    public const LOGICAL_OR = 4;      // ||
    public const LOGICAL_AND = 5;     // &&
    public const BITWISE_OR = 6;      // |
    public const BITWISE_XOR = 7;     // ^
    public const BITWISE_AND = 8;     // &
    public const EQUALITY = 9;        // == != === !==
    public const RELATIONAL = 10;     // < > <= >= in instanceof
    public const SHIFT = 11;          // << >> >>>
    public const ADDITIVE = 12;       // + -
    public const MULTIPLICATIVE = 13; // * / %
    public const EXPONENTIATION = 14; // **
    public const UNARY = 15;          // ! ~ + - typeof void delete
    public const UPDATE = 16;         // ++ --
    public const CALL = 17;           // () [] . ?.
    public const NEW_ = 18;           // new
    public const PRIMARY = 19;        // literals, identifiers

    public static function infixFor(TokenType $type): int
    {
        return match ($type) {
            TokenType::NullishCoalescing => self::NULLISH,
            TokenType::LogicalOr => self::LOGICAL_OR,
            TokenType::LogicalAnd => self::LOGICAL_AND,
            TokenType::Pipe => self::BITWISE_OR,
            TokenType::Caret => self::BITWISE_XOR,
            TokenType::Ampersand => self::BITWISE_AND,
            TokenType::EqualEqual,
            TokenType::NotEqual,
            TokenType::StrictEqual,
            TokenType::StrictNotEqual => self::EQUALITY,
            TokenType::LessThan,
            TokenType::GreaterThan,
            TokenType::LessThanEqual,
            TokenType::GreaterThanEqual,
            TokenType::Instanceof,
            TokenType::In => self::RELATIONAL,
            TokenType::LeftShift,
            TokenType::RightShift,
            TokenType::UnsignedRightShift => self::SHIFT,
            TokenType::Plus,
            TokenType::Minus => self::ADDITIVE,
            TokenType::Star,
            TokenType::Slash,
            TokenType::Percent => self::MULTIPLICATIVE,
            TokenType::Exponent => self::EXPONENTIATION,
            TokenType::LeftParen => self::CALL,
            TokenType::LeftBracket,
            TokenType::Dot,
            TokenType::OptionalChaining => self::CALL,
            default => self::NONE,
        };
    }
}
