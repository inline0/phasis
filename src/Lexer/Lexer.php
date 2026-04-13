<?php

declare(strict_types=1);

namespace PhpJs\Lexer;

use PhpJs\Exceptions\SyntaxError;

class Lexer
{
    private string $source;
    private int $length;
    private int $pos = 0;
    private int $line = 1;
    private int $column = 0;
    private bool $lineTerminatorBefore = false;
    private int $templateDepth = 0;

    /** @var Token[] */
    private array $tokens = [];

    public function __construct(string $source)
    {
        $this->source = $source;
        $this->length = strlen($source);
    }

    /** @return Token[] */
    public function tokenize(): array
    {
        $this->tokens = [];
        $this->pos = 0;
        $this->line = 1;
        $this->column = 0;

        while ($this->pos < $this->length) {
            $this->lineTerminatorBefore = false;
            $this->skipWhitespaceAndComments();

            if ($this->pos >= $this->length) {
                break;
            }

            // Inside template expression: } means template continuation
            if ($this->templateDepth > 0 && $this->source[$this->pos] === '}') {
                $this->advance(); // skip }
                $token = $this->readTemplateContinuation();
                if ($token->type === TokenType::TemplateTail) {
                    $this->templateDepth--;
                }
                // TemplateMiddle: templateDepth stays the same
            } else {
                $token = $this->readToken();
                if ($token->type === TokenType::TemplateHead) {
                    $this->templateDepth++;
                }
            }
            $token = new Token(
                $token->type,
                $token->value,
                $token->location,
                $this->lineTerminatorBefore,
            );
            $this->tokens[] = $token;
        }

        $this->tokens[] = new Token(
            TokenType::EOF,
            '',
            new SourceLocation($this->line, $this->column, $this->pos),
            $this->lineTerminatorBefore,
        );

        return $this->tokens;
    }

    private function readToken(): Token
    {
        $start = $this->location();
        $ch = $this->source[$this->pos];

        // Identifiers and keywords
        if ($this->isIdentifierStart($ch)) {
            return $this->readIdentifier($start);
        }

        // Numeric literals
        if ($ch === '.' && $this->pos + 1 < $this->length && ctype_digit($this->source[$this->pos + 1])) {
            return $this->readNumber($start);
        }
        if (ctype_digit($ch)) {
            return $this->readNumber($start);
        }

        // String literals
        if ($ch === '"' || $ch === "'") {
            return $this->readString($start);
        }

        // Template literals
        if ($ch === '`') {
            return $this->readTemplate($start);
        }

        // Punctuators
        return $this->readPunctuator($start);
    }

    private function readIdentifier(SourceLocation $start): Token
    {
        $result = '';
        while ($this->pos < $this->length && $this->isIdentifierPart($this->source[$this->pos])) {
            $result .= $this->source[$this->pos];
            $this->advance();
        }

        $keyword = TokenType::fromKeyword($result);
        if ($keyword !== null) {
            return new Token($keyword, $result, $start);
        }

        return new Token(TokenType::Identifier, $result, $start);
    }

