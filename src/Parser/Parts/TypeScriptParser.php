<?php

declare(strict_types=1);

namespace Phasis\Parser\Parts;

use Phasis\Lexer\SourceLocation;
use Phasis\Lexer\Token;
use Phasis\Lexer\TokenType;
use Phasis\Parser\ParseError;

/** Parse erasable TypeScript syntax without changing executable source text. */
trait TypeScriptParser
{
    private bool $typeScript = false;

    /** Look through erased declarations without losing JavaScript directives. */
    private function tsHasUseStrictPrologue(): bool
    {
        $saved = $this->pos;
        $tokens = $this->tokens;
        try {
            while (!$this->isAtEnd()) {
                if ($this->tsEraseDeclaration()) {
                    continue;
                }
                $token = $this->current();
                if ($token->type !== TokenType::String) {
                    return false;
                }
                $this->advance();
                while (in_array($this->current()->value, ['as', 'satisfies'], true) && !$this->current()->lineTerminatorBefore) {
                    $this->advance();
                    $this->tsType();
                }
                $next = $this->current();
                $ends = $next->type === TokenType::Semicolon || $next->type === TokenType::RightBrace
                    || $this->isAtEnd() || ($next->lineTerminatorBefore && \Phasis\Parser\Precedence::infixFor($next->type) === 0);
                if (!$ends) {
                    return false;
                }
                if ($token->value === 'use strict' && $token->rawValue === 'verbatim') {
                    return true;
                }
                $this->eat(TokenType::Semicolon);
            }
            return false;
        } finally {
            $this->pos = $saved;
            $this->tokens = $tokens;
        }
    }

    /** Remove access/type modifiers while retaining ordinary members named alike. */
    private function tsClassModifiers(): bool
    {
        $typeOnly = false;
        $index = $this->pos;
        while (isset($this->tokens[$index + 1])) {
            $token = $this->tokens[$index];
            $next = $this->tokens[$index + 1];
            if ($token->type === TokenType::Static_) {
                $index++;
                continue;
            }
            if (
                !in_array($token->value, ['public', 'private', 'protected', 'readonly', 'abstract', 'declare', 'override'], true)
                || in_array($next->value, ['(', '<', ':', '?', '!', '=', ';', '}'], true)
                || ($next->lineTerminatorBefore && $token->value !== 'abstract')
            ) {
                break;
            }
            $typeOnly = $typeOnly || $token->value === 'declare' || $token->value === 'abstract';
            array_splice($this->tokens, $index, 1);
        }
        return $typeOnly;
    }

    private function tsTryAsyncArrow(): ?\Phasis\Ast\Node
    {
        $saved = $this->pos;
        $tokens = $this->tokens;
        $location = $this->advance()->location;
        try {
            $params = $this->parseFormalParameters();
            $this->tsAnnotation();
            if ($this->check(TokenType::Arrow)) {
                return $this->parseArrowFunctionFromParams($location, $params, true);
            }
        } catch (ParseError) {
            // An ordinary call to a value named async is still JavaScript.
        }
        $this->pos = $saved;
        $this->tokens = $tokens;
        return null;
    }

    /** Distinguish a type modifier from a value import/export named type. */
    private function tsTypeOnlySpecifier(): bool
    {
        if (!$this->checkContextual('type')) {
            return false;
        }
        $next = $this->peek();
        if ($next->value === 'as') {
            $typeOnly = in_array($this->peekAt(2)->value, ['as', ',', '}'], true);
        } else {
            $typeOnly = $this->tsIsTypeName($next);
        }
        if ($typeOnly) {
            $this->advance();
        }
        return $typeOnly;
    }

