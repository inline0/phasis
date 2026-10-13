<?php

declare(strict_types=1);

namespace Phasis\Lexer;

use Phasis\Exceptions\SyntaxError;

/**
 * JSX scanning for the Lexer. Composed into Lexer via `use JsxScanning;`.
 *
 * The lexer tokenizes the whole source in one pass, so JSX has to be
 * recognized here rather than in the parser: JSX text such as `Don't` or
 * `</div>` would otherwise be read as an unterminated string or a regex.
 *
 * A stack of frames tracks where the scanner is:
 *   - tag: between `<` and `>` (names, attribute strings, punctuation)
 *   - children: between an opening tag and its closing tag (raw text)
 *   - expr: inside `{...}`, lexed as ordinary JavaScript with brace counting
 *
 * Expression frames record how many template substitutions were open when
 * they started, so a `}` is attributed to whichever of the two (a template
 * substitution or a JSX expression container) opened last.
 */
trait JsxScanning
{
    private bool $jsx = false;

    /**
     * @var list<array{kind: 'tag'|'children'|'expr', tokens: int, closing: bool, selfClosing: bool, templates: int, depth: int}>
     */
    private array $jsxStack = [];

    /** @var array<string, string> Pragmas found in comments: jsx, jsxFrag, jsxRuntime, jsxImportSource. */
    private array $jsxPragmas = [];

    /** Enable JSX scanning. Off by default, which leaves tokenization unchanged. */
    public function setJsx(bool $jsx): void
    {
        $this->jsx = $jsx;
    }

    /** @return array<string, string> */
    public function jsxPragmas(): array
    {
        return $this->jsxPragmas;
    }

    /**
     * @param 'tag'|'children'|'expr' $kind
     * @return array{kind: 'tag'|'children'|'expr', tokens: int, closing: bool, selfClosing: bool, templates: int, depth: int}
     */
    private function newJsxFrame(string $kind): array
    {
        return [
            'kind' => $kind,
            'tokens' => 0,
            'closing' => false,
            'selfClosing' => false,
            'templates' => count($this->templateBraceDepth),
            'depth' => 0,
        ];
    }

    /**
     * True when `<` at the current position starts a JSX element: an
     * expression may start here and the `<` is followed (after optional
     * whitespace) by a name or by `>` for a fragment.
     */
    private function jsxCanStartElement(): bool
    {
        // After `}` the regex rule says "operand ended" because the brace may
        // close an object literal. For `<` the other reading wins: a JSX
        // statement after a block or declaration (`function A() {}\n<A />`)
        // is real code, while comparing an object or function literal with
        // `<` is not. Babel and esbuild both read it as JSX.
        $last = $this->tokens === [] ? null : $this->tokens[count($this->tokens) - 1];
        if (($last === null || $last->type !== TokenType::RightBrace) && !$this->canStartRegExp()) {
            return false;
        }
        $i = $this->pos + 1;
        while ($i < $this->length) {
            $c = $this->source[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $i++;
                continue;
            }
            if ($c === '>') {
                return true;
            }
            $o = ord($c);
            return $o >= 0x80 || ctype_alpha($c) || $c === '_' || $c === '$';
        }
        return false;
    }

    private function startJsxElement(SourceLocation $start): Token
    {
        $this->advance();
        $this->jsxStack[] = $this->newJsxFrame('tag');
        return new Token(TokenType::JsxTagStart, '<', $start);
    }

    /** Next token while the innermost frame is a tag or children frame. */
    private function readJsxToken(): Token
    {
        $top = count($this->jsxStack) - 1;
        if ($this->jsxStack[$top]['kind'] === 'children') {
            return $this->readJsxChildToken();
        }
        return $this->readJsxTagToken($top);
    }

    private function readJsxTagToken(int $top): Token
    {
        $this->lineTerminatorBefore = false;
        $this->skipWhitespaceAndComments();
        if ($this->pos >= $this->length) {
            throw new SyntaxError('Unterminated JSX contents.', $this->location());
        }
        $start = $this->location();
        $ch = $this->source[$this->pos];
        $frame = $this->jsxStack[$top];
        $this->jsxStack[$top]['tokens']++;

        if ($ch === '>') {
            $this->advance();
            $token = new Token(TokenType::JsxTagEnd, '>', $start, $this->lineTerminatorBefore);
            if ($frame['closing']) {
                array_pop($this->jsxStack);
                $children = array_pop($this->jsxStack);
                if ($children === null || $children['kind'] !== 'children') {
                    throw new SyntaxError('Unexpected closing JSX tag', $start);
                }
                $this->afterJsxElement();
            } elseif ($frame['selfClosing']) {
                array_pop($this->jsxStack);
                $this->afterJsxElement();
            } else {
                $this->jsxStack[$top] = $this->newJsxFrame('children');
            }
            return $token;
        }
        if ($ch === '/') {
            $this->advance();
            if ($frame['tokens'] === 0) {
                $this->jsxStack[$top]['closing'] = true;
            } else {
                $this->jsxStack[$top]['selfClosing'] = true;
            }
            return new Token(TokenType::Slash, '/', $start, $this->lineTerminatorBefore);
        }
        if ($ch === '{') {
            $this->advance();
            $this->jsxStack[] = $this->newJsxFrame('expr');
            return new Token(TokenType::LeftBrace, '{', $start, $this->lineTerminatorBefore);
        }
        if ($ch === '"' || $ch === "'") {
            return $this->readJsxString($start, $ch);
        }
        if ($ch === '<') {
            // An element used as an attribute value: `<A b=<c /> />`.
            return $this->startJsxElement($start);
        }
        $punctuator = match ($ch) {
            '=' => TokenType::Equal,
            ':' => TokenType::Colon,
            '.' => TokenType::Dot,
            default => null,
        };
        if ($punctuator !== null) {
            $this->advance();
            return new Token($punctuator, $ch, $start, $this->lineTerminatorBefore);
        }
        if ($this->isIdentifierStart($ch)) {
            return $this->readJsxName($start);
        }
        throw new SyntaxError("Unexpected character in JSX tag: {$ch}", $start);
    }