    private function readNumber(SourceLocation $start): Token
    {
        $result = '';
        $ch = $this->source[$this->pos];

        if ($ch === '0' && $this->pos + 1 < $this->length) {
            $next = $this->source[$this->pos + 1];

            // Hex: 0x or 0X
            if ($next === 'x' || $next === 'X') {
                $result .= $ch . $next;
                $this->advance();
                $this->advance();
                if ($this->pos >= $this->length || !ctype_xdigit($this->source[$this->pos])) {
                    throw new SyntaxError('Invalid hex literal', $start);
                }
                while ($this->pos < $this->length && ctype_xdigit($this->source[$this->pos])) {
                    $result .= $this->source[$this->pos];
                    $this->advance();
                }
                return new Token(TokenType::Number, $result, $start);
            }

            // Octal: 0o or 0O
            if ($next === 'o' || $next === 'O') {
                $result .= $ch . $next;
                $this->advance();
                $this->advance();
                if ($this->pos >= $this->length || $this->source[$this->pos] < '0' || $this->source[$this->pos] > '7') {
                    throw new SyntaxError('Invalid octal literal', $start);
                }
                while (
                    $this->pos < $this->length
                    && $this->source[$this->pos] >= '0'
                    && $this->source[$this->pos] <= '7'
                ) {
                    $result .= $this->source[$this->pos];
                    $this->advance();
                }
                return new Token(TokenType::Number, $result, $start);
            }

            // Binary: 0b or 0B
            if ($next === 'b' || $next === 'B') {
                $result .= $ch . $next;
                $this->advance();
                $this->advance();
                $ch2 = $this->pos < $this->length ? $this->source[$this->pos] : '';
                if ($ch2 !== '0' && $ch2 !== '1') {
                    throw new SyntaxError('Invalid binary literal', $start);
                }
                while (
                    $this->pos < $this->length
                    && ($this->source[$this->pos] === '0' || $this->source[$this->pos] === '1')
                ) {
                    $result .= $this->source[$this->pos];
                    $this->advance();
                }
                return new Token(TokenType::Number, $result, $start);
            }
        }

        // Decimal (including leading dot)
        while ($this->pos < $this->length && ctype_digit($this->source[$this->pos])) {
            $result .= $this->source[$this->pos];
            $this->advance();
        }

        // Decimal point
        if ($this->pos < $this->length && $this->source[$this->pos] === '.') {
            $result .= '.';
            $this->advance();
            while ($this->pos < $this->length && ctype_digit($this->source[$this->pos])) {
                $result .= $this->source[$this->pos];
                $this->advance();
            }
        }

        // Exponent
        if (
            $this->pos < $this->length
            && ($this->source[$this->pos] === 'e' || $this->source[$this->pos] === 'E')
        ) {
            $result .= $this->source[$this->pos];
            $this->advance();
            if ($this->pos < $this->length && ($this->source[$this->pos] === '+' || $this->source[$this->pos] === '-')) {
                $result .= $this->source[$this->pos];
                $this->advance();
            }
            if ($this->pos >= $this->length || !ctype_digit($this->source[$this->pos])) {
                throw new SyntaxError('Invalid exponent', $start);
            }
            while ($this->pos < $this->length && ctype_digit($this->source[$this->pos])) {
                $result .= $this->source[$this->pos];
                $this->advance();
            }
        }

        // BigInt suffix
        if ($this->pos < $this->length && $this->source[$this->pos] === 'n') {
            $result .= 'n';
            $this->advance();
        }

        return new Token(TokenType::Number, $result, $start);
    }

    private function readString(SourceLocation $start): Token
    {
        $quote = $this->source[$this->pos];
        $this->advance();
        $result = '';

        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];

            if ($ch === $quote) {
                $this->advance();
                return new Token(TokenType::String, $result, $start);
            }

            if ($ch === '\\') {
                $this->advance();
                if ($this->pos >= $this->length) {
                    throw new SyntaxError('Unterminated string literal', $start);
                }
                $result .= $this->readEscapeSequence();
                continue;
            }

            if ($ch === "\n" || $ch === "\r") {
                throw new SyntaxError('Unterminated string literal', $start);
            }