    /** Preserve comparison expressions when a speculative type argument parse fails. */
    private function tsTryTypeArguments(): bool
    {
        if (!$this->check(TokenType::LessThan) || $this->current()->lineTerminatorBefore) {
            return false;
        }
        $saved = $this->pos;
        $tokens = $this->tokens;
        try {
            $this->tsTypeParameters(false);
            $next = $this->current();
            $cannotFollow = in_array($next->type, [
                TokenType::LessThan, TokenType::GreaterThan, TokenType::RightShift,
                TokenType::UnsignedRightShift, TokenType::Plus, TokenType::Minus,
            ], true);
            $startsExpression = in_array($next->type, [
                TokenType::Identifier, TokenType::Number, TokenType::String,
                TokenType::True, TokenType::False, TokenType::Null, TokenType::This,
                TokenType::Super, TokenType::New, TokenType::Function_, TokenType::Class_,
                TokenType::Typeof, TokenType::Void, TokenType::Delete, TokenType::Bang,
                TokenType::Tilde, TokenType::PlusPlus, TokenType::MinusMinus,
                TokenType::Async, TokenType::Await, TokenType::LeftBracket,
            ], true);
            if (
                $cannotFollow || ($startsExpression && !$next->lineTerminatorBefore
                && !in_array($next->value, ['as', 'satisfies', 'implements'], true))
            ) {
                throw new ParseError('Not a TypeScript instantiation expression', $this->current());
            }
            return true;
        } catch (ParseError) {
            $this->pos = $saved;
            $this->tokens = $tokens;
            return false;
        }
    }

    private function tsParseAngleExpression(): \Phasis\Ast\Node
    {
        $saved = $this->pos;
        $tokens = $this->tokens;
        $location = $this->current()->location;
        try {
            $disambiguated = $this->tsTypeParameters();
            if ($this->jsxOptions !== null && !$disambiguated) {
                throw new ParseError('Generic TSX arrows require a trailing comma, constraint, default, or const modifier', $this->current());
            }
            $params = $this->parseFormalParameters();
            $this->tsAnnotation();
            if (!$this->check(TokenType::Arrow)) {
                throw new ParseError('Expected generic arrow', $this->current());
            }
            return $this->parseArrowFunctionFromParams($location, $params, false);
        } catch (ParseError $error) {
            $this->pos = $saved;
            $this->tokens = $tokens;
            if ($this->jsxOptions !== null) {
                throw $error;
            }
        }
        $this->expect(TokenType::LessThan);
        $this->tsType();
        $this->tsCloseAngle();
        return $this->parseUnaryExpression();
    }

