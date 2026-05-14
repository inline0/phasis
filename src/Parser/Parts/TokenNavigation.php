<?php

declare(strict_types=1);

namespace Phasis\Parser\Parts;

use Phasis\Ast\Declaration\ClassDeclaration;
use Phasis\Ast\Declaration\ExportDeclaration;
use Phasis\Ast\Declaration\ExportSpecifier;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\ImportDeclaration;
use Phasis\Ast\Declaration\ImportSpecifier;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Declaration\VariableDeclarator;
use Phasis\Ast\Expression\ArrayExpression;
use Phasis\Ast\Expression\ArrowFunction;
use Phasis\Ast\Expression\AssignmentExpression;
use Phasis\Ast\Expression\AwaitExpression;
use Phasis\Ast\Expression\BinaryExpression;
use Phasis\Ast\Expression\CallExpression;
use Phasis\Ast\Expression\ClassExpression;
use Phasis\Ast\Expression\ClassMethod;
use Phasis\Ast\Expression\ClassProperty;
use Phasis\Ast\Expression\PrivateIdentifier;
use Phasis\Ast\Expression\StaticBlock;
use Phasis\Ast\Expression\ConditionalExpression;
use Phasis\Ast\Expression\ImportExpression;
use Phasis\Ast\Expression\MetaProperty;
use Phasis\Ast\Expression\FunctionExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Expression\LogicalExpression;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\NewExpression;
use Phasis\Ast\Expression\ObjectExpression;
use Phasis\Ast\Expression\Property;
use Phasis\Ast\Expression\SequenceExpression;
use Phasis\Ast\Expression\SpreadElement;
use Phasis\Ast\Expression\TemplateLiteral;
use Phasis\Ast\Expression\TemplateElement;
use Phasis\Ast\Expression\TaggedTemplate;
use Phasis\Ast\Expression\ThisExpression;
use Phasis\Ast\Expression\UnaryExpression;
use Phasis\Ast\Expression\UpdateExpression;
use Phasis\Ast\Expression\YieldExpression;
use Phasis\Ast\Node;
use Phasis\Ast\Pattern\ArrayPattern;
use Phasis\Ast\Pattern\AssignmentPattern;
use Phasis\Ast\Pattern\AssignmentProperty;
use Phasis\Ast\Pattern\ObjectPattern;
use Phasis\Ast\Pattern\RestElement;
use Phasis\Ast\Program;
use Phasis\Ast\Statement\BlockStatement;
use Phasis\Ast\Statement\BreakStatement;
use Phasis\Ast\Statement\CatchClause;
use Phasis\Ast\Statement\ContinueStatement;
use Phasis\Ast\Statement\DebuggerStatement;
use Phasis\Ast\Statement\DoWhileStatement;
use Phasis\Ast\Statement\EmptyStatement;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Ast\Statement\ForInStatement;
use Phasis\Ast\Statement\ForOfStatement;
use Phasis\Ast\Statement\ForStatement;
use Phasis\Ast\Statement\IfStatement;
use Phasis\Ast\Statement\LabeledStatement;
use Phasis\Ast\Statement\ReturnStatement;
use Phasis\Ast\Statement\SwitchCase;
use Phasis\Ast\Statement\SwitchStatement;
use Phasis\Ast\Statement\ThrowStatement;
use Phasis\Ast\Statement\TryStatement;
use Phasis\Ast\Statement\WhileStatement;
use Phasis\Ast\Statement\WithStatement;
use Phasis\Parser\ParseError;
use Phasis\Lexer\Lexer;
use Phasis\Lexer\SourceLocation;
use Phasis\Lexer\Token;
use Phasis\Lexer\TokenType;

/**
 * Parser part: TokenNavigation. Composed into Parser via
 * `use Parts\TokenNavigation;`. `self::`/`$this->` references resolve
 * into the composing class.
 */
trait TokenNavigation
{
    // -------------------------------------------------------------------------
    // Token navigation
    // -------------------------------------------------------------------------

    /** @phpstan-impure */
    private function current(): Token
    {
        return $this->tokens[$this->pos];
    }

    /** @phpstan-impure */
    private function peek(): Token
    {
        return $this->tokens[$this->pos + 1] ?? $this->tokens[$this->pos];
    }

    /** Look ahead $n tokens past the current position. */
    private function peekAt(int $n): Token
    {
        return $this->tokens[$this->pos + $n] ?? $this->tokens[$this->pos];
    }

    /** @phpstan-impure */
    private function advance(): Token
    {
        $token = $this->tokens[$this->pos];
        $this->pos++;
        return $token;
    }

    /** @phpstan-impure */
    private function check(TokenType $type): bool
    {
        return $this->current()->type === $type;
    }

    /** @phpstan-impure */
    private function checkContextual(string $name): bool
    {
        $token = $this->current();
        return $token->type === TokenType::Identifier && $token->value === $name;
    }