            $result .= $ch;
            $this->advance();
        }

        throw new SyntaxError('Unterminated string literal', $start);
    }

    private function readEscapeSequence(): string
    {
        $ch = $this->source[$this->pos];
        $this->advance();

        return match ($ch) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\x08",
            'f' => "\f",
            'v' => "\v",
            '0' => "\0",
            'x' => $this->readHexEscape(2),
            'u' => $this->readUnicodeEscape(),
            "\n" => '',
            "\r" => $this->pos < $this->length && $this->source[$this->pos] === "\n"
                ? (function () {
                    $this->advance();
                    return '';
                })()
                : '',
            default => $ch,
        };
    }

    private function readHexEscape(int $count): string
    {
        $start = $this->location();
        $hex = '';
        for ($i = 0; $i < $count; $i++) {
            if ($this->pos >= $this->length || !ctype_xdigit($this->source[$this->pos])) {
                throw new SyntaxError('Invalid hex escape sequence', $start);
            }
            $hex .= $this->source[$this->pos];
            $this->advance();
        }
        return mb_chr((int) hexdec($hex), 'UTF-8');
    }

    private function readUnicodeEscape(): string
    {
        $start = $this->location();

        if ($this->pos < $this->length && $this->source[$this->pos] === '{') {
            $this->advance();
            $hex = '';
            while ($this->pos < $this->length && $this->source[$this->pos] !== '}') {
                if (!ctype_xdigit($this->source[$this->pos])) {
                    throw new SyntaxError('Invalid Unicode escape sequence', $start);
                }
                $hex .= $this->source[$this->pos];
                $this->advance();
            }
            if ($this->pos >= $this->length) {
                throw new SyntaxError('Unterminated Unicode escape sequence', $start);
            }
            $this->advance(); // skip }
            $code = (int) hexdec($hex);
            if ($code > 0x10FFFF) {
                throw new SyntaxError('Unicode escape out of range', $start);
            }
            return mb_chr($code, 'UTF-8');
        }

        return $this->readHexEscape(4);
    }

    private function readTemplate(SourceLocation $start): Token
    {
        $this->advance(); // skip opening backtick
        $result = '';

        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];

            if ($ch === '`') {
                $this->advance();
                return new Token(TokenType::NoSubstitutionTemplate, $result, $start);
            }

            if ($ch === '$' && $this->pos + 1 < $this->length && $this->source[$this->pos + 1] === '{') {
                $this->advance(); // skip $
                $this->advance(); // skip {
                return new Token(TokenType::TemplateHead, $result, $start);
            }

            if ($ch === '\\') {
                $this->advance();
                if ($this->pos >= $this->length) {
                    throw new SyntaxError('Unterminated template literal', $start);
                }
                $result .= $this->readEscapeSequence();
                continue;
            }

            if ($ch === "\r") {
                $this->advance();
                if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                    $this->advance();
                }
                $result .= "\n";
                continue;
            }

            $result .= $ch;
            $this->advance();
        }

        throw new SyntaxError('Unterminated template literal', $start);
    }

    /** Read template continuation after expression in ${...}. */
    public function readTemplateContinuation(): Token
    {
        $start = $this->location();
        $this->lineTerminatorBefore = false;
        $result = '';

        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];

            if ($ch === '`') {
                $this->advance();
                return new Token(TokenType::TemplateTail, $result, $start, $this->lineTerminatorBefore);
            }

            if ($ch === '$' && $this->pos + 1 < $this->length && $this->source[$this->pos + 1] === '{') {
                $this->advance();
                $this->advance();
                return new Token(TokenType::TemplateMiddle, $result, $start, $this->lineTerminatorBefore);
            }

            if ($ch === '\\') {
                $this->advance();
                if ($this->pos >= $this->length) {
                    throw new SyntaxError('Unterminated template literal', $start);
                }
                $result .= $this->readEscapeSequence();
                continue;
            }

            if ($ch === "\r") {
                $this->advance();
                $this->lineTerminatorBefore = true;
                if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                    $this->advance();
                }
                $result .= "\n";
                continue;
            }

            if ($ch === "\n") {
                $this->lineTerminatorBefore = true;
            }

            $result .= $ch;
            $this->advance();
        }

        throw new SyntaxError('Unterminated template literal', $start);
    }

    private function readPunctuator(SourceLocation $start): Token
    {
        $ch = $this->source[$this->pos];

        // Three-character tokens first
        if ($this->pos + 2 < $this->length) {
            $three = substr($this->source, $this->pos, 3);
            $threeType = match ($three) {
                '===' => TokenType::StrictEqual,
                '!==' => TokenType::StrictNotEqual,
                '**=' => TokenType::ExponentEqual,
                '<<=' => TokenType::LeftShiftEqual,
                '>>=' => TokenType::RightShiftEqual,
                '&&=' => TokenType::LogicalAndEqual,
                '||=' => TokenType::LogicalOrEqual,
                '??=' => TokenType::NullishCoalescingEqual,
                '...' => TokenType::Ellipsis,
                '>>>' => TokenType::UnsignedRightShift,
                default => null,
            };
            if ($threeType !== null) {
                $this->advance();
                $this->advance();
                $this->advance();

                // Four-character: >>>=
                if (
                    $threeType === TokenType::UnsignedRightShift
                    && $this->pos < $this->length
                    && $this->source[$this->pos] === '='
                ) {
                    $this->advance();
                    return new Token(TokenType::UnsignedRightShiftEqual, '>>>=', $start);
                }

                return new Token($threeType, $three, $start);
            }
        }

        // Two-character tokens
        if ($this->pos + 1 < $this->length) {
            $two = substr($this->source, $this->pos, 2);
            $twoType = match ($two) {
                '==' => TokenType::EqualEqual,
                '!=' => TokenType::NotEqual,
                '<=' => TokenType::LessThanEqual,
                '>=' => TokenType::GreaterThanEqual,
                '**' => TokenType::Exponent,
                '++' => TokenType::PlusPlus,
                '--' => TokenType::MinusMinus,
                '<<' => TokenType::LeftShift,
                '>>' => TokenType::RightShift,
                '&&' => TokenType::LogicalAnd,
                '||' => TokenType::LogicalOr,
                '??' => TokenType::NullishCoalescing,
                '?.' => TokenType::OptionalChaining,
                '=>' => TokenType::Arrow,
                '+=' => TokenType::PlusEqual,
                '-=' => TokenType::MinusEqual,
                '*=' => TokenType::StarEqual,
                '/=' => TokenType::SlashEqual,
                '%=' => TokenType::PercentEqual,
                '&=' => TokenType::AmpersandEqual,
                '|=' => TokenType::PipeEqual,
                '^=' => TokenType::CaretEqual,
                default => null,
            };
            if ($twoType !== null) {
                $this->advance();
                $this->advance();
                return new Token($twoType, $two, $start);
            }
        }

        // Single-character tokens
        $type = match ($ch) {
            '{' => TokenType::LeftBrace,
            '}' => TokenType::RightBrace,
            '(' => TokenType::LeftParen,
            ')' => TokenType::RightParen,
            '[' => TokenType::LeftBracket,
            ']' => TokenType::RightBracket,
            '.' => TokenType::Dot,
            ';' => TokenType::Semicolon,
            ',' => TokenType::Comma,
            '<' => TokenType::LessThan,
            '>' => TokenType::GreaterThan,
            '+' => TokenType::Plus,
            '-' => TokenType::Minus,
            '*' => TokenType::Star,
            '/' => TokenType::Slash,
            '%' => TokenType::Percent,
            '&' => TokenType::Ampersand,
            '|' => TokenType::Pipe,
            '^' => TokenType::Caret,
            '!' => TokenType::Bang,
            '~' => TokenType::Tilde,
            '?' => TokenType::Question,
            ':' => TokenType::Colon,
            '=' => TokenType::Equal,
            default => throw new SyntaxError("Unexpected character: {$ch}", $start),
        };

        $this->advance();
        return new Token($type, $ch, $start);
    }

    private function skipWhitespaceAndComments(): void
    {
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];

            // Whitespace (non-line-terminator)
            if ($ch === ' ' || $ch === "\t" || $ch === "\f" || $ch === "\v") {
                $this->advance();
                continue;
            }

            // Line terminators
            if ($ch === "\n") {
                $this->lineTerminatorBefore = true;
                $this->pos++;
                $this->line++;
                $this->column = 0;
                continue;
            }
            if ($ch === "\r") {
                $this->lineTerminatorBefore = true;
                $this->pos++;
                if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                    $this->pos++;
                }
                $this->line++;
                $this->column = 0;
                continue;
            }

            // Single-line comment
            $isLineComment = $ch === '/'
                && $this->pos + 1 < $this->length
                && $this->source[$this->pos + 1] === '/';
            if ($isLineComment) {
                $this->pos += 2;
                $this->column += 2;
                while (
                    $this->pos < $this->length
                    && $this->source[$this->pos] !== "\n"
                    && $this->source[$this->pos] !== "\r"
                ) {
                    $this->pos++;
                    $this->column++;
                }
                continue;
            }

            // Multi-line comment
            $isBlockComment = $ch === '/'
                && $this->pos + 1 < $this->length
                && $this->source[$this->pos + 1] === '*';
            if ($isBlockComment) {
                $this->pos += 2;
                $this->column += 2;
                while ($this->pos < $this->length) {
                    $isEnd = $this->source[$this->pos] === '*'
                        && $this->pos + 1 < $this->length
                        && $this->source[$this->pos + 1] === '/';
                    if ($isEnd) {
                        $this->pos += 2;
                        $this->column += 2;
                        break;
                    }
                    if ($this->source[$this->pos] === "\n") {
                        $this->lineTerminatorBefore = true;
                        $this->pos++;
                        $this->line++;
                        $this->column = 0;
                    } elseif ($this->source[$this->pos] === "\r") {
                        $this->lineTerminatorBefore = true;
                        $this->pos++;
                        if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                            $this->pos++;
                        }
                        $this->line++;
                        $this->column = 0;
                    } else {
                        $this->pos++;
                        $this->column++;
                    }
                }
                continue;
            }

            // Not whitespace or comment
            break;
        }
    }

    private function advance(): void
    {
        $this->pos++;
        $this->column++;
    }

    private function location(): SourceLocation
    {
        return new SourceLocation($this->line, $this->column, $this->pos);
    }

    private function isIdentifierStart(string $ch): bool
    {
        return ctype_alpha($ch) || $ch === '_' || $ch === '$';
    }

    private function isIdentifierPart(string $ch): bool
    {
        return ctype_alnum($ch) || $ch === '_' || $ch === '$';
    }

    public function getPosition(): int
    {
        return $this->pos;
    }
}