    /**
     * Whether a filename selects TypeScript syntax automatically.
     * @param string $path Source filename.
     * @return bool Whether the extension denotes TypeScript.
     */
    public static function isTypeScriptPath(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['ts', 'tsx', 'mts', 'cts'], true);
    }

    private function tsUnsupported(): never
    {
        throw new ParseError('TypeScript syntax requires runtime transformation and cannot be erased', $this->current());
    }

    /** Consume one closing angle, leaving the rest of a shift/assignment token. */
    private function tsCloseAngle(): void
    {
        $token = $this->current();
        if (!str_starts_with($token->value, '>')) {
            throw new ParseError("Expected '>' in TypeScript type", $token);
        }
        if ($token->value === '>') {
            $this->advance();
            return;
        }
        $rest = substr($token->value, 1);
        $location = $token->location;
        while (isset($this->tokens[$this->pos + 1])) {
            $next = $this->tokens[$this->pos + 1];
            if (
                $next->location->offset !== $location->offset + 1 + strlen($rest)
                || TokenType::tryFrom($rest . $next->value) === null
            ) {
                break;
            }
            $rest .= $next->value;
            array_splice($this->tokens, $this->pos + 1, 1);
        }
        $this->tokens[$this->pos] = new Token(
            TokenType::from($rest),
            $rest,
            new SourceLocation($location->line, $location->column + 1, $location->offset + 1),
        );
    }

    private function tsAtCloseAngle(): bool
    {
        return str_starts_with($this->current()->value, '>');
    }

    /** Consume generic parameters or arguments using the type grammar. */
    private function tsTypeParameters(bool $declaration = true): bool
    {
        $disambiguated = false;
        $this->expect(TokenType::LessThan);
        if ($this->tsAtCloseAngle()) {
            throw new ParseError('Empty TypeScript type parameter list', $this->current());
        }
        do {
            if ($declaration) {
                while (
                    $this->check(TokenType::Const_)
                    || (in_array($this->current()->value, ['in', 'out'], true) && $this->peekIs(TokenType::Identifier))
                ) {
                    $disambiguated = $disambiguated || $this->check(TokenType::Const_);
                    $this->advance();
                }
                $this->tsTypeName();
                if ($this->eat(TokenType::Extends)) {
                    $disambiguated = true;
                    $this->tsType(false);
                }
                if ($this->eat(TokenType::Equal)) {
                    $disambiguated = true;
                    $this->tsType();
                }
            } else {
                $this->tsType();
            }
            if (!$this->eat(TokenType::Comma)) {
                break;
            }
            $disambiguated = true;
            if ($this->tsAtCloseAngle()) {
                break;
            }
        } while (!$this->isAtEnd());
        $this->tsCloseAngle();
        return $disambiguated;
    }

    private function tsIsTypeName(Token $token): bool
    {
        return $token->type === TokenType::Identifier || $token->type->isKeyword();
    }

    private function tsTypeName(): void
    {
        if (!$this->tsIsTypeName($this->current()) && $this->current()->type !== TokenType::String && $this->current()->type !== TokenType::Number) {
            throw new ParseError('Expected TypeScript type name', $this->current());
        }
        $this->advance();
    }

    /** Parse conditional, union and intersection types with proper boundaries. */
    private function tsType(bool $conditional = true): void
    {
        $this->eat(TokenType::Pipe);
        $this->eat(TokenType::Ampersand);
        $this->tsTypePrimary();
        while ($this->check(TokenType::Pipe) || $this->check(TokenType::Ampersand)) {
            $this->advance();
            $this->tsTypePrimary();
        }
        if ($conditional && !$this->current()->lineTerminatorBefore && $this->eat(TokenType::Extends)) {
            $this->tsType(false);
            $this->expect(TokenType::Question);
            $this->tsType();
            $this->expect(TokenType::Colon);
            $this->tsType();
        }
    }

    private function tsTypePrimary(): void
    {
        $token = $this->current();
        if ($token->value === 'abstract' && $this->peekIs(TokenType::New)) {
            $this->advance();
            $this->tsTypePrimary();
            return;
        }
        $genericAllowed = $this->tsIsTypeName($token) && !in_array($token->value, [
            'any', 'unknown', 'never', 'void', 'undefined', 'null', 'boolean',
            'number', 'bigint', 'string', 'symbol', 'object', 'true', 'false', 'const', 'this',
        ], true);
        if (in_array($token->value, ['keyof', 'readonly', 'unique', 'typeof', 'infer', 'asserts'], true)) {
            $this->advance();
            $this->tsTypePrimary();
            if ($token->value === 'infer' && $this->eat(TokenType::Extends)) {
                $this->tsType(false);
            }
            return;
        }
        if ($this->eat(TokenType::Minus)) {
            $this->expect(TokenType::Number);
        } elseif ($this->eat(TokenType::New)) {
            if ($this->check(TokenType::LessThan)) {
                $this->tsTypeParameters();
            }
            $this->tsTypeSignature();
            $this->expect(TokenType::Arrow);
            $this->tsType();
        } elseif ($this->check(TokenType::LessThan)) {
            $this->tsTypeParameters();
            $this->tsTypeSignature();
            $this->expect(TokenType::Arrow);
            $this->tsType();
        } elseif ($this->check(TokenType::LeftParen)) {
            $saved = $this->pos;
            try {
                $this->tsTypeSignature();
                $this->expect(TokenType::Arrow);
                $this->tsType();
            } catch (ParseError) {
                $this->pos = $saved;
                $this->expect(TokenType::LeftParen);
                $this->tsType();
                $this->expect(TokenType::RightParen);
            }
        } elseif ($this->check(TokenType::LeftBrace)) {
            $this->tsTypeMembers();
        } elseif ($this->eat(TokenType::LeftBracket)) {
            while (!$this->check(TokenType::RightBracket) && !$this->isAtEnd()) {
                $this->eat(TokenType::Ellipsis);
                if ($this->peekIs(TokenType::Colon) || ($this->peekIs(TokenType::Question) && $this->peekAt(2)->type === TokenType::Colon)) {
                    $this->tsTypeName();
                    $this->eat(TokenType::Question);
                    $this->expect(TokenType::Colon);
                }
                $this->tsType();
                $this->eat(TokenType::Question);
                if (!$this->eat(TokenType::Comma)) {
                    break;
                }
            }
            $this->expect(TokenType::RightBracket);
        } elseif ($token->type === TokenType::TemplateHead) {
            $this->advance();
            while (true) {
                $this->tsType();
                $part = $this->advance();
                if ($part->type === TokenType::TemplateTail) {
                    break;
                }
                if ($part->type !== TokenType::TemplateMiddle) {
                    throw new ParseError('Expected template literal type continuation', $part);
                }
            }
        } elseif ($token->type === TokenType::NoSubstitutionTemplate) {
            $this->advance();
        } elseif ($this->eat(TokenType::Import)) {
            $this->expect(TokenType::LeftParen);
            $this->expect(TokenType::String);
            if ($this->eat(TokenType::Comma)) {
                $this->parseAssignmentExpression();
            }
            $this->expect(TokenType::RightParen);
        } else {
            $this->tsTypeName();
        }
        while (true) {
            if ($this->eat(TokenType::Dot)) {
                $this->tsTypeName();
                $genericAllowed = true;
            } elseif ($genericAllowed && $this->check(TokenType::LessThan) && !$this->current()->lineTerminatorBefore) {
                $this->tsTypeParameters(false);
                $genericAllowed = false;
            } elseif ($this->eat(TokenType::LeftBracket)) {
                if (!$this->check(TokenType::RightBracket)) {
                    $this->tsType();
                }
                $this->expect(TokenType::RightBracket);
                $genericAllowed = false;
            } else {
                break;
            }
        }
        if ($this->checkContextual('is') && !$this->current()->lineTerminatorBefore) {
            $this->advance();
            $this->tsType();
        }
    }

    private function tsTypeSignature(): void
    {
        $this->expect(TokenType::LeftParen);
        while (!$this->check(TokenType::RightParen) && !$this->isAtEnd()) {
            $this->eat(TokenType::Ellipsis);
            if (!$this->eat(TokenType::This)) {
                $this->parseBindingPattern();
            }
            $this->eat(TokenType::Question);
            if ($this->eat(TokenType::Colon)) {
                $this->tsType();
            }
            if (!$this->eat(TokenType::Comma)) {
                break;
            }
        }
        $this->expect(TokenType::RightParen);
    }

    private function tsTypeMembers(): void
    {
        $this->expect(TokenType::LeftBrace);
        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            if ($this->eat(TokenType::Semicolon) || $this->eat(TokenType::Comma)) {
                continue;
            }
            if (
                in_array($this->current()->value, ['get', 'set'], true)
                && $this->tsIsTypeName($this->peek()) && $this->peekAt(2)->type === TokenType::LeftParen
            ) {
                $this->advance();
            }
            if ($this->checkContextual('readonly') && !in_array($this->peek()->value, [':', '?', '(', '<'], true)) {
                $this->advance();
            } elseif (($this->check(TokenType::Plus) || $this->check(TokenType::Minus)) && $this->peek()->value === 'readonly') {
                $this->advance();
                $this->advance();
            }
            if ($this->eat(TokenType::LeftBracket)) {
                $this->tsTypeName();
                if ($this->eat(TokenType::In)) {
                    $this->tsType();
                    if ($this->checkContextual('as')) {
                        $this->advance();
                        $this->tsType();
                    }
                } elseif ($this->eat(TokenType::Colon)) {
                    $this->tsType();
                } else {
                    while ($this->eat(TokenType::Dot)) {
                        $this->tsTypeName();
                    }
                }
                $this->expect(TokenType::RightBracket);
            } elseif (!$this->check(TokenType::LeftParen) && !$this->check(TokenType::LessThan)) {
                $this->tsTypeName();
            }
            if ($this->check(TokenType::Plus) || $this->check(TokenType::Minus)) {
                $this->advance();
            }
            $this->eat(TokenType::Question);
            if ($this->check(TokenType::LessThan)) {
                $this->tsTypeParameters();
            }
            if ($this->check(TokenType::LeftParen)) {
                $this->tsTypeSignature();
            }
            if ($this->eat(TokenType::Colon)) {
                $this->tsType();
            }
            if (
                !$this->eat(TokenType::Semicolon) && !$this->eat(TokenType::Comma)
                && !$this->check(TokenType::RightBrace) && !$this->current()->lineTerminatorBefore
            ) {
                throw new ParseError('Expected TypeScript type member separator', $this->current());
            }
        }
        $this->expect(TokenType::RightBrace);
    }

    private function tsAnnotation(bool $optional = false): void
    {
        if (!$this->typeScript) {
            return;
        }
        if ($optional) {
            $this->eat(TokenType::Question);
        }
        if ($this->eat(TokenType::Colon)) {
            $this->tsType();
        }
    }

    /** Consume declarations that introduce no runtime binding or side effect. */
    private function tsEraseDeclaration(bool $ambientContext = false): bool
    {
        if (!$this->typeScript) {
            return false;
        }
        $saved = $this->pos;
        $exported = $this->eat(TokenType::Export);
        $fileLevel = ($this->moduleMode && $this->moduleTopLevel)
            || ($this->commonJsWrapper && $this->blockDepth === 1);
        if ($exported && !$fileLevel) {
            throw new ParseError('Type-only exports require file top level', $this->current());
        }
        if ($exported) {
            $this->eat(TokenType::Default_);
        }
        $declared = $this->checkContextual('declare') && !$this->peek()->lineTerminatorBefore;
        $ambient = $ambientContext || $declared;
        if ($declared) {
            $this->advance();
        }
        $kind = $this->current()->value;
        if ($kind === 'enum' || ($kind === 'const' && $this->peek()->value === 'enum')) {
            if (!$ambient) {
                $this->tsUnsupported();
            }
            $this->eat(TokenType::Const_);
            $this->expectContextual('enum');
            $this->tsTypeName();
            $this->expect(TokenType::LeftBrace);
            while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
                $this->tsTypeName();
                if ($this->eat(TokenType::Equal)) {
                    $this->parseAssignmentExpression();
                }
                if (!$this->eat(TokenType::Comma)) {
                    break;
                }
            }
            $this->expect(TokenType::RightBrace);
            return true;
        }
        $declarationContext = $exported || $ambient || !$this->peek()->lineTerminatorBefore;
        if (
            $kind === 'type' && $declarationContext
            && $this->tsIsTypeName($this->peek()) && $this->peek()->value !== 'from'
        ) {
            $this->advance();
            $this->tsTypeName();
            if ($this->check(TokenType::LessThan)) {
                $this->tsTypeParameters();
            }
            $this->expect(TokenType::Equal);
            $this->tsType();
            $this->consumeSemicolon();
            return true;
        }
        if ($kind === 'interface' && $declarationContext && $this->tsIsTypeName($this->peek())) {
            $this->advance();
            $this->tsTypeName();
            if ($this->check(TokenType::LessThan)) {
                $this->tsTypeParameters();
            }
            if ($this->eat(TokenType::Extends)) {
                do {
                    $this->tsType();
                } while ($this->eat(TokenType::Comma));
            }
            $this->tsTypeMembers();
            return true;
        }
        if ($exported && $kind === 'type' && in_array($this->peek()->type, [TokenType::LeftBrace, TokenType::Star], true)) {
            $this->advance();
            $hasSource = false;
            if ($this->eat(TokenType::Star)) {
                if ($this->checkContextual('as')) {
                    $this->advance();
                    $this->tsTypeName();
                }
                $this->expectContextual('from');
                $this->expect(TokenType::String);
                $hasSource = true;
            } else {
                $this->parseExportSpecifiers();
                if ($this->checkContextual('from')) {
                    $this->advance();
                    $this->expect(TokenType::String);
                    $hasSource = true;
                }
            }
            if ($hasSource && ($this->check(TokenType::With) || $this->checkContextual('assert'))) {
                $this->advance();
                $this->parseAttributeClause();
            }
            $this->consumeSemicolon();
            return true;
        }
        if (
            !$exported && $kind === 'import' && $this->peek()->value === 'type'
            && $this->peekAt(2)->value !== 'from' && $this->peekAt(2)->type !== TokenType::Equal
        ) {
            if (!$fileLevel) {
                throw new ParseError('Type-only imports require module top level', $this->current());
            }
            $this->advance();
            $this->advance();
            if ($this->check(TokenType::LeftBrace)) {
                $this->parseNamedImports();
            } elseif ($this->eat(TokenType::Star)) {
                $this->expectContextual('as');
                $this->tsTypeName();
            } else {
                $this->tsTypeName();
                if ($this->eat(TokenType::Equal)) {
                    $this->parseAssignmentExpression();
                    $this->consumeSemicolon();
                    return true;
                }
            }
            $this->expectContextual('from');
            $this->expect(TokenType::String);
            if ($this->check(TokenType::With) || $this->checkContextual('assert')) {
                $this->advance();
                $this->parseAttributeClause();
            }
            $this->consumeSemicolon();
            return true;
        }
        if (
            (($kind === 'namespace' || ($kind === 'module' && $declarationContext))
                && ($this->tsIsTypeName($this->peek()) || $this->peekIs(TokenType::String)))
            || ($kind === 'global' && $ambient)
        ) {
            $this->advance();
            if ($kind !== 'global') {
                $this->tsTypeName();
                while ($this->eat(TokenType::Dot)) {
                    $this->tsTypeName();
                }
            }
            $this->expect(TokenType::LeftBrace);
            while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
                if (!$ambient && $this->check(TokenType::Import)) {
                    $this->tsUnsupported();
                }
                if (!$this->tsEraseDeclaration($ambient) && !$this->eat(TokenType::Semicolon)) {
                    $this->tsUnsupported();
                }
            }
            $this->expect(TokenType::RightBrace);
            return true;
        }
        if ($ambient) {
            if (in_array($kind, ['const', 'let', 'var'], true)) {
                $this->advance();
                do {
                    $this->parseBindingPattern();
                    $this->tsAnnotation();
                    if ($this->eat(TokenType::Equal)) {
                        $this->parseAssignmentExpression();
                    }
                } while ($this->eat(TokenType::Comma));
                $this->consumeSemicolon();
            } elseif ($kind === 'function') {
                $this->advance();
                $this->eat(TokenType::Star);
                $this->tsTypeName();
                if ($this->check(TokenType::LessThan)) {
                    $this->tsTypeParameters();
                }
                $this->tsTypeSignature();
                $this->tsAnnotation();
                $this->consumeSemicolon();
            } elseif ($kind === 'class') {
                $this->parseClassDeclaration();
            } else {
                throw new ParseError('Unsupported ambient TypeScript declaration', $this->current());
            }
            return true;
        }
        if ($kind === 'function' || ($kind === 'async' && $this->peekIs(TokenType::Function_))) {
            $tokens = $this->tokens;
            try {
                if ($kind === 'async') {
                    $this->advance();
                }
                $this->expect(TokenType::Function_);
                $this->eat(TokenType::Star);
                $this->tsTypeName();
                if ($this->check(TokenType::LessThan)) {
                    $this->tsTypeParameters();
                }
                $this->tsTypeSignature();
                $this->tsAnnotation();
                if (!$this->check(TokenType::LeftBrace)) {
                    $this->consumeSemicolon();
                    return true;
                }
            } catch (ParseError) {
                // Default parameter expressions belong to the executable
                // function parser. This lookahead only recognizes signatures.
            }
            $this->tokens = $tokens;
        }
        $this->pos = $saved;
        return false;
    }
}