    private function expectContextual(string $name): Token
    {
        $token = $this->current();
        if ($token->type !== TokenType::Identifier || $token->value !== $name) {
            throw new ParseError("Expected '{$name}'", $token);
        }
        return $this->advance();
    }

    /** @phpstan-impure */
    private function peekIs(TokenType $type): bool
    {
        return $this->peek()->type === $type;
    }

    /**
     * Re-tokenize a RegExp token at $index as a sequence of expression
     * tokens beginning with a Slash. Used when the surrounding context
     * (e.g. `await` or `yield` as identifier in non-async/non-generator
     * code) means the lexer's eager regex classification is wrong.
     *
     * The original Token is consumed; the splice of replacement tokens
     * preserves the rest of the stream.
     */
    private function reinterpretRegExpAsDivision(int $index): bool
    {
        if (!isset($this->tokens[$index]) || $this->tokens[$index]->type !== TokenType::RegExp) {
            return false;
        }
        $token = $this->tokens[$index];
        // Prepend `0` to make the first `/` lex as division. Then drop
        // the synthetic Number token; everything after is the real
        // expression-tokenization of the regex source.
        $sub = new Lexer('0' . $token->value);
        try {
            $subTokens = $sub->tokenize();
        } catch (\Throwable) {
            return false;
        }
        // Strip the synthetic Number(0) and the trailing EOF.
        if (
            ($subTokens[0]->type ?? null) !== TokenType::Number
            || $subTokens[0]->value !== '0'
        ) {
            return false;
        }
        array_shift($subTokens);
        $eof = end($subTokens);
        if ($eof !== false && $eof->type === TokenType::EOF) {
            array_pop($subTokens);
        }
        if ($subTokens === []) {
            return false;
        }
        $loc = $token->location;
        $relocated = [];
        foreach ($subTokens as $st) {
            $relocated[] = new Token(
                $st->type,
                $st->value,
                $loc,
                $st === $subTokens[0] ? $token->lineTerminatorBefore : false,
                $st->rawValue,
                $st->cookedInvalid,
            );
        }
        array_splice($this->tokens, $index, 1, $relocated);
        return true;
    }

    /** @phpstan-impure */
    private function eat(TokenType $type): bool
    {
        if ($this->check($type)) {
            $this->advance();
            return true;
        }
        return false;
    }

    private function expect(TokenType $type): Token
    {
        if (!$this->check($type)) {
            throw new ParseError("Expected {$type->value}", $this->current());
        }
        return $this->advance();
    }

    private function isAtEnd(): bool
    {
        return $this->current()->type === TokenType::EOF;
    }

    /** Automatic Semicolon Insertion. */
    private function consumeSemicolon(): void
    {
        if ($this->eat(TokenType::Semicolon)) {
            return;
        }

        // ASI: insert semicolon if line terminator before current token,
        // or current token is }, or at end of input.
        if (
            $this->current()->lineTerminatorBefore
            || $this->check(TokenType::RightBrace)
            || $this->isAtEnd()
        ) {
            return;
        }

        throw new ParseError('Expected semicolon', $this->current());
    }

    private function rescanSlashAsRegExp(): void
    {
        $token = $this->tokens[$this->pos];
        $offset = $token->location->offset;
        $src = $this->source;
        $len = strlen($src);
        if ($offset >= $len || $src[$offset] !== '/') {
            return;
        }
        $i = $offset + 1;
        $pattern = '';
        $inCharClass = false;
        while ($i < $len) {
            $ch = $src[$i];
            if ($ch === '\\') {
                $pattern .= $ch;
                $i++;
                if ($i < $len) {
                    if ($src[$i] === "\n" || $src[$i] === "\r") {
                        return;
                    }
                    $pattern .= $src[$i];
                    $i++;
                }
                continue;
            }
            if ($ch === '[') {
                $inCharClass = true;
                $pattern .= $ch;
                $i++;
                continue;
            }
            if ($ch === ']' && $inCharClass) {
                $inCharClass = false;
                $pattern .= $ch;
                $i++;
                continue;
            }
            if ($ch === '/' && !$inCharClass) {
                $i++;
                break;
            }
            if ($ch === "\n" || $ch === "\r") {
                return;
            }
            $pattern .= $ch;
            $i++;
        }
        $flags = '';
        while ($i < $len && preg_match('/[a-zA-Z]/', $src[$i])) {
            $flags .= $src[$i];
            $i++;
        }
        $regExpToken = new Token(
            TokenType::RegExp,
            '/' . $pattern . '/' . $flags,
            $token->location,
            $token->lineTerminatorBefore,
        );
        $removeCount = 0;
        for ($j = $this->pos; $j < count($this->tokens); $j++) {
            if ($this->tokens[$j]->location->offset >= $i) {
                break;
            }
            $removeCount++;
        }
        if ($removeCount < 1) {
            $removeCount = 1;
        }
        array_splice($this->tokens, $this->pos, $removeCount, [$regExpToken]);
    }
}