    private function readJsxChildToken(): Token
    {
        $start = $this->location();
        $ch = $this->source[$this->pos];
        if ($ch === '{') {
            $this->advance();
            $this->jsxStack[] = $this->newJsxFrame('expr');
            return new Token(TokenType::LeftBrace, '{', $start);
        }
        if ($ch === '<') {
            return $this->startJsxElement($start);
        }
        $from = $this->pos;
        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos];
            if ($c === '{' || $c === '<') {
                break;
            }
            $this->advanceTrackingLines($c);
        }
        if ($this->pos >= $this->length) {
            throw new SyntaxError('Unterminated JSX contents.', $start);
        }
        return new Token(TokenType::JsxText, substr($this->source, $from, $this->pos - $from), $start);
    }

    private function readJsxString(SourceLocation $start, string $quote): Token
    {
        $this->advance();
        $from = $this->pos;
        while ($this->pos < $this->length && $this->source[$this->pos] !== $quote) {
            $this->advanceTrackingLines($this->source[$this->pos]);
        }
        if ($this->pos >= $this->length) {
            throw new SyntaxError('Unterminated string constant.', $start);
        }
        $value = substr($this->source, $from, $this->pos - $from);
        $this->advance();
        return new Token(TokenType::JsxString, $value, $start, $this->lineTerminatorBefore, $quote);
    }

    private function readJsxName(SourceLocation $start): Token
    {
        $from = $this->pos;
        while ($this->pos < $this->length) {
            $c = $this->source[$this->pos];
            if ($c === '-' || $this->isIdentifierPart($c)) {
                $this->advance();
                continue;
            }
            break;
        }
        return new Token(TokenType::JsxName, substr($this->source, $from, $this->pos - $from), $start, $this->lineTerminatorBefore);
    }

    /** Advance one byte, keeping line and column right across line terminators. */
    private function advanceTrackingLines(string $c): void
    {
        if ($c === "\n") {
            $this->pos++;
            $this->line++;
            $this->column = 0;
            return;
        }
        if ($c === "\r") {
            $this->pos++;
            if ($this->pos < $this->length && $this->source[$this->pos] === "\n") {
                $this->pos++;
            }
            $this->line++;
            $this->column = 0;
            return;
        }
        $this->advance();
    }

    /**
     * Called when an element finishes. Back in JavaScript, a following `<`
     * that would start another element is the classic mistake of returning
     * two roots; report it the way Babel does.
     */
    private function afterJsxElement(): void
    {
        if ($this->jsxStack !== [] && $this->jsxStack[count($this->jsxStack) - 1]['kind'] !== 'expr') {
            return;
        }
        $i = $this->pos;
        $line = $this->line;
        $column = $this->column;
        while ($i < $this->length && in_array($this->source[$i], [' ', "\t", "\n", "\r"], true)) {
            if ($this->source[$i] === "\n" || ($this->source[$i] === "\r" && ($this->source[$i + 1] ?? '') !== "\n")) {
                $line++;
                $column = 0;
            } else {
                $column++;
            }
            $i++;
        }
        if ($i + 1 < $this->length && $this->source[$i] === '<') {
            $next = $this->source[$i + 1];
            if ($next === '>' || $next === '/' || ctype_alpha($next) || $next === '_' || $next === '$') {
                throw new SyntaxError(
                    'Adjacent JSX elements must be wrapped in an enclosing tag. Did you want a JSX fragment <>...</>?',
                    new SourceLocation($line, $column, $i),
                );
            }
        }
    }

    /**
     * Handle `}` when the innermost open construct is a JSX expression
     * container at brace depth 0. Returns the RightBrace token, or null when
     * the brace belongs to JavaScript (or a template substitution).
     */
    private function closeJsxExpression(): ?Token
    {
        $top = count($this->jsxStack) - 1;
        $frame = $this->jsxStack[$top];
        if (
            $frame['kind'] !== 'expr'
            || $frame['depth'] !== 0
            || $frame['templates'] !== count($this->templateBraceDepth)
        ) {
            return null;
        }
        $start = $this->location();
        $this->advance();
        array_pop($this->jsxStack);
        return new Token(TokenType::RightBrace, '}', $start, $this->lineTerminatorBefore);
    }

    /** Count braces read as JavaScript inside the innermost JSX expression container. */
    private function trackJsxExpressionBrace(Token $token): void
    {
        $top = count($this->jsxStack) - 1;
        if (
            $this->jsxStack[$top]['kind'] !== 'expr'
            || $this->jsxStack[$top]['templates'] !== count($this->templateBraceDepth)
        ) {
            return;
        }
        if ($token->type === TokenType::LeftBrace) {
            $this->jsxStack[$top]['depth']++;
        } elseif ($token->type === TokenType::RightBrace) {
            $this->jsxStack[$top]['depth']--;
        }
    }

    /** Record `@jsx`, `@jsxFrag`, `@jsxRuntime` and `@jsxImportSource` pragmas. */
    private function captureJsxPragmas(string $comment): void
    {
        if (!str_contains($comment, '@jsx')) {
            return;
        }
        if (preg_match_all('/@(jsx|jsxFrag|jsxRuntime|jsxImportSource)\s+(\S+)/', $comment, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $match) {
                $this->jsxPragmas[$match[1]] = rtrim($match[2], '*/');
            }
        }
    }
}
