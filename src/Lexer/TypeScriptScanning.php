<?php

declare(strict_types=1);

namespace Phasis\Lexer;

/** Keep TypeScript angle lists distinct from JSX while preserving source spans. */
trait TypeScriptScanning
{
    private bool $typeScript = false;

    /** @var list<Token> Tokens belonging to a JSX element's type arguments. */
    private array $typeScriptTokens = [];

    /** @var array<int, true> Ends of TypeScript instantiation and non-null expressions. */
    private array $typeScriptExpressionEnds = [];

    /** Select TypeScript lexical disambiguation for a TypeScript source file. */
    public function setTypeScript(bool $enabled): void
    {
        $this->typeScript = $enabled;
    }

    /** Find an angle-list boundary without treating strings or comments as code. */
    private function typeScriptAngleEnd(?int $start = null, string $open = '<', string $close = '>'): ?int
    {
        $depth = 0;
        $quote = null;
        for ($i = $start ?? $this->pos; $i < $this->length; $i++) {
            $ch = $this->source[$i];
            if ($quote !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === '/' && ($this->source[$i + 1] ?? '') === '*') {
                $end = strpos($this->source, '*/', $i + 2);
                if ($end === false) {
                    return null;
                }
                $i = $end + 1;
            } elseif ($ch === '/' && ($this->source[$i + 1] ?? '') === '/') {
                $i += strcspn($this->source, "\r\n", $i) - 1;
            } elseif ($ch === $open) {
                $depth++;
            } elseif ($ch === $close && ($close !== '>' || ($this->source[$i - 1] ?? '') !== '=')) {
                if (--$depth === 0) {
                    return $i + 1;
                }
            }
        }
        return null;
    }

    private function typeScriptGenericStart(): bool
    {
        if (!$this->typeScript) {
            return false;
        }
        $end = $this->typeScriptAngleEnd();
        if ($end === null) {
            return false;
        }
        $trivia = '~\G(?:\s|/\*.*?\*/|//[^\r\n]*(?:\r\n?|\n))*~s';
        preg_match($trivia, $this->source, $matched, 0, $end);
        $start = $end + strlen($matched[0] ?? '');
        if (($this->source[$start] ?? '') !== '(') {
            return false;
        }
        $end = $this->typeScriptAngleEnd($start, '(', ')');
        if ($end === null) {
            return false;
        }
        preg_match($trivia, $this->source, $matched, 0, $end);
        $end += strlen($matched[0] ?? '');
        if (substr($this->source, $end, 2) === '=>') {
            return true;
        }
        if (($this->source[$end] ?? '') !== ':') {
            return false;
        }
        // A typed return needs a following arrow. A JSX closing tag cannot be
        // part of that type; this keeps ordinary "(text): more" JSX as text.
        $arrow = strpos($this->source, '=>', $end);
        $closingTag = strpos($this->source, '</', $end);
        return $arrow !== false && ($closingTag === false || $arrow < $closingTag);
    }

    /** Scan a JSX tag's generic arguments through the ordinary lexer. */
    private function readJsxTypeArguments(SourceLocation $start): Token
    {
        $end = $this->typeScriptAngleEnd();
        if ($end === null) {
            throw new \Phasis\Exceptions\SyntaxError('Unterminated JSX type arguments', $start);
        }
        $source = substr($this->source, $this->pos, $end - $this->pos);
        $lexer = new self($source);
        $lexer->setTypeScript(true);
        $tokens = $lexer->tokenize();
        foreach ($tokens as $token) {
            if ($token->type === TokenType::EOF) {
                continue;
            }
            $loc = $token->location;
            $this->typeScriptTokens[] = new Token(
                $token->type,
                $token->value,
                new SourceLocation(
                    $start->line + $loc->line - 1,
                    $loc->line === 1 ? $start->column + $loc->column : $loc->column,
                    $start->offset + $loc->offset,
                ),
                $token->lineTerminatorBefore,
                $token->rawValue,
                $token->cookedInvalid,
            );
        }
        while ($this->pos < $end) {
            $this->advance();
        }
        return array_shift($this->typeScriptTokens) ?? throw new \LogicException('Missing TypeScript angle token.');
    }
}
