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

        // Unicode escape at identifier start: \uXXXX or \u{XXXX}
        if ($ch === '\\' && $this->pos + 1 < $this->length && $this->source[$this->pos + 1] === 'u') {
            return $this->readIdentifierWithEscapes($start);
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

        // RegExp literal: / at start of expression context
        if ($ch === '/' && $this->canStartRegExp()) {
            return $this->readRegExp($start);
        }

        // Punctuators
        return $this->readPunctuator($start);
    }

    private function readIdentifier(SourceLocation $start): Token
    {
        $result = '';
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];
            if ($ch === '\\' && $this->pos + 1 < $this->length && $this->source[$this->pos + 1] === 'u') {
                $this->advance(); // skip backslash
                $this->advance(); // skip 'u'
                $decoded = $this->readUnicodeEscape();
                $result .= $decoded;
                continue;
            }
            if ($this->isIdentifierPart($ch)) {
                $result .= $ch;
                $this->advance();
            } else {
                break;
            }
        }

        $keyword = TokenType::fromKeyword($result);
        if ($keyword !== null) {
            return new Token($keyword, $result, $start);
        }

        return new Token(TokenType::Identifier, $result, $start);
    }

    private function readIdentifierWithEscapes(SourceLocation $start): Token
    {
        $result = '';

        // Read the first character via unicode escape
        $this->advance(); // skip backslash
        $this->advance(); // skip 'u'
        $decoded = $this->readUnicodeEscape();
        $result .= $decoded;

        // Continue reading identifier parts (including more escapes)
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];
            if ($ch === '\\' && $this->pos + 1 < $this->length && $this->source[$this->pos + 1] === 'u') {
                $this->advance(); // skip backslash
                $this->advance(); // skip 'u'
                $decoded = $this->readUnicodeEscape();
                $result .= $decoded;
                continue;
            }
            if ($this->isIdentifierPart($ch)) {
                $result .= $ch;
                $this->advance();
            } else {
                break;
            }
        }

        // Check if the decoded identifier matches a keyword
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
                while ($this->pos < $this->length && $this->isHexDigitOrSeparator()) {
                    if ($this->source[$this->pos] !== '_') {
                        $result .= $this->source[$this->pos];
                    }
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
                while ($this->pos < $this->length && $this->isOctalDigitOrSeparator()) {
                    if ($this->source[$this->pos] !== '_') {
                        $result .= $this->source[$this->pos];
                    }
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
                while ($this->pos < $this->length && $this->isBinaryDigitOrSeparator()) {
                    if ($this->source[$this->pos] !== '_') {
                        $result .= $this->source[$this->pos];
                    }
                    $this->advance();
                }
                return new Token(TokenType::Number, $result, $start);
            }
        }

        // Decimal (including leading dot)
        while ($this->pos < $this->length && $this->isDigitOrSeparator()) {
            if ($this->source[$this->pos] !== '_') {
                $result .= $this->source[$this->pos];
            }
            $this->advance();
        }

        // Decimal point
        if ($this->pos < $this->length && $this->source[$this->pos] === '.') {
            $result .= '.';
            $this->advance();
            while ($this->pos < $this->length && $this->isDigitOrSeparator()) {
                if ($this->source[$this->pos] !== '_') {
                    $result .= $this->source[$this->pos];
                }
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
            if (
                $this->pos < $this->length
                && ($this->source[$this->pos] === '+' || $this->source[$this->pos] === '-')
            ) {
                $result .= $this->source[$this->pos];
                $this->advance();
            }
            if ($this->pos >= $this->length || !ctype_digit($this->source[$this->pos])) {
                throw new SyntaxError('Invalid exponent', $start);
            }
            while ($this->pos < $this->length && $this->isDigitOrSeparator()) {
                if ($this->source[$this->pos] !== '_') {
                    $result .= $this->source[$this->pos];
                }
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
        $code = (int) hexdec($hex);
        $chr = mb_chr($code, 'UTF-8');
        return $chr !== false ? $chr : chr($code);
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
            $chr = mb_chr($code, 'UTF-8');
            return $chr !== false ? $chr : '?';
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

            // Multi-byte UTF-8: check Unicode whitespace and line terminators
            if (ord($ch) >= 128) {
                if ($this->isUnicodeLineTerminator()) {
                    $this->lineTerminatorBefore = true;
                    $this->pos += 3;
                    $this->line++;
                    $this->column = 0;
                    continue;
                }
                if ($this->isUnicodeWhitespace()) {
                    $this->skipUtf8Char();
                    continue;
                }
                break;
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

            // Unicode line terminators handled above in multi-byte section

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

    private function isDigitOrSeparator(): bool
    {
        $ch = $this->source[$this->pos];
        return ctype_digit($ch) || $ch === '_';
    }

    private function isHexDigitOrSeparator(): bool
    {
        $ch = $this->source[$this->pos];
        return ctype_xdigit($ch) || $ch === '_';
    }

    private function isOctalDigitOrSeparator(): bool
    {
        $ch = $this->source[$this->pos];
        return ($ch >= '0' && $ch <= '7') || $ch === '_';
    }

    private function isBinaryDigitOrSeparator(): bool
    {
        $ch = $this->source[$this->pos];
        return $ch === '0' || $ch === '1' || $ch === '_';
    }

    public function getPosition(): int
    {
        return $this->pos;
    }

    private function isUnicodeWhitespace(): bool
    {
        if ($this->pos + 1 >= $this->length) {
            return false;
        }
        $b0 = ord($this->source[$this->pos]);
        // 2-byte: U+00A0 = C2 A0
        if ($b0 === 0xC2 && $this->pos + 1 < $this->length && ord($this->source[$this->pos + 1]) === 0xA0) {
            return true;
        }
        // 3-byte sequences
        if ($b0 === 0xE2 && $this->pos + 2 < $this->length) {
            $b1 = ord($this->source[$this->pos + 1]);
            $b2 = ord($this->source[$this->pos + 2]);
            // U+2000-U+200A (E2 80 80 - E2 80 8A)
            if ($b1 === 0x80 && $b2 >= 0x80 && $b2 <= 0x8A) {
                return true;
            }
            // U+202F (E2 80 AF)
            if ($b1 === 0x80 && $b2 === 0xAF) {
                return true;
            }
            // U+205F (E2 81 9F)
            if ($b1 === 0x81 && $b2 === 0x9F) {
                return true;
            }
        }
        // U+3000 (E3 80 80)
        if ($b0 === 0xE3 && $this->pos + 2 < $this->length) {
            if (ord($this->source[$this->pos + 1]) === 0x80 && ord($this->source[$this->pos + 2]) === 0x80) {
                return true;
            }
        }
        // U+FEFF (EF BB BF) - BOM
        if ($b0 === 0xEF && $this->pos + 2 < $this->length) {
            if (ord($this->source[$this->pos + 1]) === 0xBB && ord($this->source[$this->pos + 2]) === 0xBF) {
                return true;
            }
        }
        return false;
    }

    private function isUnicodeLineTerminator(): bool
    {
        if ($this->pos + 2 >= $this->length) {
            return false;
        }
        $b0 = ord($this->source[$this->pos]);
        if ($b0 !== 0xE2) {
            return false;
        }
        $b1 = ord($this->source[$this->pos + 1]);
        $b2 = ord($this->source[$this->pos + 2]);
        // U+2028 = E2 80 A8, U+2029 = E2 80 A9
        return $b1 === 0x80 && ($b2 === 0xA8 || $b2 === 0xA9);
    }

    private function skipUtf8Char(): void
    {
        $b0 = ord($this->source[$this->pos]);
        if ($b0 < 0x80) {
            $this->advance();
        } elseif ($b0 < 0xE0) {
            $this->pos += 2;
            $this->column++;
        } elseif ($b0 < 0xF0) {
            $this->pos += 3;
            $this->column++;
        } else {
            $this->pos += 4;
            $this->column++;
        }
    }

    /**
     * Determine if / should be interpreted as a RegExp literal (vs division).
     * After value-producing tokens, / is division. Otherwise it's a regex.
     */
    private function canStartRegExp(): bool
    {
        if (empty($this->tokens)) {
            return true;
        }
        $prev = $this->tokens[count($this->tokens) - 1];
        // After these token types, / is division (value-producing)
        return !in_array($prev->type, [
            TokenType::Identifier,
            TokenType::Number,
            TokenType::String,
            TokenType::NoSubstitutionTemplate,
            TokenType::TemplateTail,
            TokenType::RegExp,
            TokenType::RightParen,
            TokenType::RightBracket,
            TokenType::PlusPlus,
            TokenType::MinusMinus,
            TokenType::True,
            TokenType::False,
            TokenType::Null,
            TokenType::This,
            TokenType::RightBrace,
        ], true);
    }

    /**
     * Read a RegExp literal: /pattern/flags
     */
    private function readRegExp(SourceLocation $start): Token
    {
        $this->advance(); // skip opening /
        $pattern = '';
        $inCharClass = false;

        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];

            if ($ch === '\\') {
                $pattern .= $ch;
                $this->advance();
                if ($this->pos < $this->length) {
                    $pattern .= $this->source[$this->pos];
                    $this->advance();
                }
                continue;
            }

            if ($ch === '[') {
                $inCharClass = true;
                $pattern .= $ch;
                $this->advance();
                continue;
            }

            if ($ch === ']' && $inCharClass) {
                $inCharClass = false;
                $pattern .= $ch;
                $this->advance();
                continue;
            }

            if ($ch === '/' && !$inCharClass) {
                $this->advance(); // skip closing /
                break;
            }

            if ($ch === "\n" || $ch === "\r") {
                throw new SyntaxError('Unterminated regular expression', $start);
            }

            $pattern .= $ch;
            $this->advance();
        }

        // Read flags
        $flags = '';
        while ($this->pos < $this->length && ctype_alpha($this->source[$this->pos])) {
            $flags .= $this->source[$this->pos];
            $this->advance();
        }

        return new Token(TokenType::RegExp, "/{$pattern}/{$flags}", $start);
    }
}
