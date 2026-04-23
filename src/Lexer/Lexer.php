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
    /** @var int[] Brace depth stack: one entry per active template expression. */
    private array $templateBraceDepth = [];

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

        // Skip hashbang (#!) at the very start of the source per spec.
        if (
            $this->pos === 0
            && $this->length >= 2
            && $this->source[0] === '#'
            && $this->source[1] === '!'
        ) {
            while ($this->pos < $this->length) {
                $ch = $this->source[$this->pos];
                // Stop at any line terminator: LF, CR, U+2028, U+2029
                if ($ch === "\n" || $ch === "\r") {
                    break;
                }
                if (ord($ch) >= 0xE0 && $this->isUnicodeLineTerminator()) {
                    break;
                }
                $this->pos++;
                $this->column++;
            }
        }

        while ($this->pos < $this->length) {
            $this->lineTerminatorBefore = false;
            $this->skipWhitespaceAndComments();

            if ($this->pos >= $this->length) {
                break;
            }

            // Inside template expression: } at brace depth 0 means template continuation.
            // Nested braces (from function bodies, object literals, etc.) are tracked.
            if (
                $this->templateDepth > 0
                && $this->source[$this->pos] === '}'
                && end($this->templateBraceDepth) === 0
            ) {
                $this->advance(); // skip }
                array_pop($this->templateBraceDepth);
                $token = $this->readTemplateContinuation();
                if ($token->type === TokenType::TemplateTail) {
                    $this->templateDepth--;
                } else {
                    // TemplateMiddle: push new brace depth for the new expression.
                    $this->templateBraceDepth[] = 0;
                }
            } else {
                $token = $this->readToken();
                if ($token->type === TokenType::TemplateHead) {
                    $this->templateDepth++;
                    $this->templateBraceDepth[] = 0;
                }
                // Track brace depth inside template expressions.
                if ($this->templateDepth > 0) {
                    if ($token->type === TokenType::LeftBrace) {
                        $this->templateBraceDepth[count($this->templateBraceDepth) - 1]++;
                    } elseif ($token->type === TokenType::RightBrace && !empty($this->templateBraceDepth)) {
                        $this->templateBraceDepth[count($this->templateBraceDepth) - 1]--;
                    }
                }
            }
            $token = new Token(
                $token->type,
                $token->value,
                $token->location,
                $this->lineTerminatorBefore,
                $token->rawValue,
                $token->cookedInvalid,
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

        // Private identifier (#name) for class private fields/methods
        if ($ch === '#' && $this->pos + 1 < $this->length && $this->isIdentifierStart($this->source[$this->pos + 1])) {
            return $this->readPrivateIdentifier($start);
        }

        // Punctuators
        return $this->readPunctuator($start);
    }

    private function readIdentifier(SourceLocation $start): Token
    {
        $result = '';
        $hasEscape = false;
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];
            if ($ch === '\\' && $this->pos + 1 < $this->length && $this->source[$this->pos + 1] === 'u') {
                $hasEscape = true;
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
            // Mark escaped keywords so the parser can reject them where the spec
            // requires the literal keyword (e.g. import() must be literal 'import').
            $rawValue = $hasEscape ? 'escaped' : null;
            return new Token($keyword, $result, $start, rawValue: $rawValue);
        }

        return new Token(TokenType::Identifier, $result, $start);
    }

    private function readPrivateIdentifier(SourceLocation $start): Token
    {
        $this->advance(); // skip '#'
        $name = '';
        while ($this->pos < $this->length && $this->isIdentifierPart($this->source[$this->pos])) {
            $name .= $this->source[$this->pos];
            $this->advance();
        }
        return new Token(TokenType::PrivateIdentifier, '#' . $name, $start);
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

        // Check if the decoded identifier matches a keyword.
        // Always mark as escaped since this method is only called when the
        // identifier starts with a unicode escape sequence.
        $keyword = TokenType::fromKeyword($result);
        if ($keyword !== null) {
            return new Token($keyword, $result, $start, rawValue: 'escaped');
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
                // BigInt suffix
                if ($this->pos < $this->length && $this->source[$this->pos] === 'n') {
                    $result .= 'n';
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
                // BigInt suffix
                if ($this->pos < $this->length && $this->source[$this->pos] === 'n') {
                    $result .= 'n';
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
                // BigInt suffix
                if ($this->pos < $this->length && $this->source[$this->pos] === 'n') {
                    $result .= 'n';
                    $this->advance();
                }
                return new Token(TokenType::Number, $result, $start);
            }

            // Legacy octal: 0 followed by octal digits (AnnexB B.1.1).
            // If any digit 8 or 9 appears, it falls back to decimal.
            if ($next >= '0' && $next <= '7') {
                $scanPos = $this->pos + 1;
                $isLegacyOctal = true;
                while ($scanPos < $this->length) {
                    $scanCh = $this->source[$scanPos];
                    if ($scanCh >= '0' && $scanCh <= '7') {
                        $scanPos++;
                    } elseif ($scanCh === '8' || $scanCh === '9') {
                        $isLegacyOctal = false;
                        break;
                    } else {
                        // Non-digit: stop scan. If we hit '.', 'e', 'E' it's decimal.
                        if ($scanCh === '.' || $scanCh === 'e' || $scanCh === 'E') {
                            $isLegacyOctal = false;
                        }
                        break;
                    }
                }
                if ($isLegacyOctal) {
                    // Emit with a 0lo prefix so the parser can distinguish
                    // legacy octals (Annex B, forbidden in strict mode) from
                    // the explicit 0o notation. The value is still octal.
                    $this->advance(); // skip leading 0
                    $result = '0lo';
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
        $hasEscape = false;

        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];

            if ($ch === $quote) {
                $this->advance();
                // rawValue 'true' means verbatim (no escape sequences); used to recognise
                // directive prologues: "use strict" is only a directive if it has no escapes.
                return new Token(TokenType::String, $result, $start, rawValue: $hasEscape ? 'escaped' : 'verbatim');
            }

            if ($ch === '\\') {
                $hasEscape = true;
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
        // Line continuation: \<LS> or \<PS> (U+2028, U+2029) produce empty string.
        if ($this->isUnicodeLineTerminator()) {
            $this->pos += 3;
            $this->line++;
            $this->column = 0;
            return '';
        }

        $ch = $this->source[$this->pos];
        $this->advance();

        // AnnexB B.1.4: legacy octal escape sequences (\0-\7 start).
        if ($ch >= '0' && $ch <= '7') {
            return $this->readLegacyOctalEscape($ch);
        }

        return match ($ch) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\x08",
            'f' => "\f",
            'v' => "\v",
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

    /**
     * Read an escape sequence inside a template literal. Per ES2018+ (tagged
     * template literal revision), invalid escape sequences do not throw at lex
     * time. Instead, the cooked value is set to null (undefined in JS) while
     * the raw value is preserved from source text. Returns null when the escape
     * is invalid, or the cooked string on success.
     */
    private function readTemplateEscapeSequence(bool &$cookedInvalid): ?string
    {
        // Line continuation: \<LS> or \<PS> (U+2028, U+2029) produce empty string.
        if ($this->isUnicodeLineTerminator()) {
            $this->pos += 3;
            $this->line++;
            $this->column = 0;
            return '';
        }

        $ch = $this->source[$this->pos];

        // Legacy octal escapes (other than \0 not followed by a digit) are
        // NotEscapeSequence in templates per spec.
        if ($ch >= '1' && $ch <= '7') {
            $this->advance();
            $cookedInvalid = true;
            return null;
        }
        if ($ch === '8' || $ch === '9') {
            $this->advance();
            $cookedInvalid = true;
            return null;
        }
        if ($ch === '0') {
            // \0 followed by another digit is an octal escape (invalid in templates).
            $nextIsDigit = $this->pos + 1 < $this->length
                && $this->source[$this->pos + 1] >= '0'
                && $this->source[$this->pos + 1] <= '9';
            if ($nextIsDigit) {
                $this->advance();
                $cookedInvalid = true;
                return null;
            }
            // \0 alone is the null character.
            $this->advance();
            return "\0";
        }

        $this->advance();

        // For hex and unicode escapes, save position and try; if invalid, restore and mark invalid.
        if ($ch === 'x') {
            $savedPos = $this->pos;
            $savedLine = $this->line;
            $savedCol = $this->column;
            try {
                return $this->readHexEscape(2);
            } catch (SyntaxError) {
                $this->pos = $savedPos;
                $this->line = $savedLine;
                $this->column = $savedCol;
                $cookedInvalid = true;
                return null;
            }
        }

        if ($ch === 'u') {
            $savedPos = $this->pos;
            $savedLine = $this->line;
            $savedCol = $this->column;
            try {
                return $this->readUnicodeEscape();
            } catch (SyntaxError) {
                $this->pos = $savedPos;
                $this->line = $savedLine;
                $this->column = $savedCol;
                $cookedInvalid = true;
                return null;
            }
        }

        return match ($ch) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\x08",
            'f' => "\f",
            'v' => "\v",
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

    /**
     * AnnexB B.1.4: legacy octal escape sequences.
     *
     * Handles \0 alone (null char) and \0-\377 octal escapes (1-3 digits).
     */
    private function readLegacyOctalEscape(string $first): string
    {
        $value = (int) $first;

        // Read up to 2 more octal digits.
        if (
            $this->pos < $this->length
            && $this->source[$this->pos] >= '0'
            && $this->source[$this->pos] <= '7'
        ) {
            $value = $value * 8 + (int) $this->source[$this->pos];
            $this->advance();

            // Third octal digit allowed only when first digit is 0-3 (max 377 = 255).
            if (
                $first <= '3'
                && $this->pos < $this->length
                && $this->source[$this->pos] >= '0'
                && $this->source[$this->pos] <= '7'
            ) {
                $value = $value * 8 + (int) $this->source[$this->pos];
                $this->advance();
            }
        }

        $chr = mb_chr($value, 'UTF-8');

        return $chr !== false ? $chr : chr($value);
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

        // Per spec: \uD800-\uDBFF is a high surrogate. If followed by \uDC00-\uDFFF
        // (a low surrogate), combine them into the supplementary Unicode code point.
        if ($count === 4 && $code >= 0xD800 && $code <= 0xDBFF) {
            $savedPos = $this->pos;
            if (
                $this->pos + 5 < $this->length
                && $this->source[$this->pos] === '\\'
                && $this->source[$this->pos + 1] === 'u'
            ) {
                $lo = '';
                for ($j = 0; $j < 4; $j++) {
                    $c = $this->source[$this->pos + 2 + $j] ?? '';
                    if (!ctype_xdigit($c)) {
                        $lo = '';
                        break;
                    }
                    $lo .= $c;
                }
                if (strlen($lo) === 4) {
                    $loCode = (int) hexdec($lo);
                    if ($loCode >= 0xDC00 && $loCode <= 0xDFFF) {
                        // Valid surrogate pair: advance past \uXXXX
                        $this->pos += 6;
                        $codePoint = 0x10000 + ($code - 0xD800) * 0x400 + ($loCode - 0xDC00);
                        $chr = mb_chr($codePoint, 'UTF-8');
                        return $chr !== false ? $chr : '?';
                    }
                }
            }
            // Not a surrogate pair: store as WTF-8 lone surrogate (CESU-8 style).
            // Encode as 3-byte CESU-8: ED A0+((code>>6)&0xF) 80+(code&0x3F)
            return "\xED" . chr(0xA0 | (($code >> 6) & 0x0F)) . chr(0x80 | ($code & 0x3F));
        }

        if ($count === 4 && $code >= 0xDC00 && $code <= 0xDFFF) {
            // Lone low surrogate: encode as CESU-8
            return "\xED" . chr(0xB0 | (($code >> 6) & 0x0F)) . chr(0x80 | ($code & 0x3F));
        }

        $chr = mb_chr($code, 'UTF-8');
        return $chr !== false ? $chr : chr($code & 0xFF);
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
        $cooked = '';
        $raw = '';
        $cookedInvalid = false;

        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];

            if ($ch === '`') {
                $this->advance();
                return new Token(TokenType::NoSubstitutionTemplate, $cooked, $start, false, $raw, $cookedInvalid);
            }

            if ($ch === '$' && $this->pos + 1 < $this->length && $this->source[$this->pos + 1] === '{') {
                $this->advance(); // skip $
                $this->advance(); // skip {
                return new Token(TokenType::TemplateHead, $cooked, $start, false, $raw, $cookedInvalid);
            }

            if ($ch === '\\') {
                $rawStart = $this->pos;
                $this->advance();
                if ($this->pos >= $this->length) {
                    throw new SyntaxError('Unterminated template literal', $start);
                }
                $result = $this->readTemplateEscapeSequence($cookedInvalid);
                if ($result !== null && !$cookedInvalid) {
                    $cooked .= $result;
                }
                // Raw value preserves the original escape text from source,
                // but CR and CRLF are normalized to LF per spec.
                $rawChunk = substr($this->source, $rawStart, $this->pos - $rawStart);
                $rawChunk = str_replace("\r\n", "\n", $rawChunk);
                $rawChunk = str_replace("\r", "\n", $rawChunk);
                $raw .= $rawChunk;
                continue;
            }

            if ($ch === "\r") {
                $this->advance();
                if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                    $this->advance();
                }
                $cooked .= "\n";
                $raw .= "\n";
                continue;
            }

            $cooked .= $ch;
            $raw .= $ch;
            $this->advance();
        }

        throw new SyntaxError('Unterminated template literal', $start);
    }

    /** Read template continuation after expression in ${...}. */
    public function readTemplateContinuation(): Token
    {
        $start = $this->location();
        $this->lineTerminatorBefore = false;
        $cooked = '';
        $raw = '';
        $cookedInvalid = false;

        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];

            if ($ch === '`') {
                $this->advance();
                $ltb = $this->lineTerminatorBefore;
                return new Token(TokenType::TemplateTail, $cooked, $start, $ltb, $raw, $cookedInvalid);
            }

            if ($ch === '$' && $this->pos + 1 < $this->length && $this->source[$this->pos + 1] === '{') {
                $this->advance();
                $this->advance();
                $ltb = $this->lineTerminatorBefore;
                return new Token(TokenType::TemplateMiddle, $cooked, $start, $ltb, $raw, $cookedInvalid);
            }

            if ($ch === '\\') {
                $rawStart = $this->pos;
                $this->advance();
                if ($this->pos >= $this->length) {
                    throw new SyntaxError('Unterminated template literal', $start);
                }
                $result = $this->readTemplateEscapeSequence($cookedInvalid);
                if ($result !== null && !$cookedInvalid) {
                    $cooked .= $result;
                }
                $rawChunk = substr($this->source, $rawStart, $this->pos - $rawStart);
                $rawChunk = str_replace("\r\n", "\n", $rawChunk);
                $rawChunk = str_replace("\r", "\n", $rawChunk);
                $raw .= $rawChunk;
                continue;
            }

            if ($ch === "\r") {
                $this->advance();
                $this->lineTerminatorBefore = true;
                if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                    $this->advance();
                }
                $cooked .= "\n";
                $raw .= "\n";
                continue;
            }

            if ($ch === "\n") {
                $this->lineTerminatorBefore = true;
            }

            $cooked .= $ch;
            $raw .= $ch;
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
            // Per spec: ?. is NOT optional chaining when followed by a decimal digit.
            // e.g. `a?.3:b` is ternary `a ? .3 : b`, not optional chaining.
            if (
                $twoType === TokenType::OptionalChaining
                && $this->pos + 2 < $this->length
                && ctype_digit($this->source[$this->pos + 2])
            ) {
                $twoType = null;
            }

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
            '@' => TokenType::At,
            default => throw new SyntaxError("Unexpected character: {$ch}", $start),
        };

        $this->advance();
        return new Token($type, $ch, $start);
    }

    private function skipWhitespaceAndComments(): void
    {
        // Track whether a line terminator was seen during this whitespace/comment
        // pass, or we are at the very start of the source. This is needed for
        // AnnexB HTML-like close comments: --> is only a comment when it appears
        // at the start of a line (or on the first line of the source).
        $lineTerminatorInPass = empty($this->tokens);

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
                    $lineTerminatorInPass = true;
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
                $lineTerminatorInPass = true;
                $this->pos++;
                $this->line++;
                $this->column = 0;
                continue;
            }
            if ($ch === "\r") {
                $this->lineTerminatorBefore = true;
                $lineTerminatorInPass = true;
                $this->pos++;
                if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                    $this->pos++;
                }
                $this->line++;
                $this->column = 0;
                continue;
            }

            // Unicode line terminators handled above in multi-byte section

            // Single-line comment: //
            $isLineComment = $ch === '/'
                && $this->pos + 1 < $this->length
                && $this->source[$this->pos + 1] === '/';
            if ($isLineComment) {
                $this->pos += 2;
                $this->column += 2;
                $this->skipToEndOfLine();
                continue;
            }

            // AnnexB: SingleLineHTMLOpenComment (<!-- treated as single-line comment)
            if (
                $ch === '<'
                && $this->pos + 3 < $this->length
                && $this->source[$this->pos + 1] === '!'
                && $this->source[$this->pos + 2] === '-'
                && $this->source[$this->pos + 3] === '-'
            ) {
                $this->pos += 4;
                $this->column += 4;
                $this->skipToEndOfLine();
                continue;
            }

            // AnnexB: SingleLineHTMLCloseComment (--> at start of line)
            if (
                $ch === '-'
                && $lineTerminatorInPass
                && $this->pos + 2 < $this->length
                && $this->source[$this->pos + 1] === '-'
                && $this->source[$this->pos + 2] === '>'
            ) {
                $this->pos += 3;
                $this->column += 3;
                $this->skipToEndOfLine();
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
                        $lineTerminatorInPass = true;
                        $this->pos++;
                        $this->line++;
                        $this->column = 0;
                    } elseif ($this->source[$this->pos] === "\r") {
                        $this->lineTerminatorBefore = true;
                        $lineTerminatorInPass = true;
                        $this->pos++;
                        if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                            $this->pos++;
                        }
                        $this->line++;
                        $this->column = 0;
                    } elseif (ord($this->source[$this->pos]) >= 128 && $this->isUnicodeLineTerminator()) {
                        // U+2028 LINE SEPARATOR and U+2029 PARAGRAPH SEPARATOR
                        // are line terminators per the spec (sec-line-terminators).
                        $this->lineTerminatorBefore = true;
                        $lineTerminatorInPass = true;
                        $this->pos += 3;
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

    /** Skip to the end of the current line (used by single-line comment handlers). */
    private function skipToEndOfLine(): void
    {
        while ($this->pos < $this->length) {
            $ch = $this->source[$this->pos];
            if ($ch === "\n" || $ch === "\r") {
                break;
            }
            // Also stop at Unicode line separators (U+2028, U+2029).
            if (ord($ch) >= 128 && $this->isUnicodeLineTerminator()) {
                break;
            }
            $this->pos++;
            $this->column++;
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
        $ord = ord($ch);
        if ($ord >= 0x80) {
            // Exclude Unicode whitespace, line terminators, and Format-category
            // code points that are not part of the ID_Start set (e.g. U+180E).
            if ($this->isUnicodeLineTerminator() || $this->isUnicodeWhitespace()) {
                return false;
            }
            if ($this->isNonIdentFormatChar()) {
                return false;
            }
            return true;
        }
        return ctype_alpha($ch) || $ch === '_' || $ch === '$';
    }

    private function isIdentifierPart(string $ch): bool
    {
        $ord = ord($ch);
        if ($ord >= 0x80) {
            // Exclude Unicode whitespace, line terminators, and Format-category
            // code points that are not ZWJ/ZWNJ (spec ID_Continue).
            if ($this->isUnicodeLineTerminator() || $this->isUnicodeWhitespace()) {
                return false;
            }
            if ($this->isNonIdentFormatChar()) {
                return false;
            }
            return true;
        }
        return ctype_alnum($ch) || $ch === '_' || $ch === '$';
    }

    /**
     * Detect Format-category code points that must not be treated as identifier
     * characters even though they are non-ASCII. Currently handles U+180E, the
     * Mongolian Vowel Separator, which was removed from the Space_Separator
     * category but is also not in ID_Continue.
     */
    private function isNonIdentFormatChar(): bool
    {
        if ($this->pos + 2 >= $this->length) {
            return false;
        }
        // U+180E encodes as E1 A0 8E.
        return ord($this->source[$this->pos]) === 0xE1
            && ord($this->source[$this->pos + 1]) === 0xA0
            && ord($this->source[$this->pos + 2]) === 0x8E;
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
        // 3-byte: U+1680 OGHAM SPACE MARK = E1 9A 80
        if ($b0 === 0xE1 && $this->pos + 2 < $this->length) {
            if (ord($this->source[$this->pos + 1]) === 0x9A && ord($this->source[$this->pos + 2]) === 0x80) {
                return true;
            }
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
        // After value-producing tokens, / is division. After everything else, it's regex.
        // Value-producing tokens:
        if (
            $prev->type === TokenType::Identifier
            || $prev->type === TokenType::Number
            || $prev->type === TokenType::String
            || $prev->type === TokenType::RegExp
            || $prev->type === TokenType::NoSubstitutionTemplate
            || $prev->type === TokenType::TemplateTail
            || $prev->type === TokenType::RightParen
            || $prev->type === TokenType::RightBracket
            || $prev->type === TokenType::RightBrace
            || $prev->type === TokenType::PlusPlus
            || $prev->type === TokenType::MinusMinus
            || $prev->type === TokenType::True
            || $prev->type === TokenType::False
            || $prev->type === TokenType::Null
            || $prev->type === TokenType::This
            || $prev->type === TokenType::Super
            // Contextual keywords that can be used as identifiers in expression context.
            || $prev->type === TokenType::Of
        ) {
            return false;
        }
        // After keywords that expect expressions, / is regex
        return true;
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
                    // Backslash before a line terminator is illegal in regexp.
                    $ch = $this->source[$this->pos];
                    if ($ch === "\n" || $ch === "\r" || $this->isUnicodeLineTerminator()) {
                        throw new SyntaxError('Unterminated regular expression', $start);
                    }
                    // Capture a full UTF-8 character (may be multi-byte).
                    $b0 = ord($this->source[$this->pos]);
                    if ($b0 < 0x80) {
                        $pattern .= $this->source[$this->pos];
                        $this->advance();
                    } elseif ($b0 < 0xE0) {
                        $pattern .= substr($this->source, $this->pos, 2);
                        $this->pos += 2;
                        $this->column++;
                    } elseif ($b0 < 0xF0) {
                        $pattern .= substr($this->source, $this->pos, 3);
                        $this->pos += 3;
                        $this->column++;
                    } else {
                        $pattern .= substr($this->source, $this->pos, 4);
                        $this->pos += 4;
                        $this->column++;
                    }
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

            if ($ch === "\n" || $ch === "\r" || $this->isUnicodeLineTerminator()) {
                throw new SyntaxError('Unterminated regular expression', $start);
            }

            $pattern .= $ch;
            $this->advance();
        }

        // Read flags (ASCII letters only, not locale-dependent ctype_alpha)
        $flags = '';
        while (
            $this->pos < $this->length
            && preg_match('/[a-zA-Z]/', $this->source[$this->pos])
        ) {
            $flags .= $this->source[$this->pos];
            $this->advance();
        }

        return new Token(TokenType::RegExp, "/{$pattern}/{$flags}", $start);
    }
}
