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
 * Parser part: StatementParser. Composed into Parser via
 * `use Parts\StatementParser;`. `self::`/`$this->` references resolve
 * into the composing class.
 */
trait StatementParser
{
    // -------------------------------------------------------------------------
    // Statements and declarations
    // -------------------------------------------------------------------------

    private function parseStatementOrDeclaration(): Node
    {
        $token = $this->current();

        // In sloppy mode, `let` is only a keyword when followed by an identifier,
        // `[`, or `{` (binding pattern starts). Otherwise treat it as an identifier.
        if ($token->type === TokenType::Let) {
            $next = $this->peek();
            $isDeclaration = $next->type === TokenType::Identifier
                || $next->type === TokenType::LeftBracket
                || $next->type === TokenType::LeftBrace
                || $next->type === TokenType::Yield
                || $next->type === TokenType::Await
                || $next->type === TokenType::Let
                || $next->type === TokenType::Async
                || $next->type === TokenType::Static_
                || $next->type === TokenType::Of;
            if ($isDeclaration) {
                return $this->parseVariableDeclaration();
            }
            return $this->parseStatement();
        }

        // `using` as a declaration keyword: `using x = expr`
        if ($token->type === TokenType::Identifier && $token->value === 'using') {
            $next = $this->peek();
            if (
                $next->type === TokenType::Identifier
                || $next->type === TokenType::Await
                || $next->type === TokenType::Yield
                || $next->type === TokenType::Let
                || $next->type === TokenType::Async
                || $next->type === TokenType::Static_
                || $next->type === TokenType::Of
            ) {
                return $this->parseUsingDeclaration(false);
            }
        }

        // `await using x = expr` (top-level or inside async)
        if ($token->type === TokenType::Await) {
            $next = $this->peek();
            if ($next->type === TokenType::Identifier && $next->value === 'using') {
                $savedPos = $this->pos;
                $this->advance(); // consume 'await'
                $this->advance(); // consume 'using'
                $afterUsing = $this->current();
                $this->pos = $savedPos;
                if (
                    $afterUsing->type === TokenType::Identifier
                    || $afterUsing->type === TokenType::Await
                    || $afterUsing->type === TokenType::Yield
                    || $afterUsing->type === TokenType::Let
                    || $afterUsing->type === TokenType::Async
                    || $afterUsing->type === TokenType::Static_
                    || $afterUsing->type === TokenType::Of
                ) {
                    $this->advance(); // consume 'await'
                    return $this->parseUsingDeclaration(true);
                }
            }
        }

        // Decorators: @expr before class declaration
        if ($token->type === TokenType::At) {
            $decorators = $this->parseDecoratorList();
            return $this->parseClassDeclaration($decorators);
        }

        return match ($token->type) {
            TokenType::Var, TokenType::Const_ => $this->parseVariableDeclaration(),
            TokenType::Function_ => $this->parseFunctionDeclaration(),
            TokenType::Class_ => $this->parseClassDeclaration(),
            TokenType::Async => $this->maybeParseAsyncFunction(),
            TokenType::Import => $this->parseImportDeclarationOrExpression(),
            TokenType::Export => $this->parseExportDeclaration(),
            default => $this->parseStatement(),
        };
    }

    private function parseStatement(): Node
    {
        $token = $this->current();

        // Declaration-kinds that are never allowed in single-statement
        // position (e.g. `if (x) class C {}`, `if (x) function* g() {}`,
        // `if (x) async function f() {}`) are a parse-time SyntaxError.
        // Plain FunctionDeclaration in sloppy mode is tolerated via
        // Annex B but still disallowed for generator/async forms or in
        // strict mode.
        if ($token->type === TokenType::Class_) {
            throw new ParseError(
                "Class declarations may not appear in single-statement position",
                $token,
            );
        }
        if (
            $token->type === TokenType::Function_
            && $this->peek()->type === TokenType::Star
        ) {
            throw new ParseError(
                "Generator declarations may not appear in single-statement position",
                $token,
            );
        }
        if ($token->type === TokenType::Function_) {
            // In sloppy mode, Annex B allows plain FunctionDeclarations only
            // in the `if (x) f();` / `if (x) f(); else g();` forms — those
            // bypass parseStatement via parseIfBodyStatement. Anywhere else
            // (for/while/do-while/labeled body, etc.) a function declaration
            // is a parse-time SyntaxError.
            throw new ParseError(
                "Function declarations may not appear in single-statement position",
                $token,
            );
        }
        if (
            $token->type === TokenType::Async
            && $this->peek()->type === TokenType::Function_
            && !$this->peek()->lineTerminatorBefore
        ) {
            throw new ParseError(
                "Async function declarations may not appear in single-statement position",
                $token,
            );
        }
        // Per spec 12.6: ReservedWord matching is over the source code points,
        // so `do` written as `do` is not the keyword `do`. Reject the
        // escaped form here for the strictly reserved keywords; contextual
        // keywords (let/static/async/yield/await/of) keep their dual role and
        // are validated at their specific call sites.
        if ($token->rawValue === 'escaped' && self::isStrictlyReservedKeyword($token->type)) {
            throw new ParseError(
                "Keyword '{$token->value}' must not contain escaped characters",
                $token,
            );
        }
        // Bare `let [` pattern in expression position is a SyntaxError (spec
        // lookahead restriction) except when starting a let-declaration;
        // since parseStatement is for Statements only, a let-declaration is
        // not allowed here at all. `let` as identifier or label is fine.
        return match ($token->type) {
            // var is a VariableStatement, which is a Statement per spec.
            // let/const are LexicalDeclarations (Declarations) and may only
            // appear as loop/if bodies when wrapped in a BlockStatement.
            TokenType::Var => $this->parseVariableDeclaration(),
            TokenType::LeftBrace => $this->parseBlockStatement(),
            TokenType::If => $this->parseIfStatement(),
            TokenType::For => $this->parseForStatement(),
            TokenType::While => $this->parseWhileStatement(),
            TokenType::Do => $this->parseDoWhileStatement(),
            TokenType::Switch => $this->parseSwitchStatement(),
            TokenType::Return => $this->parseReturnStatement(),
            TokenType::Throw => $this->parseThrowStatement(),
            TokenType::Try => $this->parseTryStatement(),
            TokenType::Break => $this->parseBreakStatement(),
            TokenType::Continue => $this->parseContinueStatement(),
            TokenType::Semicolon => $this->parseEmptyStatement(),
            TokenType::Debugger => $this->parseDebuggerStatement(),
            TokenType::With => $this->parseWithStatement(),
            default => $this->parseExpressionOrLabeledStatement(),
        };
    }

    private function parseBlockStatement(bool $isFunctionBody = false): BlockStatement
    {
        $location = $this->expect(TokenType::LeftBrace)->location;
        $body = [];

        // Function bodies may begin with a "use strict" prologue, which
        // makes the body parse in strict mode (relevant for early errors
        // on identifier references, parameter names, etc.).
        $prevStrict = $this->strictMode;
        if ($isFunctionBody && !$this->strictMode && $this->blockStartHasUseStrictPrologue()) {
            $this->strictMode = true;
        }

        // Blocks inside a module are not module top-level for the purposes
        // of import/export placement — but `topLevel` is still true here
        // because top-level await/yield reservation depends on it. Track
        // module-top-level separately.
        $prevModuleTopLevel = $this->moduleTopLevel;
        $this->moduleTopLevel = false;
        // Cover-init / __proto__-duplicate deferral is per-AssignmentExpression
        // and must not cross function boundaries: a return-statement inside a
        // nested function body parses its own AssignmentExpressions, and any
        // ObjectLiteral inside should run its early-error checks immediately
        // instead of waiting for an enclosing assignment that doesn't exist.
        $prevAllowCoverInit = $this->allowCoverInit;
        if ($isFunctionBody) {
            $this->allowCoverInit = false;
        }
        try {
            while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
                $body[] = $this->parseStatementOrDeclaration();
            }
        } finally {
            $this->moduleTopLevel = $prevModuleTopLevel;
            $this->strictMode = $prevStrict;
            $this->allowCoverInit = $prevAllowCoverInit;
        }

        $this->expect(TokenType::RightBrace);
        // Function body and Script body use TopLevelLexicallyDeclaredNames,
        // which excludes HoistableDeclaration. The duplicate check on those
        // is handled separately (or is a no-op for the current relaxed
        // semantics). Only nested blocks use the strict LexicallyDeclared
        // vs VarDeclared overlap rule.
        if (!$isFunctionBody) {
            $this->validateBlockDuplicateLexNames($body);
        }
        return new BlockStatement($location, $body);
    }

    /**
     * Per §16.1.1 / §14.2.1 Early Errors: the LexicallyDeclaredNames of a
     * StatementList cannot contain duplicates, and must not overlap with
     * its VarDeclaredNames. Applies to block bodies and switch case
     * consequents.
     *
     * @param Node[] $body
     */
    private function validateBlockDuplicateLexNames(array $body): void
    {
        // Per Annex B §B.3.2, two non-generator non-async FunctionDeclarations
        // with the same name inside a non-strict Block are permitted. Track
        // plain-function names separately so only dup-function-with-function
        // is relaxed; other overlaps still error.
        $lexNames = [];
        $plainFuncNames = [];
        foreach ($body as $stmt) {
            if (
                $stmt instanceof VariableDeclaration
                && ($stmt->kind === 'let' || $stmt->kind === 'const'
                    || $stmt->kind === 'using' || $stmt->kind === 'await using')
            ) {
                foreach ($stmt->declarations as $d) {
                    foreach (self::collectPatternNames($d->id) as $n) {
                        if (isset($lexNames[$n]) || isset($plainFuncNames[$n])) {
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Identifier '{$n}' has already been declared",
                            );
                        }
                        $lexNames[$n] = true;
                    }
                }
            } elseif ($stmt instanceof ClassDeclaration && $stmt->id !== null) {
                $n = $stmt->id->name;
                if (isset($lexNames[$n]) || isset($plainFuncNames[$n])) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Identifier '{$n}' has already been declared",
                    );
                }
                $lexNames[$n] = true;
            } elseif ($stmt instanceof FunctionDeclaration && $stmt->id !== null) {
                $n = $stmt->id->name;
                $isPlain = !$stmt->generator && !$stmt->async;
                if ($isPlain && !$this->strictMode) {
                    // Plain FunctionDeclaration in non-strict block: allow
                    // duplicates with other plain function declarations, but
                    // still conflict with let/const/class and with var.
                    if (isset($lexNames[$n])) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Identifier '{$n}' has already been declared",
                        );
                    }
                    $plainFuncNames[$n] = true;
                } else {
                    if (isset($lexNames[$n]) || isset($plainFuncNames[$n])) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Identifier '{$n}' has already been declared",
                        );
                    }
                    $lexNames[$n] = true;
                }
            }
        }
        if (empty($lexNames) && empty($plainFuncNames)) {
            return;
        }
        $varNames = [];
        foreach ($body as $stmt) {
            self::collectVarDeclaredNames($stmt, $varNames);
        }
        foreach (array_keys($lexNames) as $n) {
            if (isset($varNames[$n])) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Identifier '{$n}' has already been declared",
                );
            }
        }
        // Plain (Annex B) function names in a block still conflict with var
        // declarations in the same block — only dup-function is relaxed.
        foreach (array_keys($plainFuncNames) as $n) {
            if (isset($varNames[$n])) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Identifier '{$n}' has already been declared",
                );
            }
        }
    }

    /**
     * Collect VarDeclaredNames recursively per §8.2.7. Walks Statements
     * (including nested BlockStatements) but does not descend into nested
     * function bodies, class bodies, or top-level Declarations.
     *
     * @param array<string,bool> $out
     */
    private static function collectVarDeclaredNames(Node $node, array &$out): void
    {
        if ($node instanceof VariableDeclaration && $node->kind === 'var') {
            foreach ($node->declarations as $d) {
                foreach (self::collectPatternNames($d->id) as $n) {
                    $out[$n] = true;
                }
            }
            return;
        }
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $s) {
                self::collectVarDeclaredNames($s, $out);
            }
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\IfStatement) {
            self::collectVarDeclaredNames($node->consequent, $out);
            if ($node->alternate !== null) {
                self::collectVarDeclaredNames($node->alternate, $out);
            }
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\ForStatement) {
            if ($node->init instanceof Node) {
                self::collectVarDeclaredNames($node->init, $out);
            }
            self::collectVarDeclaredNames($node->body, $out);
            return;
        }
        if (
            $node instanceof \Phasis\Ast\Statement\ForInStatement
            || $node instanceof \Phasis\Ast\Statement\ForOfStatement
        ) {
            if ($node->left instanceof VariableDeclaration) {
                self::collectVarDeclaredNames($node->left, $out);
            }
            self::collectVarDeclaredNames($node->body, $out);
            return;
        }
        if (
            $node instanceof \Phasis\Ast\Statement\WhileStatement
            || $node instanceof \Phasis\Ast\Statement\DoWhileStatement
        ) {
            self::collectVarDeclaredNames($node->body, $out);
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\WithStatement) {
            self::collectVarDeclaredNames($node->body, $out);
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\SwitchStatement) {
            foreach ($node->cases as $case) {
                foreach ($case->consequent as $s) {
                    self::collectVarDeclaredNames($s, $out);
                }
            }
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\TryStatement) {
            self::collectVarDeclaredNames($node->block, $out);
            if ($node->handler !== null) {
                self::collectVarDeclaredNames($node->handler->body, $out);
            }
            if ($node->finalizer !== null) {
                self::collectVarDeclaredNames($node->finalizer, $out);
            }
            return;
        }
        if ($node instanceof \Phasis\Ast\Statement\LabeledStatement) {
            self::collectVarDeclaredNames($node->body, $out);
            return;
        }
    }

    /** @return list<string> */
    private static function collectPatternNames(Node $pattern): array
    {
        if ($pattern instanceof Identifier) {
            return [$pattern->name];
        }
        if ($pattern instanceof \Phasis\Ast\Pattern\ArrayPattern) {
            $out = [];
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $out = array_merge($out, self::collectPatternNames($elem));
                }
            }
            return $out;
        }
        if ($pattern instanceof \Phasis\Ast\Pattern\ObjectPattern) {
            $out = [];
            foreach ($pattern->properties as $p) {
                if ($p instanceof \Phasis\Ast\Pattern\AssignmentProperty) {
                    $out = array_merge($out, self::collectPatternNames($p->value));
                } elseif ($p instanceof \Phasis\Ast\Pattern\RestElement) {
                    $out = array_merge($out, self::collectPatternNames($p->argument));
                }
            }
            return $out;
        }
        if ($pattern instanceof \Phasis\Ast\Pattern\AssignmentPattern) {
            return self::collectPatternNames($pattern->left);
        }
        if ($pattern instanceof \Phasis\Ast\Pattern\RestElement) {
            return self::collectPatternNames($pattern->argument);
        }
        return [];
    }

    private function parseVariableDeclaration(): VariableDeclaration
    {
        $token = $this->advance();
        $kind = $token->value;
        $location = $token->location;
        $declarations = [];

        do {
            $decl = $this->parseVariableDeclarator();
            // Per §13.3.1.1: const declarations must have initializers
            // (except inside for-of/for-in, where the initializer comes
            // from the iteration protocol — the for-header path bypasses
            // this method).
            if (($kind === 'const' || $kind === 'using') && $decl->init === null) {
                throw new ParseError(
                    "Missing initializer in {$kind} declaration",
                    $token,
                );
            }
            $declarations[] = $decl;
        } while ($this->eat(TokenType::Comma));

        // Per §13.3.1.1: LexicalDeclaration must not bind `let` as its
        // identifier name. Also cannot bind duplicate names within a
        // single LexicalBinding's pattern.
        if ($kind === 'let' || $kind === 'const' || $kind === 'using' || $kind === 'await using') {
            $seen = [];
            foreach ($declarations as $decl) {
                foreach (self::collectPatternNames($decl->id) as $name) {
                    if ($name === 'let') {
                        throw new ParseError(
                            "'let' is not a valid binding identifier in let/const declarations",
                            $token,
                        );
                    }
                    if (isset($seen[$name])) {
                        throw new ParseError(
                            "Identifier '{$name}' has already been declared",
                            $token,
                        );
                    }
                    $seen[$name] = true;
                }
            }
        }
        // Per §13.1.1: BindingIdentifier cannot be 'arguments' or 'eval'
        // in strict-mode code.
        if ($this->strictMode) {
            foreach ($declarations as $decl) {
                foreach (self::collectPatternNames($decl->id) as $name) {
                    if ($name === 'arguments' || $name === 'eval') {
                        throw new ParseError(
                            "Binding identifier '{$name}' may not be used in strict mode",
                            $token,
                        );
                    }
                }
            }
        }
        // `await` is not a valid binding identifier inside a class static
        // initialization block (per §15.7.1).
        if ($this->inStaticBlock) {
            foreach ($declarations as $decl) {
                foreach (self::collectPatternNames($decl->id) as $name) {
                    if ($name === 'await') {
                        throw new ParseError(
                            "'await' cannot be a binding identifier inside a class static block",
                            $token,
                        );
                    }
                }
            }
        }

        $this->consumeSemicolon();
        return new VariableDeclaration($location, $kind, $declarations);
    }

    /**
     * Parse `using x = expr` or `await using x = expr`.
     * Reuses VariableDeclaration with kind 'using' or 'await using'.
     */
    private function parseUsingDeclaration(bool $isAwait): VariableDeclaration
    {
        $token = $this->advance(); // consume 'using'
        $location = $isAwait
            ? new SourceLocation(
                $this->tokens[$this->pos - 2]->location->line,
                $this->tokens[$this->pos - 2]->location->column,
                $this->tokens[$this->pos - 2]->location->offset,
            )
            : $token->location;
        $kind = $isAwait ? 'await using' : 'using';
        $declarations = [];

        do {
            $declarations[] = $this->parseVariableDeclarator();
        } while ($this->eat(TokenType::Comma));

        $this->consumeSemicolon();
        return new VariableDeclaration($location, $kind, $declarations);
    }

    /**
     * Disambiguate: `import` at statement level can be an import declaration
     * (import x from 'y') or an expression statement (import('./x.js')).
     *
     * If followed by '(' or '.', treat as expression statement. Otherwise
     * parse as import declaration.
     */
    private function parseImportDeclarationOrExpression(): Node
    {
        $next = $this->peek();
        // import( ... ) or import.meta: treat as expression statement.
        if ($next->type === TokenType::LeftParen || $next->type === TokenType::Dot) {
            return $this->parseStatement();
        }
        return $this->parseImportDeclaration();
    }

    /**
     * Parse an import declaration.
     *
     * import defaultExport from 'source';
     * import { a, b as c } from 'source';
     * import * as ns from 'source';
     * import 'source';
     * import defaultExport, { a } from 'source';
     * import defaultExport, * as ns from 'source';
     */
    private function parseImportDeclaration(): ImportDeclaration
    {
        // Static import declarations only appear at the top level of a Module.
        if (!$this->moduleMode) {
            throw new ParseError(
                "'import' is only valid in module code",
                $this->current(),
            );
        }
        if (!$this->moduleTopLevel) {
            throw new ParseError(
                "'import' declarations may only appear at top level of a module",
                $this->current(),
            );
        }
        $location = $this->expect(TokenType::Import)->location;
        $specifiers = [];

        // Stage-3 proposals:
        //   `import defer * as ns from ...`   (defer + namespace)
        //   `import source * as ns from ...`  (source + namespace)
        //   `import source ImportedBinding from ...` (source + default)
        // Track the phase on the ImportDeclaration so the runtime can
        // pick the deferred namespace / source representation. We must
        // not consume `source` / `defer` when it is itself the default
        // binding name (`import source from "x"`,
        // `import defer from "x"`).
        $phase = 'evaluation';
        if (
            $this->check(TokenType::Identifier)
            && ($this->current()->value === 'defer' || $this->current()->value === 'source')
        ) {
            $next = $this->peek();
            $consumeModifier = false;
            if ($next->type === TokenType::Star) {
                $consumeModifier = true;
            } elseif (
                $next->type === TokenType::Identifier
                && !($next->value === 'from' && $this->peekAt(2)->type === TokenType::String)
            ) {
                // `import source ImportedBinding from ...` — second token is
                // an identifier that is NOT followed by a string literal
                // (which would make the whole thing `import source from "x";`,
                // i.e. `source` as the binding name).
                $consumeModifier = true;
            }
            if ($consumeModifier) {
                $phase = $this->current()->value;
                $this->advance();
            }
        }

        // import 'source' (bare import, no specifiers).
        if ($this->check(TokenType::String)) {
            $source = $this->advance()->value;
            // Optional WithClause.
            $withIsKeyword = $this->check(TokenType::With);
            $withIsAssert = $this->check(TokenType::Identifier)
                && $this->current()->value === 'assert';
            if (($withIsKeyword || $withIsAssert) && $this->peek()->type === TokenType::LeftBrace) {
                $this->advance();
                $this->skipAttributeClause();
            }
            $this->consumeSemicolon();
            return new ImportDeclaration($location, $specifiers, $source, null, $phase);
        }

        // import * as ns from 'source'
        if ($this->check(TokenType::Star)) {
            $this->advance();
            if ($this->current()->rawValue === 'escaped') {
                throw new ParseError(
                    "Keyword 'as' must not contain escaped characters",
                    $this->current(),
                );
            }
            $this->expectContextual('as');
            $local = $this->parseIdentifier();
            $specifiers[] = new ImportSpecifier($local->location, 'namespace', $local->name);
        } elseif ($this->check(TokenType::LeftBrace)) {
            // import { a, b as c } from 'source'
            $specifiers = $this->parseNamedImports();
        } else {
            // Default import: import foo from 'source'
            $local = $this->parseIdentifier();
            $specifiers[] = new ImportSpecifier($local->location, 'default', $local->name);

            // import foo, { a } from 'source' or import foo, * as ns from 'source'
            if ($this->eat(TokenType::Comma)) {
                if ($this->check(TokenType::Star)) {
                    $this->advance();
                    if ($this->current()->rawValue === 'escaped') {
                        throw new ParseError(
                            "Keyword 'as' must not contain escaped characters",
                            $this->current(),
                        );
                    }
                    $this->expectContextual('as');
                    $nsLocal = $this->parseIdentifier();
                    $specifiers[] = new ImportSpecifier(
                        $nsLocal->location,
                        'namespace',
                        $nsLocal->name,
                    );
                } elseif ($this->check(TokenType::LeftBrace)) {
                    $specifiers = array_merge($specifiers, $this->parseNamedImports());
                }
            }
        }

        if ($this->current()->rawValue === 'escaped') {
            throw new ParseError(
                "Keyword 'from' must not contain escaped characters",
                $this->current(),
            );
        }
        $this->expectContextual('from');

        if (!$this->check(TokenType::String)) {
            throw new ParseError('Expected module specifier string', $this->current());
        }
        $source = $this->advance()->value;

        // Optional WithClause: `with { key: value, ... }` — we accept and
        // ignore (no attribute-based module resolution).
        $withIsKeyword = $this->check(TokenType::With);
        $withIsAssert = $this->check(TokenType::Identifier) && $this->current()->value === 'assert';
        if (($withIsKeyword || $withIsAssert) && $this->peek()->type === TokenType::LeftBrace) {
            $this->advance();
            $this->skipAttributeClause();
        }

        $this->consumeSemicolon();
        return new ImportDeclaration($location, $specifiers, $source, null, $phase);
    }

    /** Consume and discard `{ key: "val", ... }` from a WithClause. */
    private function skipAttributeClause(): void
    {
        $this->expect(TokenType::LeftBrace);
        $seenKeys = [];
        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            // Key: IdentifierName or StringLiteral.
            $keyToken = $this->current();
            $keyName = $keyToken->value;
            $this->advance();
            // Per §16.2.1.1 early error: WithClause keys must be unique.
            if (isset($seenKeys[$keyName])) {
                throw new ParseError(
                    "Duplicate attribute key '{$keyName}' in WithClause",
                    $keyToken,
                );
            }
            $seenKeys[$keyName] = true;
            $this->expect(TokenType::Colon);
            if (!$this->check(TokenType::String)) {
                throw new ParseError(
                    'Expected string literal in import attribute value',
                    $this->current(),
                );
            }
            $this->advance();
            if (!$this->eat(TokenType::Comma)) {
                break;
            }
        }
        $this->expect(TokenType::RightBrace);
    }

    /** @return ImportSpecifier[] */
    private function parseNamedImports(): array
    {
        $this->expect(TokenType::LeftBrace);
        $specifiers = [];
        $localNames = [];

        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            $importedToken = $this->current();
            $imported = $importedToken->value;
            $this->advance();

            $local = $imported;
            $localToken = $importedToken;
            $hasAs = false;
            if ($this->checkContextual('as')) {
                if ($this->current()->rawValue === 'escaped') {
                    throw new ParseError(
                        "Keyword 'as' must not contain escaped characters",
                        $this->current(),
                    );
                }
                $this->advance();
                $hasAs = true;
                $localToken = $this->current();
                $localNode = $this->parseIdentifier();
                $local = $localNode->name;
            }
            // ImportedBinding is a BindingIdentifier and is parsed under
            // strict-mode rules (modules are always strict). 'arguments'
            // and 'eval' are forbidden binding names per §13.1.1.
            if ($local === 'arguments' || $local === 'eval') {
                throw new ParseError(
                    "Binding identifier '{$local}' may not be used in strict mode",
                    $localToken,
                );
            }
            if (isset($localNames[$local])) {
                throw new ParseError(
                    "Duplicate import binding '{$local}'",
                    $localToken,
                );
            }
            $localNames[$local] = true;

            $specifiers[] = new ImportSpecifier(
                $importedToken->location,
                'named',
                $local,
                $imported,
            );

            if (!$this->eat(TokenType::Comma)) {
                break;
            }
        }

        $this->expect(TokenType::RightBrace);
        return $specifiers;
    }

    /**
     * Parse an export declaration.
     *
     * export default expr;
     * export { a, b as c };
     * export var x = 1;
     * export function foo() {}
     * export class Bar {}
     * export { a } from 'source';
     * export * from 'source';
     * export * as ns from 'source';
     */
    private function parseExportDeclaration(): ExportDeclaration
    {
        // Per spec, export declarations only appear at the top level of a
        // Module. Any occurrence inside a function, block, or other inner
        // statement is a parse-time SyntaxError.
        if (!$this->moduleMode) {
            throw new ParseError(
                "'export' is only valid in module code",
                $this->current(),
            );
        }
        if (!$this->moduleTopLevel) {
            throw new ParseError(
                "'export' declarations may only appear at top level of a module",
                $this->current(),
            );
        }
        $location = $this->expect(TokenType::Export)->location;

        // export default ...
        if ($this->check(TokenType::Default_)) {
            if ($this->current()->rawValue === 'escaped') {
                throw new ParseError(
                    "Keyword 'default' must not contain escaped characters",
                    $this->current(),
                );
            }
            $this->advance();

            $declaration = null;
            if ($this->check(TokenType::Function_)) {
                $declaration = $this->parseFunctionDeclaration(true);
            } elseif ($this->check(TokenType::Class_)) {
                $declaration = $this->parseClassDeclaration();
            } elseif ($this->check(TokenType::Async) && $this->peek()->type === TokenType::Function_) {
                $declaration = $this->maybeParseAsyncFunction();
            } else {
                $declaration = $this->parseAssignmentExpression();
                $this->consumeSemicolon();
            }

            return new ExportDeclaration($location, declaration: $declaration, isDefault: true);
        }

        // export * from 'source' or export * as ns from 'source'
        if ($this->check(TokenType::Star)) {
            $this->advance();
            $allAs = null;
            if ($this->checkContextual('as')) {
                if ($this->current()->rawValue === 'escaped') {
                    throw new ParseError(
                        "Keyword 'as' must not contain escaped characters",
                        $this->current(),
                    );
                }
                $this->advance();
                $aliasToken = $this->current();
                $allAs = $aliasToken->value;
                $this->advance();
            }
            if ($this->current()->rawValue === 'escaped') {
                throw new ParseError(
                    "Keyword 'from' must not contain escaped characters",
                    $this->current(),
                );
            }
            $this->expectContextual('from');
            if (!$this->check(TokenType::String)) {
                throw new ParseError('Expected module specifier string', $this->current());
            }
            $source = $this->advance()->value;
            // Optional WithClause; accept and discard.
            $withIsKeyword = $this->check(TokenType::With);
            $withIsAssert = $this->check(TokenType::Identifier)
                && $this->current()->value === 'assert';
            if (($withIsKeyword || $withIsAssert) && $this->peek()->type === TokenType::LeftBrace) {
                $this->advance();
                $this->skipAttributeClause();
            }
            $this->consumeSemicolon();
            return new ExportDeclaration(
                $location,
                source: $source,
                isAll: true,
                allAs: $allAs,
            );
        }

        // export { a, b as c } or export { a } from 'source'
        if ($this->check(TokenType::LeftBrace)) {
            $specifiers = $this->parseExportSpecifiers();
            $source = null;
            if ($this->checkContextual('from')) {
                if ($this->current()->rawValue === 'escaped') {
                    throw new ParseError(
                        "Keyword 'from' must not contain escaped characters",
                        $this->current(),
                    );
                }
                $this->advance();
                if (!$this->check(TokenType::String)) {
                    throw new ParseError('Expected module specifier string', $this->current());
                }
                $source = $this->advance()->value;
                // Optional WithClause; accept and discard.
                $withIsKeyword = $this->check(TokenType::With);
                $withIsAssert = $this->check(TokenType::Identifier)
                    && $this->current()->value === 'assert';
                if (($withIsKeyword || $withIsAssert) && $this->peek()->type === TokenType::LeftBrace) {
                    $this->advance();
                    $this->skipAttributeClause();
                }
            }
            // Per §16.2.3.1: without a FromClause, ReferencedBindings of
            // NamedExports (the `local` side) cannot be string literals.
            if ($source === null) {
                foreach ($specifiers as $spec) {
                    if ($spec->localIsString) {
                        throw new ParseError(
                            'String literal cannot be used as export binding without a FromClause',
                            $this->current(),
                        );
                    }
                }
            }
            // Exported name (as target) when a string must be well-formed
            // Unicode: no lone UTF-16 surrogates.
            foreach ($specifiers as $spec) {
                if ($spec->exportedIsString && $this->containsLoneSurrogate($spec->exported)) {
                    throw new ParseError(
                        'Module export name contains lone surrogate',
                        $this->current(),
                    );
                }
            }
            $this->consumeSemicolon();
            return new ExportDeclaration($location, specifiers: $specifiers, source: $source);
        }

        // export var/let/const, export function, export class, export async function
        $declaration = match ($this->current()->type) {
            TokenType::Var, TokenType::Let, TokenType::Const_ => $this->parseVariableDeclaration(),
            TokenType::Function_ => $this->parseFunctionDeclaration(),
            TokenType::Class_ => $this->parseClassDeclaration(),
            TokenType::Async => $this->maybeParseAsyncFunction(),
            default => throw new ParseError('Unexpected token in export', $this->current()),
        };

        return new ExportDeclaration($location, declaration: $declaration);
    }

    /** @return ExportSpecifier[] */
    private function parseExportSpecifiers(): array
    {
        $this->expect(TokenType::LeftBrace);
        $specifiers = [];

        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            $localToken = $this->current();
            $localIsString = $localToken->type === TokenType::String;
            $local = $localToken->value;
            $this->advance();

            $exported = $local;
            $exportedIsString = $localIsString;
            if ($this->checkContextual('as')) {
                if ($this->current()->rawValue === 'escaped') {
                    throw new ParseError(
                        "Keyword 'as' must not contain escaped characters",
                        $this->current(),
                    );
                }
                $this->advance();
                $exportedToken = $this->current();
                $exportedIsString = $exportedToken->type === TokenType::String;
                $exported = $exportedToken->value;
                $this->advance();
            }

            $specifiers[] = new ExportSpecifier(
                $localToken->location,
                $local,
                $exported,
                $localIsString,
                $exportedIsString,
            );

            if (!$this->eat(TokenType::Comma)) {
                break;
            }
        }

        $this->expect(TokenType::RightBrace);
        return $specifiers;
    }

    /**
     * Check if a JS string value contains unpaired UTF-16 surrogates.
     * Token strings are stored as UTF-8 in PHP; walk the codepoints.
     */
    private function containsLoneSurrogate(string $s): bool
    {
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $b = ord($s[$i]);
            if (($b & 0x80) === 0) {
                $i++;
                continue;
            }
            if (($b & 0xE0) === 0xC0) {
                $i += 2;
                continue;
            }
            if (($b & 0xF0) === 0xE0 && $i + 2 < $len) {
                $b1 = ord($s[$i + 1]);
                $b2 = ord($s[$i + 2]);
                $cp = (($b & 0x0F) << 12) | (($b1 & 0x3F) << 6) | ($b2 & 0x3F);
                if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                    return true;
                }
                $i += 3;
                continue;
            }
            $i++;
        }
        return false;
    }

    private function parseVariableDeclarator(): VariableDeclarator
    {
        $id = $this->parseBindingPattern();
        $init = null;

        if ($this->eat(TokenType::Equal)) {
            $init = $this->parseAssignmentExpression();
        }

        return new VariableDeclarator($id->location, $id, $init);
    }

    private function parseBindingPattern(): Node
    {
        $token = $this->current();

        return match ($token->type) {
            TokenType::LeftBracket => $this->parseArrayPattern(),
            TokenType::LeftBrace => $this->parseObjectPattern(),
            default => $this->parseIdentifier(),
        };
    }

    private function parseArrayPattern(): ArrayPattern
    {
        $location = $this->expect(TokenType::LeftBracket)->location;
        $elements = [];

        while (!$this->check(TokenType::RightBracket) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Comma)) {
                $elements[] = null; // hole
                $this->advance();
                continue;
            }

            if ($this->check(TokenType::Ellipsis)) {
                $elements[] = $this->parseRestElement();
                break;
            }

            $element = $this->parseBindingPattern();
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $element = new AssignmentPattern($element->location, $element, $default);
            }
            $elements[] = $element;

            if (!$this->check(TokenType::RightBracket)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightBracket);
        return new ArrayPattern($location, $elements);
    }

    private function parseObjectPattern(): ObjectPattern
    {
        $location = $this->expect(TokenType::LeftBrace)->location;
        $properties = [];

        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Ellipsis)) {
                $properties[] = $this->parseRestElement();
                break;
            }

            $properties[] = $this->parseAssignmentProperty();

            if (!$this->check(TokenType::RightBrace)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightBrace);
        return new ObjectPattern($location, $properties);
    }

    private function parseAssignmentProperty(): AssignmentProperty
    {
        $computed = false;
        $shorthand = false;
        $location = $this->current()->location;

        if ($this->check(TokenType::LeftBracket)) {
            $computed = true;
            $this->advance();
            // Per spec: ComputedPropertyName uses AssignmentExpression[+In].
            $savedNoIn = $this->noIn;
            $this->noIn = false;
            $key = $this->parseAssignmentExpression();
            $this->noIn = $savedNoIn;
            $this->expect(TokenType::RightBracket);
            $this->expect(TokenType::Colon);
            $value = $this->parseBindingPattern();
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $value = new AssignmentPattern($value->location, $value, $default);
            }
            return new AssignmentProperty($location, $key, $value, $computed, $shorthand);
        }

        $keyToken = $this->current();
        $key = $this->parsePropertyName();

        if ($this->eat(TokenType::Colon)) {
            $value = $this->parseBindingPattern();
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $value = new AssignmentPattern($value->location, $value, $default);
            }
        } else {
            // Shorthand: { x } or { x = default }. The key doubles as the
            // binding identifier, so it must already BE an
            // identifier — string literals / numeric literals as the
            // sole property are SyntaxErrors per spec (no
            // BindingProperty[Yield, Await] : SingleNameBinding rule
            // matches a non-identifier PropertyName).
            if (!$key instanceof Identifier) {
                throw new ParseError(
                    'Object pattern shorthand requires an identifier',
                    $keyToken,
                );
            }
            // Reserved words (e.g. `class`, `enum`, `return`) must raise
            // SyntaxError — BindingIdentifier cannot be a reserved word
            // even in sloppy mode. Check by token type (Enum, Class_,
            // etc.) and also by decoded value so Unicode escape forms
            // like `enum` are still rejected.
            if (self::isReservedWordKeyword($keyToken->type)) {
                throw new ParseError(
                    "Unexpected reserved word '{$keyToken->value}' in shorthand destructuring",
                    $keyToken,
                );
            }
            if (self::isReservedWordIdentifierName($keyToken->value)) {
                throw new ParseError(
                    "Unexpected reserved word '{$keyToken->value}' in shorthand destructuring",
                    $keyToken,
                );
            }
            $shorthand = true;
            $value = $key;
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $value = new AssignmentPattern($value->location, $value, $default);
            }
        }

        return new AssignmentProperty($location, $key, $value, $computed, $shorthand);
    }

    /**
     * Check an ObjectExpression used as a destructuring assignment target for
     * invalid shorthand reserved-word identifiers, e.g. `({ class } = x)`
     * must throw SyntaxError because `class` is not a valid BindingIdentifier.
     */
    private function validateAssignmentTargetObjectShorthand(ObjectExpression $obj): void
    {
        foreach ($obj->properties as $prop) {
            if (
                $prop instanceof Property
                && $prop->shorthand
                && $prop->key instanceof Identifier
                && self::isReservedWordIdentifierName($prop->key->name)
            ) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Unexpected reserved word '{$prop->key->name}' as shorthand property",
                    $prop->key->location,
                );
            }
            // In strict mode, `eval` and `arguments` are not valid
            // BindingIdentifiers (per §13.1.1 / §13.15.1). When the
            // surrounding object literal is refined into an
            // AssignmentPattern, the shorthand property's value-side becomes
            // a BindingIdentifier and the strict-mode rejection applies.
            if (
                $this->strictMode
                && $prop instanceof Property
                && $prop->shorthand
                && $prop->key instanceof Identifier
                && ($prop->key->name === 'eval' || $prop->key->name === 'arguments')
            ) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Unexpected '{$prop->key->name}' as binding identifier in strict mode",
                    $prop->key->location,
                );
            }
        }
    }

    /**
     * Per §13.5.1 early errors: `delete expr.#priv` is a parse error.
     * Recurse into parenthesized/comma expressions to catch covered forms
     * (e.g. `delete (x.#y)`). Stops at non-transparent nodes.
     */
    private static function validateDeleteArgument(Node $argument, \Phasis\Lexer\Token $token): void
    {
        $node = $argument;
        // Unwrap conditional chain / sequence without evaluation.
        while (true) {
            if (
                $node instanceof \Phasis\Ast\Expression\MemberExpression
                && $node->property instanceof \Phasis\Ast\Expression\PrivateIdentifier
            ) {
                throw new ParseError(
                    "Cannot delete a private member",
                    $token,
                );
            }
            if ($node instanceof \Phasis\Ast\Expression\SequenceExpression) {
                $exprs = $node->expressions;
                $last = $exprs === [] ? null : $exprs[array_key_last($exprs)];
                if ($last instanceof Node) {
                    $node = $last;
                    continue;
                }
            }
            break;
        }
    }

    /**
     * Return the PropName of a class element key, or null if the name is
     * computed / not statically determinable. Handles Identifier,
     * PrivateIdentifier, and Literal (string/number) keys.
     */
    /**
     * Whether $type is a reserved keyword that cannot be spelled with a
     * Unicode escape sequence per ES spec 12.6. Contextual keywords
     * (let/static/async/yield/await/of) are excluded — those are validated
     * at the specific positions where the parser distinguishes them.
     */
    private static function isStrictlyReservedKeyword(TokenType $type): bool
    {
        return match ($type) {
            TokenType::Break,
            TokenType::Case,
            TokenType::Catch,
            TokenType::Class_,
            TokenType::Const_,
            TokenType::Continue,
            TokenType::Debugger,
            TokenType::Default_,
            TokenType::Delete,
            TokenType::Do,
            TokenType::Else,
            TokenType::Export,
            TokenType::Extends,
            TokenType::Finally,
            TokenType::For,
            TokenType::Function_,
            TokenType::If,
            TokenType::Import,
            TokenType::In,
            TokenType::Instanceof,
            TokenType::New,
            TokenType::Null,
            TokenType::Return,
            TokenType::Super,
            TokenType::Switch,
            TokenType::This,
            TokenType::Throw,
            TokenType::True,
            TokenType::False,
            TokenType::Try,
            TokenType::Typeof,
            TokenType::Var,
            TokenType::Void,
            TokenType::While,
            TokenType::With => true,
            default => false,
        };
    }

    private static function staticPropName(Node $key): ?string
    {
        if ($key instanceof Identifier) {
            return $key->name;
        }
        if ($key instanceof Literal && is_string($key->value)) {
            return $key->value;
        }
        if ($key instanceof Literal && is_int($key->value)) {
            return (string) $key->value;
        }
        if ($key instanceof Literal && is_float($key->value)) {
            // Numeric literal PropName is ToString(value).
            return (new \Phasis\Value\JsNumber($key->value))->toJsString();
        }
        return null;
    }

    /**
     * Class field initializers cannot contain SuperCall or references to
     * `arguments` per spec §15.7.1 early errors. Recurse into the AST but
     * stop at function/class boundaries (those introduce their own scope).
     */
    private static function validateClassFieldInitializer(?Node $node): void
    {
        if ($node === null) {
            return;
        }
        self::walkClassFieldInitializer($node);
    }

    private static function walkClassFieldInitializer(?Node $node): void
    {
        if ($node === null) {
            return;
        }
        // Stop at nested function/class boundaries — they introduce their
        // own `arguments` binding and their own super scope. For classes,
        // still descend into computed property names (which are evaluated
        // in the surrounding context).
        if (
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Declaration\FunctionDeclaration
        ) {
            return;
        }
        if (
            $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
        ) {
            // Heritage expression and computed class element keys are
            // evaluated in the enclosing context; descend into them but
            // not into method/field/static-block bodies.
            if ($node->superClass !== null) {
                self::walkClassFieldInitializer($node->superClass);
            }
            foreach ($node->body as $element) {
                if (
                    ($element instanceof ClassMethod || $element instanceof ClassProperty)
                    && $element->computed
                ) {
                    self::walkClassFieldInitializer($element->key);
                }
            }
            return;
        }
        // Arrow functions inherit `arguments` and `super` from the outer
        // scope — still forbidden.
        if ($node instanceof \Phasis\Ast\Expression\Identifier && $node->name === 'arguments') {
            throw new \Phasis\Exceptions\SyntaxError(
                "'arguments' is not allowed in class field initializers",
            );
        }
        if (
            $node instanceof \Phasis\Ast\Expression\CallExpression
            && $node->callee instanceof \Phasis\Ast\Expression\Identifier
            && $node->callee->name === 'super'
        ) {
            throw new \Phasis\Exceptions\SyntaxError(
                "'super' call is not allowed in class field initializers",
            );
        }
        // Walk all public Node children.
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                self::walkClassFieldInitializer($value);
            } elseif (is_array($value)) {
                foreach ($value as $v) {
                    if ($v instanceof Node) {
                        self::walkClassFieldInitializer($v);
                    }
                }
            }
        }
    }

    /**
     * True if `$node` is a valid AssignmentTarget for `=`. For compound
     * assignments (`+=`, `*=`, etc.), only a simple target (Identifier or
     * MemberExpression) is allowed — destructuring is forbidden.
     */
    private static function isValidAssignmentTarget(Node $node, bool $allowPattern): bool
    {
        if (self::isSimpleAssignmentTarget($node)) {
            return true;
        }
        if ($allowPattern) {
            // `=` accepts ObjectLiteral/ArrayLiteral as destructuring targets.
            if (
                $node instanceof \Phasis\Ast\Expression\ObjectExpression
                || $node instanceof \Phasis\Ast\Expression\ArrayExpression
            ) {
                return true;
            }
            // Patterns after reinterpretation.
            if (
                $node instanceof \Phasis\Ast\Pattern\ObjectPattern
                || $node instanceof \Phasis\Ast\Pattern\ArrayPattern
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * True if the node is a "simple" AssignmentTargetType per spec —
     * i.e. a valid target for `x++`, `++x`, `x = v` without destructuring.
     * Covers Identifier and MemberExpression (including computed member,
     * optional chain non-terminal, and super references).
     */
    private static function isSimpleAssignmentTarget(Node $node): bool
    {
        if ($node instanceof Identifier) {
            // `eval` and `arguments` would be restricted in strict mode but
            // that check happens elsewhere; any identifier is structurally
            // a simple target.
            return true;
        }
        if ($node instanceof MemberExpression) {
            // OptionalExpression cannot be an AssignmentTarget per
            // §13.5.1.1 / §13.15.1. Reject if any part of the chain is
            // optional.
            return !self::memberExpressionIsOptional($node);
        }
        // ParenthesizedExpression is transparent — look inside.
        return false;
    }

    private static function memberExpressionIsOptional(Node $node): bool
    {
        if ($node instanceof MemberExpression) {
            if ($node->optional) {
                return true;
            }
            return self::memberExpressionIsOptional($node->object);
        }
        if ($node instanceof CallExpression) {
            if ($node->optional) {
                return true;
            }
            return self::memberExpressionIsOptional($node->callee);
        }
        return false;
    }

    /**
     * True if the identifier name matches a reserved word that cannot be a
     * BindingIdentifier (e.g. class, const, enum, export, import, return).
     * Excludes contextual keywords (let, static, yield, await, async, of).
     */
    private static function isReservedWordIdentifierName(string $name): bool
    {
        return match ($name) {
            'break', 'case', 'catch', 'class', 'const', 'continue',
            'debugger', 'default', 'delete', 'do', 'else', 'enum',
            'export', 'extends', 'false', 'finally', 'for', 'function',
            'if', 'import', 'in', 'instanceof', 'new', 'null',
            'return', 'super', 'switch', 'this', 'throw', 'true',
            'try', 'typeof', 'var', 'void', 'while', 'with' => true,
            default => false,
        };
    }

    /**
     * True if the identifier name is a FutureReservedWord that cannot be
     * used as IdentifierReference in strict-mode code (per §13.1.1).
     * Includes the regular ReservedWords plus the strict-mode list.
     */
    private static function isStrictReservedWordIdentifier(string $name): bool
    {
        return match ($name) {
            'implements', 'interface', 'let', 'package', 'private',
            'protected', 'public', 'static', 'yield' => true,
            default => self::isReservedWordIdentifierName($name),
        };
    }

    /**
     * True if the token is a reserved word that cannot appear as a
     * BindingIdentifier (e.g. class, const, enum, export, import, return).
     * Excludes contextual keywords (let, static, yield, await, async, of)
     * which may appear as identifiers outside strict/module/async contexts.
     */
    private static function isReservedWordKeyword(TokenType $type): bool
    {
        return match ($type) {
            TokenType::Break, TokenType::Case, TokenType::Catch,
            TokenType::Class_, TokenType::Const_, TokenType::Continue,
            TokenType::Debugger, TokenType::Default_, TokenType::Delete,
            TokenType::Do, TokenType::Else,
            TokenType::Export, TokenType::Extends, TokenType::False,
            TokenType::Finally, TokenType::For, TokenType::Function_,
            TokenType::If, TokenType::Import, TokenType::In,
            TokenType::Instanceof, TokenType::New, TokenType::Null,
            TokenType::Return, TokenType::Super, TokenType::Switch,
            TokenType::This, TokenType::Throw, TokenType::True,
            TokenType::Try, TokenType::Typeof, TokenType::Var,
            TokenType::Void, TokenType::While, TokenType::With => true,
            default => false,
        };
    }

    private function parseRestElement(): RestElement
    {
        $location = $this->expect(TokenType::Ellipsis)->location;
        $argument = $this->parseBindingPattern();
        return new RestElement($location, $argument);
    }

    private function parseFunctionDeclaration(bool $optionalName = false): FunctionDeclaration
    {
        $location = $this->expect(TokenType::Function_)->location;
        $startOffset = $location->offset;
        $generator = $this->eat(TokenType::Star);
        $id = null;
        if (
            !$optionalName
            || $this->check(TokenType::Identifier)
            || $this->check(TokenType::Yield)
            || $this->check(TokenType::Await)
            || $this->check(TokenType::Let)
            || $this->check(TokenType::Static_)
            || $this->check(TokenType::Of)
            || $this->check(TokenType::Async)
        ) {
            $id = $this->parseIdentifier();
        }
        // Set inGenerator/inAsync BEFORE parsing parameters so that default
        // parameter expressions use the function's own context.
        $prevGenerator = $this->inGenerator;
        $prevAsync = $this->inAsync;
        $prevTopLevel = $this->topLevel;
        $prevStaticBlock = $this->inStaticBlock;
        $this->inGenerator = $generator;
        $this->inAsync = false;
        $this->topLevel = false;
        $this->inStaticBlock = false;
        $params = $this->parseFormalParameters();
        $body = $this->parseBlockStatement(true);
        $this->inGenerator = $prevGenerator;
        $this->inAsync = $prevAsync;
        $this->topLevel = $prevTopLevel;
        $this->inStaticBlock = $prevStaticBlock;

        $this->validateStrictDirectiveWithNonSimpleParams($params, $body, $location);
        $this->validateParamLexBindingOverlap($params, $body);
        // Plain function declarations (not class methods) cannot contain
        // SuperCall, super.prop, or super[expr] — those are method-only.
        self::validateNoSuperInPlainFunctionBody($params, $body);
        // Generator function params cannot contain YieldExpression.
        if ($generator) {
            foreach ($params as $p) {
                if (self::containsYieldOrAwaitExpression($p)) {
                    throw new ParseError(
                        'YieldExpression not permitted in generator parameters',
                        new \Phasis\Lexer\Token(TokenType::Identifier, '', $location),
                    );
                }
            }
        }

        return new FunctionDeclaration(
            $location,
            $id,
            $params,
            $body,
            $generator,
            false,
            false,
            $this->extractSource($startOffset),
        );
    }

    /**
     * Per §15.2.1 early errors: a plain FunctionDeclaration/FunctionExpression
     * (not a method) cannot contain `super` references in its parameter list
     * or body. Recurse into the AST but stop at nested function/class
     * boundaries (those introduce their own super scope).
     *
     * @param Node[] $params
     */
    private static function validateNoSuperInPlainFunctionBody(array $params, Node $body): void
    {
        foreach ($params as $p) {
            self::walkForSuperRef($p);
        }
        self::walkForSuperRef($body);
    }

    /**
     * Per §13.2.5.1 and §15.5.1 / §15.6.1: object method definitions and
     * getter/setter definitions cannot contain SuperCall (super()). Only
     * class constructor methods may call super(). SuperProperty remains
     * allowed — those are handled by inMethodLike.
     *
     * @param Node[] $params
     */
    private static function validateNoSuperCallInMethod(array $params, Node $body): void
    {
        foreach ($params as $p) {
            self::walkForSuperCallOnly($p);
        }
        self::walkForSuperCallOnly($body);
    }

    private static function walkForSuperRef(?Node $node): void
    {
        if ($node === null) {
            return;
        }
        if (
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Declaration\FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
        ) {
            return;
        }
        // super.prop / super[expr]
        if (
            $node instanceof \Phasis\Ast\Expression\MemberExpression
            && $node->object instanceof \Phasis\Ast\Expression\Identifier
            && $node->object->name === 'super'
        ) {
            throw new \Phasis\Exceptions\SyntaxError(
                "'super' keyword not allowed in this context",
            );
        }
        // super()
        if (
            $node instanceof \Phasis\Ast\Expression\CallExpression
            && $node->callee instanceof \Phasis\Ast\Expression\Identifier
            && $node->callee->name === 'super'
        ) {
            throw new \Phasis\Exceptions\SyntaxError(
                "'super' call not allowed in this context",
            );
        }
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                self::walkForSuperRef($value);
            } elseif (is_array($value)) {
                foreach ($value as $v) {
                    if ($v instanceof Node) {
                        self::walkForSuperRef($v);
                    }
                }
            }
        }
    }

    /**
     * Per spec §15.2.1 and §15.3.1 Early Errors, a "use strict" directive
     * at the top of a FunctionBody is a SyntaxError if the enclosing
     * parameter list is not simple (contains defaults, rest, or
     * destructuring). This prevents observing strict-only param semantics
     * in functions that rely on non-simple parameter structures.
     *
     * Additionally, in strict mode (including implicit-strict contexts like
     * class bodies and module code) it is a SyntaxError for a FormalParameter
     * list to contain duplicate BoundNames.
     *
     * @param Node[] $params
     */
    /**
     * Per §14.7.5.1: BoundNames of ForDeclaration in for-in/of cannot
     * overlap with VarDeclaredNames of the body Statement.
     */
    private static function validateForBindingNoVarOverlap(Node $idPattern, Node $body): void
    {
        $bound = self::collectPatternNames($idPattern);
        if (empty($bound)) {
            return;
        }
        $varNames = [];
        self::collectVarDeclaredNames($body, $varNames);
        foreach ($bound as $n) {
            if (isset($varNames[$n])) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Identifier '{$n}' has already been declared",
                );
            }
        }
    }

    /**
     * Per §13.15.5.1 / §14.7.5.1: validate that an expression can be
     * refined into an AssignmentPattern. Rejects things like getters/
     * setters in object literals, SequenceExpression elements, rest
     * elements that aren't last, etc.
     */
    private function validateAsAssignmentPattern(Node $node): void
    {
        // Per §13.15.5.1: a nested DestructuringAssignmentTarget that is a
        // parenthesized ObjectLiteral, ArrayLiteral, or AssignmentExpression
        // is an early SyntaxError. Object/array literals must be unwrapped
        // so the cover-grammar refinement applies; assignment expressions
        // must be the unparenthesized AssignmentElement form `a = init`.
        // (The outer `([a]) = ...` case is rejected before this is called.)
        if (
            $this->parenthesized->offsetExists($node)
            && (
                $node instanceof ObjectExpression
                || $node instanceof ArrayExpression
                || ($node instanceof AssignmentExpression && $node->operator === '=')
            )
        ) {
            throw new \Phasis\Exceptions\SyntaxError(
                'Invalid destructuring assignment target: parenthesized pattern',
            );
        }
        if ($node instanceof ObjectExpression) {
            $count = count($node->properties);
            for ($i = 0; $i < $count; $i++) {
                $prop = $node->properties[$i];
                if ($prop instanceof Property) {
                    if ($prop->kind !== 'init' || $prop->method) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            'Invalid destructuring pattern: method definition not allowed',
                        );
                    }
                    $this->validateAsAssignmentPattern($prop->value);
                } elseif ($prop instanceof SpreadElement) {
                    if ($i !== $count - 1) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            'Object rest element must be last',
                        );
                    }
                    $this->validateAsAssignmentPattern($prop->argument);
                }
            }
            return;
        }
        if ($node instanceof ArrayExpression) {
            $count = count($node->elements);
            for ($i = 0; $i < $count; $i++) {
                $el = $node->elements[$i];
                if ($el === null) {
                    continue;
                }
                if ($el instanceof SequenceExpression) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        'Invalid destructuring pattern: comma expression not allowed',
                    );
                }
                if ($el instanceof SpreadElement) {
                    if ($i !== $count - 1) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            'Array rest element must be last',
                        );
                    }
                    if ($el->argument instanceof AssignmentExpression) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            'Array rest element may not have a default initializer',
                        );
                    }
                    $this->validateAsAssignmentPattern($el->argument);
                    continue;
                }
                $this->validateAsAssignmentPattern($el);
            }
            // Reject trailing comma after a rest element in patterns.
            if ($this->arrayExpressionsWithTrailingCommaAfterRest->offsetExists($node)) {
                throw new \Phasis\Exceptions\SyntaxError(
                    'Trailing comma not allowed after rest element in pattern',
                );
            }
            return;
        }
        if ($node instanceof AssignmentExpression && $node->operator === '=') {
            // Strict-mode: 'eval'/'arguments' can't be assignment-target name.
            if (
                $this->strictMode
                && $node->left instanceof Identifier
                && ($node->left->name === 'eval' || $node->left->name === 'arguments')
            ) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Binding identifier '{$node->left->name}' may not be used in strict mode",
                );
            }
            $this->validateAsAssignmentPattern($node->left);
            return;
        }
        if (
            $this->strictMode
            && $node instanceof Identifier
            && (
                $node->name === 'eval'
                || $node->name === 'arguments'
                || $node->name === 'let'
                || $node->name === 'static'
                || $node->name === 'yield'
                || self::isStrictModeFutureReserved($node->name)
            )
        ) {
            throw new \Phasis\Exceptions\SyntaxError(
                "Binding identifier '{$node->name}' may not be used in strict mode",
            );
        }
        // Per §13.10.1: MetaProperty (new.target, import.meta) is never a
        // valid AssignmentTarget, including inside a destructuring pattern.
        if ($node instanceof \Phasis\Ast\Expression\MetaProperty) {
            throw new \Phasis\Exceptions\SyntaxError(
                'Invalid destructuring assignment target: MetaProperty',
            );
        }
        if (
            $node instanceof Identifier
            && ($node->name === '[[NewTarget]]' || $node->name === '[[ImportMeta]]')
        ) {
            throw new \Phasis\Exceptions\SyntaxError(
                'Invalid destructuring assignment target: MetaProperty',
            );
        }
        // Other nodes like Identifier, MemberExpression, etc. are valid
        // assignment targets at the leaf level. Anything else (literals,
        // logical/binary/unary expressions, calls, conditional, sequence,
        // template literals, etc.) is a SyntaxError per
        // §13.15.1 / §13.15.5 — even if it would constant-fold to a valid
        // reference at runtime, the early error fires before evaluation.
        if (
            !($node instanceof Identifier)
            && !($node instanceof MemberExpression)
            && !($node instanceof \Phasis\Ast\Pattern\ArrayPattern)
            && !($node instanceof \Phasis\Ast\Pattern\ObjectPattern)
            && !($node instanceof \Phasis\Ast\Pattern\AssignmentPattern)
            && !($node instanceof \Phasis\Ast\Pattern\RestElement)
        ) {
            throw new \Phasis\Exceptions\SyntaxError(
                'Invalid destructuring assignment target',
            );
        }
    }

    private static function containsYieldOrAwaitExpression(?Node $node): bool
    {
        if ($node === null) {
            return false;
        }
        if (
            $node instanceof \Phasis\Ast\Expression\YieldExpression
            || $node instanceof \Phasis\Ast\Expression\AwaitExpression
        ) {
            return true;
        }
        // Don't descend into nested function/class bodies — those define
        // their own [Yield]/[Await] context.
        if (
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Declaration\FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
        ) {
            return false;
        }
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                if (self::containsYieldOrAwaitExpression($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node && self::containsYieldOrAwaitExpression($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Per §15.7.1: ClassStaticBlockBody cannot contain a `return`
     * statement (no return target available).
     */
    private function validateNoTopLevelReturnNodes(Node $node): void
    {
        if ($node instanceof \Phasis\Ast\Statement\ReturnStatement) {
            throw new \Phasis\Exceptions\SyntaxError(
                'Illegal return statement inside class static block',
            );
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof ClassDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
        ) {
            return;
        }
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                $this->validateNoTopLevelReturnNodes($value);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        $this->validateNoTopLevelReturnNodes($item);
                    }
                }
            }
        }
    }

    /**
     * Validate break/continue statements within a function body or static
     * block. Unlabeled break needs an enclosing loop or switch; unlabeled
     * continue needs an enclosing loop. Labeled break/continue requires
     * the label to be declared on an enclosing labeled statement, all
     * within the same function/static-block scope.
     */
    private static function validateBreakContinue(Node $body): void
    {
        self::walkBreakContinue($body, [], 0, 0);
    }

    /**
     * @param array<string,'loop'|'block'> $labels label name → kind
     */
    private static function walkBreakContinue(
        Node $node,
        array $labels,
        int $loopDepth,
        int $switchDepth,
    ): void {
        if ($node instanceof BreakStatement) {
            if ($node->label === null) {
                if ($loopDepth === 0 && $switchDepth === 0) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        'Illegal break statement: no enclosing loop or switch',
                    );
                }
                return;
            }
            if (!isset($labels[$node->label])) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Undefined label '{$node->label}'",
                );
            }
            return;
        }
        if ($node instanceof ContinueStatement) {
            if ($node->label === null) {
                if ($loopDepth === 0) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        'Illegal continue statement: no enclosing loop',
                    );
                }
                return;
            }
            if (!isset($labels[$node->label]) || $labels[$node->label] !== 'loop') {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Undefined label '{$node->label}' for continue",
                );
            }
            return;
        }
        // Function/class boundaries reset all break/continue/label scopes.
        if (
            $node instanceof \Phasis\Ast\Declaration\FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
        ) {
            return;
        }
        $isLoop = $node instanceof ForStatement
            || $node instanceof \Phasis\Ast\Statement\ForInStatement
            || $node instanceof \Phasis\Ast\Statement\ForOfStatement
            || $node instanceof \Phasis\Ast\Statement\WhileStatement
            || $node instanceof DoWhileStatement;
        $isSwitch = $node instanceof \Phasis\Ast\Statement\SwitchStatement;
        if ($node instanceof LabeledStatement) {
            if (isset($labels[$node->label])) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Label '{$node->label}' has already been declared",
                );
            }
            $kind = ($node->body instanceof ForStatement
                || $node->body instanceof \Phasis\Ast\Statement\ForInStatement
                || $node->body instanceof \Phasis\Ast\Statement\ForOfStatement
                || $node->body instanceof \Phasis\Ast\Statement\WhileStatement
                || $node->body instanceof DoWhileStatement)
                ? 'loop' : 'block';
            $newLabels = $labels;
            $newLabels[$node->label] = $kind;
            self::walkBreakContinue($node->body, $newLabels, $loopDepth, $switchDepth);
            return;
        }
        $newLoop = $loopDepth + ($isLoop ? 1 : 0);
        $newSwitch = $switchDepth + ($isSwitch ? 1 : 0);
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                self::walkBreakContinue($value, $labels, $newLoop, $newSwitch);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        self::walkBreakContinue($item, $labels, $newLoop, $newSwitch);
                    }
                }
            }
        }
    }

    /**
     * Per §15.2.1: in a function body, the LexicallyDeclaredNames of the
     * body cannot include any of the BoundNames of the FormalParameters.
     * @param Node[] $params
     */
    private function validateParamLexBindingOverlap(
        array $params,
        Node $body,
    ): void {
        if (!$body instanceof \Phasis\Ast\Statement\BlockStatement) {
            return;
        }
        $paramNames = [];
        foreach ($params as $p) {
            foreach (self::collectPatternNames($p) as $n) {
                $paramNames[$n] = true;
            }
        }
        if (empty($paramNames)) {
            return;
        }
        foreach ($body->body as $stmt) {
            $names = [];
            if (
                $stmt instanceof VariableDeclaration
                && in_array($stmt->kind, ['let', 'const', 'using', 'await using'], true)
            ) {
                foreach ($stmt->declarations as $d) {
                    foreach (self::collectPatternNames($d->id) as $n) {
                        $names[] = $n;
                    }
                }
            } elseif ($stmt instanceof ClassDeclaration && $stmt->id !== null) {
                $names[] = $stmt->id->name;
            } elseif ($stmt instanceof FunctionDeclaration && $stmt->id !== null) {
                // Top-level FunctionDeclaration in function body is var-hoisted
                // (TopLevelLexicallyDeclaredNames excludes HoistableDeclaration),
                // so it does not overlap with parameter names.
                continue;
            }
            foreach ($names as $n) {
                if (isset($paramNames[$n])) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Identifier '{$n}' has already been declared",
                    );
                }
            }
        }
    }

    /**
     * @param array<mixed> $params
     */
    private function validateStrictDirectiveWithNonSimpleParams(
        array $params,
        Node $body,
        \Phasis\Lexer\SourceLocation $location,
    ): void {
        if (!$body instanceof \Phasis\Ast\Statement\BlockStatement) {
            return;
        }
        $nonSimple = self::hasNonSimpleParameterList($params);
        $bodyStrict = self::bodyHasUseStrictDirective($body);
        if ($bodyStrict && $nonSimple) {
            throw new ParseError(
                "Illegal 'use strict' directive in function with non-simple parameter list",
                new \Phasis\Lexer\Token(TokenType::String, '', $location),
            );
        }
        // A `use strict` directive in the body retroactively promotes all
        // string literals in the body to strict rules. Legacy octal escapes
        // anywhere in the body (including the directive prologue itself) are
        // a SyntaxError per §12.9.4.1.
        if ($bodyStrict) {
            $this->rejectLegacyOctalStringsIn($body);
        }
        // Duplicate parameter names are a strict-mode SyntaxError, regardless
        // of whether the params are simple. A non-simple list (defaults,
        // destructuring, rest) is always an error for duplicates even in
        // sloppy mode per §15.2.1 early error.
        $effectiveStrict = $this->strictMode || $bodyStrict;
        if ($effectiveStrict || $nonSimple) {
            self::validateUniqueParameterNames($params, $location);
        }
        // In strict mode, no parameter may be 'arguments', 'eval', or any
        // strict-mode reserved word (let, static, yield, implements,
        // interface, package, private, protected, public). These are
        // rejected at parse time when the function inherits strict mode
        // from its body's "use strict" directive (retroactive strict).
        if ($effectiveStrict) {
            foreach ($params as $p) {
                if ($p === null) {
                    continue;
                }
                foreach (self::collectPatternNames($p) as $name) {
                    if (
                        $name === 'arguments'
                        || $name === 'eval'
                        || $name === 'let'
                        || $name === 'static'
                        || $name === 'yield'
                        || self::isStrictModeFutureReserved($name)
                    ) {
                        throw new ParseError(
                            "Parameter name '{$name}' may not be used in strict mode",
                            new \Phasis\Lexer\Token(TokenType::Identifier, $name, $location),
                        );
                    }
                }
            }
        }
    }

    /**
     * @param Node[] $params
     */
    private static function validateUniqueParameterNames(
        array $params,
        \Phasis\Lexer\SourceLocation $location,
    ): void {
        $seen = [];
        foreach ($params as $p) {
            foreach (self::collectPatternNames($p) as $name) {
                if (isset($seen[$name])) {
                    throw new ParseError(
                        "Duplicate parameter name '{$name}' not allowed in this context",
                        new \Phasis\Lexer\Token(TokenType::Identifier, $name, $location),
                    );
                }
                $seen[$name] = true;
            }
        }
    }

    /**
     * Walk a subtree and throw if any Literal node was marked as containing a
     * legacy octal escape sequence. Called when a `use strict` directive is
     * found in a function body or Program.
     */
    private function rejectLegacyOctalStringsIn(?Node $node): void
    {
        if ($node === null) {
            return;
        }
        if ($node instanceof Literal && $this->stringsWithLegacyOctal->offsetExists($node)) {
            throw new \Phasis\Exceptions\SyntaxError(
                'Octal escape sequences are not allowed in strict mode',
            );
        }
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                $this->rejectLegacyOctalStringsIn($value);
            } elseif (is_array($value)) {
                foreach ($value as $v) {
                    if ($v instanceof Node) {
                        $this->rejectLegacyOctalStringsIn($v);
                    }
                }
            }
        }
    }

    private static function bodyHasUseStrictDirective(\Phasis\Ast\Statement\BlockStatement $body): bool
    {
        foreach ($body->body as $stmt) {
            if (!$stmt instanceof ExpressionStatement) {
                break;
            }
            $expr = $stmt->expression;
            if (!$expr instanceof Literal || !is_string($expr->value)) {
                break;
            }
            if ($expr->value === 'use strict' && $expr->verbatim) {
                return true;
            }
            // Other directive strings don't count, but keep scanning.
        }
        return false;
    }

    /**
     * @param Node[] $params
     */
    private static function hasNonSimpleParameterList(array $params): bool
    {
        foreach ($params as $p) {
            if (
                $p instanceof \Phasis\Ast\Pattern\AssignmentPattern
                || $p instanceof \Phasis\Ast\Pattern\ArrayPattern
                || $p instanceof \Phasis\Ast\Pattern\ObjectPattern
                || $p instanceof \Phasis\Ast\Pattern\RestElement
            ) {
                return true;
            }
        }
        return false;
    }

    /** @param Node[] $decorators */
    private function parseClassDeclaration(array $decorators = []): ClassDeclaration
    {
        $location = $this->expect(TokenType::Class_)->location;
        $startOffset = $location->offset;
        $id = null;
        // Class declarations are always strict (§15.7.1). Reject `yield`,
        // `let`, `static`, etc. as the class name.
        $prevStrict = $this->strictMode;
        $this->strictMode = true;
        try {
            if ($this->canStartBindingIdentifier()) {
                $id = $this->parseIdentifier();
            }
        } finally {
            $this->strictMode = $prevStrict;
        }
        $superClass = null;
        if ($this->eat(TokenType::Extends)) {
            // ClassHeritage is parsed in strict mode (the entire class is
            // strict per §15.7.1).
            $prevStrict = $this->strictMode;
            $this->strictMode = true;
            try {
                $superClass = $this->parseLeftHandSideExpression();
            } finally {
                $this->strictMode = $prevStrict;
            }
            // ClassHeritage is LeftHandSideExpression, which excludes
            // ArrowFunction / AssignmentExpression / ConditionalExpression
            // when they appear at the top level. Parenthesized arrows
            // are allowed because the outer ParenthesizedExpression IS
            // a PrimaryExpression / LHS.
            if (
                ($superClass instanceof \Phasis\Ast\Expression\ArrowFunction
                    || $superClass instanceof \Phasis\Ast\Expression\AssignmentExpression
                    || $superClass instanceof \Phasis\Ast\Expression\ConditionalExpression)
                && !$this->parenthesized->offsetExists($superClass)
            ) {
                throw new ParseError(
                    'Invalid class heritage expression',
                    $this->current(),
                );
            }
        }
        $body = $this->parseClassBody();
        // SuperCall is only allowed in a derived constructor.
        if ($superClass === null) {
            self::validateNoSuperCallInConstructor($body);
        }
        return new ClassDeclaration(
            $location,
            $id,
            $superClass,
            $body,
            $this->extractSource($startOffset),
            $decorators,
        );
    }

    /**
     * Walk the constructor body of a non-derived class for any
     * SuperCall expression (super() vs super.x). The latter is allowed
     * (it's MemberExpression super.x — not a call).
     *
     * @param Node[] $classBody
     */
    private static function validateNoSuperCallInConstructor(array $classBody): void
    {
        foreach ($classBody as $element) {
            if (
                $element instanceof ClassMethod
                && $element->kind === 'constructor'
            ) {
                self::walkForSuperCallOnly($element->value->body);
            }
        }
    }

    /**
     * Check whether the current token can start a BindingIdentifier.
     * Accepts plain Identifier plus contextual keywords allowed as identifiers.
     */
    private function canStartBindingIdentifier(): bool
    {
        $type = $this->current()->type;
        return $type === TokenType::Identifier
            || $type === TokenType::Let
            || $type === TokenType::Static_
            || $type === TokenType::Of
            || $type === TokenType::Yield
            || $type === TokenType::Await
            || $type === TokenType::Async;
    }

    /**
     * Parse a list of decorators: @expr (@expr)*
     *
     * @return Node[]
     */
    private function parseDecoratorList(): array
    {
        $decorators = [];
        while ($this->check(TokenType::At)) {
            $decorators[] = $this->parseDecorator();
        }
        return $decorators;
    }

    /**
     * Parse a single decorator: @ DecoratorExpression
     *
     * DecoratorExpression:
     *   DecoratorMemberExpression
     *   DecoratorMemberExpression Arguments (call expression)
     *   DecoratorParenthesizedExpression
     *
     * DecoratorMemberExpression:
     *   IdentifierReference
     *   DecoratorMemberExpression . IdentifierName
     *   DecoratorMemberExpression . PrivateIdentifier
     */
    private function parseDecorator(): Node
    {
        $this->expect(TokenType::At);
        $location = $this->current()->location;

        // Parenthesized expression: @(expr)
        if ($this->check(TokenType::LeftParen)) {
            $this->advance();
            $expr = $this->parseAssignmentExpression();
            $this->expect(TokenType::RightParen);
            return $expr;
        }

        // Member expression: @foo.bar.baz or @foo
        // The initial identifier can be a keyword used as identifier (e.g. await, yield).
        $token = $this->current();
        if ($token->type === TokenType::Identifier || $token->type->isKeyword()) {
            $this->advance();
            $expr = new Identifier($token->location, $token->value);
        } else {
            throw new ParseError('Expected decorator expression', $token);
        }

        while ($this->check(TokenType::Dot)) {
            $this->advance();
            if ($this->check(TokenType::PrivateIdentifier)) {
                $token = $this->advance();
                $prop = new PrivateIdentifier($token->location, $token->value);
            } else {
                $prop = $this->parsePropertyName();
            }
            $expr = new MemberExpression($location, $expr, $prop, false, false);
        }

        // Optional call: @foo(args) or @foo.bar(args)
        if ($this->check(TokenType::LeftParen)) {
            $this->advance(); // consume '('
            $args = $this->parseArguments();
            $expr = new CallExpression($location, $expr, $args);
        }

        return $expr;
    }

    /** @return Node[] Returns ClassMethod, ClassProperty, or StaticBlock nodes. */
    private function parseClassBody(): array
    {
        // Class bodies are always strict per spec §15.7.1, regardless of any
        // surrounding Use Strict directive. Stay strict while parsing
        // everything inside the class braces.
        $prevStrict = $this->strictMode;
        $this->strictMode = true;
        $this->expect(TokenType::LeftBrace);
        $elements = [];

        // Track PrivateBoundNames for duplicate detection. Per spec the same
        // name can only co-exist when it is a getter+setter pair with one
        // static and one non-static (or the inverse).
        $privateNames = [];

        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            if ($this->eat(TokenType::Semicolon)) {
                continue;
            }
            // Parse decorators before each class element
            $elementDecorators = [];
            if ($this->check(TokenType::At)) {
                $elementDecorators = $this->parseDecoratorList();
            }
            $element = $this->parseClassElement();
            // Attach decorators to the element
            if (!empty($elementDecorators)) {
                if ($element instanceof ClassMethod) {
                    $element = new ClassMethod(
                        $element->location,
                        $element->key,
                        $element->value,
                        $element->kind,
                        $element->static,
                        $element->computed,
                        $elementDecorators,
                    );
                } elseif ($element instanceof ClassProperty) {
                    $element = new ClassProperty(
                        $element->location,
                        $element->key,
                        $element->value,
                        $element->static,
                        $element->computed,
                        $elementDecorators,
                        $element->isAccessor,
                    );
                }
            }
            // Track PrivateBoundNames for duplicate detection.
            if ($element instanceof ClassMethod && $element->key instanceof \Phasis\Ast\Expression\PrivateIdentifier) {
                $pName = $element->key->name;
                $pKind = $element->kind; // 'method' | 'get' | 'set' | 'constructor'
                $pStatic = $element->static;
                // Spec: a private name may repeat only when one declaration is
                // a getter and the other is a setter with the same static-ness.
                foreach ($privateNames as $prev) {
                    if ($prev['name'] !== $pName) {
                        continue;
                    }
                    $isPair = (
                        ($prev['kind'] === 'get' && $pKind === 'set')
                        || ($prev['kind'] === 'set' && $pKind === 'get')
                    );
                    if (!$isPair || $prev['static'] !== $pStatic) {
                        throw new ParseError(
                            "Duplicate private name '#{$pName}' in class body",
                            $this->current(),
                        );
                    }
                }
                $privateNames[] = ['name' => $pName, 'kind' => $pKind, 'static' => $pStatic];
            } elseif ($element instanceof ClassProperty && $element->key instanceof \Phasis\Ast\Expression\PrivateIdentifier) {
                $pName = $element->key->name;
                foreach ($privateNames as $prev) {
                    if ($prev['name'] === $pName) {
                        throw new ParseError(
                            "Duplicate private name '#{$pName}' in class body",
                            $this->current(),
                        );
                    }
                }
                $privateNames[] = ['name' => $pName, 'kind' => 'field', 'static' => $element->static];
            }
            $elements[] = $element;
        }

        $this->expect(TokenType::RightBrace);
        $this->strictMode = $prevStrict;
        // Per §15.7.1: at most one constructor, no get/set named
        // "constructor", no async/generator constructor, no static method
        // named "prototype".
        $ctorCount = 0;
        foreach ($elements as $el) {
            if (!($el instanceof ClassMethod)) {
                continue;
            }
            if ($el->kind === 'constructor') {
                $ctorCount++;
                if ($ctorCount > 1) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        'A class may only have one constructor',
                    );
                }
            }
            if (!$el->static && !$el->computed) {
                $name = self::staticPropName($el->key);
                if (
                    $name === 'constructor'
                    && in_array($el->kind, ['get', 'set'], true)
                ) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "A constructor cannot be a get/set method",
                    );
                }
                if (
                    $name === 'constructor'
                    && ($el->value->generator || $el->value->async)
                ) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        'Class constructor cannot be a generator or async',
                    );
                }
            }
            if ($el->static && !$el->computed) {
                $name = self::staticPropName($el->key);
                if ($name === 'prototype') {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Class static method cannot be named 'prototype'",
                    );
                }
            }
        }
        return $elements;
    }

    /** Parse a single class element: method, field, or static block. */
    private function parseClassElement(): Node
    {
        $prevMethodLike = $this->inMethodLike;
        $this->inMethodLike = true;
        try {
            return $this->parseClassElementInner();
        } finally {
            $this->inMethodLike = $prevMethodLike;
        }
    }

    private function parseClassElementInner(): Node
    {
        $location = $this->current()->location;
        $isStatic = false;

        // Check for 'static' keyword
        if ($this->check(TokenType::Static_)) {
            $next = $this->peek();
            // Contextual keyword `static` cannot be escaped when used as
            // the static modifier. As a property name it's allowed.
            $isModifier = $next->type !== TokenType::LeftParen
                && $next->type !== TokenType::Equal
                && $next->type !== TokenType::Semicolon
                && $next->type !== TokenType::RightBrace;
            if ($isModifier && $this->current()->rawValue === 'escaped') {
                throw new ParseError(
                    "Keyword 'static' must not contain escaped characters",
                    $this->current(),
                );
            }

            // static { ... } is a static block. Per spec, the body is
            // parsed with [Yield=false, Await=false] regardless of the
            // surrounding context, so `yield` and `await` inside become
            // plain identifiers (and are rejected in strict mode).
            if ($next->type === TokenType::LeftBrace) {
                $this->advance(); // consume 'static'
                $prevGenerator = $this->inGenerator;
                $prevAsync = $this->inAsync;
                $prevStaticBlock = $this->inStaticBlock;
                $this->inGenerator = false;
                $this->inAsync = false;
                $this->inStaticBlock = true;
                try {
                    $body = $this->parseBlockStatement(true);
                } finally {
                    $this->inGenerator = $prevGenerator;
                    $this->inAsync = $prevAsync;
                    $this->inStaticBlock = $prevStaticBlock;
                }
                // Per §15.7.1: ClassStaticBlockBody applies the same
                // LexicallyDeclaredNames vs VarDeclaredNames overlap rule
                // as a regular Block. (Function bodies use TopLevelLex
                // semantics which skip HoistableDeclarations; static
                // blocks do not.)
                $this->validateBlockDuplicateLexNames($body->body);
                // Static blocks cannot contain top-level `return`.
                $this->validateNoTopLevelReturnNodes($body);
                // Static blocks cannot contain SuperCall.
                self::walkForSuperCallOnly($body);
                // Per §15.7.1: ClassStaticBlockBody cannot contain
                // `arguments` (ContainsArguments static semantic).
                self::walkClassFieldInitializer($body);
                $this->consumeSemicolon();
                return new StaticBlock($location, $body);
            }

            // 'static' used as a modifier (not as a property name)
            // It's a modifier if the next token is NOT '(' (method named static),
            // '=' (field named static), ';' (field named static with no initializer)
            if (
                $next->type !== TokenType::LeftParen
                && $next->type !== TokenType::Equal
                && $next->type !== TokenType::Semicolon
                && !($next->type === TokenType::RightBrace)
            ) {
                // Check also for ASI: if next token has lineTerminatorBefore and
                // could be the start of the next element, then 'static' is a field name.
                // But in most cases, 'static' is a modifier.
                $this->advance();
                $isStatic = true;
            }
        }

        // Method source starts AFTER 'static' (if present), at the first substantive token.
        $methodStartOffset = $this->current()->location->offset;

        $isAsync = false;
        $isGenerator = false;
        $kind = 'method';
        $computed = false;

        // Try to parse modifiers (async, *, get, set) but we may need to backtrack
        // if they turn out to be field names.
        $savedPos = $this->pos;

        if ($this->check(TokenType::Async)) {
            $next = $this->peek();
            if (
                !$next->lineTerminatorBefore
                && $next->type !== TokenType::LeftParen
                && $next->type !== TokenType::Equal
                && $next->type !== TokenType::Semicolon
                && $next->type !== TokenType::RightBrace
            ) {
                // Contextual keyword `async` cannot be a unicode escape
                // when used as the async-method modifier.
                if ($this->current()->rawValue === 'escaped') {
                    throw new ParseError(
                        "Keyword 'async' must not contain escaped characters",
                        $this->current(),
                    );
                }
                $this->advance();
                $isAsync = true;
            }
        }

        // Auto-accessor field: `accessor name[;| = init]`. Per ES2023
        // decorators §15.7.3, this declares a hidden storage slot and a
        // getter / setter pair on the prototype (or constructor for static).
        $isAccessor = false;
        if (
            !$isAsync
            && $this->checkContextual('accessor')
        ) {
            $next = $this->peek();
            if (
                !$next->lineTerminatorBefore
                && $next->type !== TokenType::LeftParen
                && $next->type !== TokenType::Equal
                && $next->type !== TokenType::Semicolon
                && $next->type !== TokenType::RightBrace
                && $next->type !== TokenType::Star
            ) {
                $this->advance();
                $isAccessor = true;
            }
        }

        if ($this->eat(TokenType::Star)) {
            $isGenerator = true;
        }

        if ($this->checkContextual('get')) {
            $next = $this->peek();
            if (
                $next->type !== TokenType::LeftParen
                && $next->type !== TokenType::Equal
                && $next->type !== TokenType::Semicolon
                && $next->type !== TokenType::RightBrace
                && $next->type !== TokenType::Star
            ) {
                if ($this->current()->rawValue === 'escaped') {
                    throw new ParseError(
                        "Keyword 'get' must not contain escaped characters",
                        $this->current(),
                    );
                }
                $this->advance();
                $kind = 'get';
            }
        } elseif ($this->checkContextual('set')) {
            $next = $this->peek();
            if (
                $next->type !== TokenType::LeftParen
                && $next->type !== TokenType::Equal
                && $next->type !== TokenType::Semicolon
                && $next->type !== TokenType::RightBrace
                && $next->type !== TokenType::Star
            ) {
                if ($this->current()->rawValue === 'escaped') {
                    throw new ParseError(
                        "Keyword 'set' must not contain escaped characters",
                        $this->current(),
                    );
                }
                $this->advance();
                $kind = 'set';
            }
        }

        // Parse the key
        if ($this->check(TokenType::LeftBracket)) {
            $computed = true;
            $this->advance();
            $savedNoIn = $this->noIn;
            $this->noIn = false;
            $key = $this->parseAssignmentExpression();
            $this->noIn = $savedNoIn;
            $this->expect(TokenType::RightBracket);
        } elseif ($this->check(TokenType::PrivateIdentifier)) {
            $token = $this->advance();
            $key = new PrivateIdentifier($token->location, $token->value);
            // Per §15.7.1: PrivateName cannot be "#constructor".
            if (ltrim($key->name, '#') === 'constructor') {
                throw new ParseError(
                    'Private name #constructor is not allowed in class body',
                    $token,
                );
            }
        } else {
            $key = $this->parsePropertyName();
        }

        // Determine if this is a field or a method: if the next token is '(', it's a method.
        if (!$this->check(TokenType::LeftParen)) {
            // This is a field declaration (not a method).
            // If we consumed async/get/set/*, those were actually the field name.
            // We need to handle this: if isAsync/isGenerator/kind!=method was set but
            // now we see it's a field, the key we parsed IS the field key.
            $value = null;
            if ($this->eat(TokenType::Equal)) {
                // A class FieldDefinition initializer is parsed in a synthetic
                // non-async, non-generator function. Inside an async function
                // or generator, that synthetic function shadows [Await]/[Yield],
                // so `await` and `yield` revert to IdentifierReferences.
                //
                // The synthetic function created for a field initializer is
                // non-async and non-generator, so [Yield]/[Await] reset to
                // false. Module top-level still reserves `await` as a
                // module-level identifier, but that is enforced separately
                // by parseAwaitAsIdentifier.
                $prevYield = $this->inGenerator;
                $prevAsync = $this->inAsync;
                $this->inGenerator = false;
                $this->inAsync = false;
                try {
                    $value = $this->parseAssignmentExpression();
                } finally {
                    $this->inGenerator = $prevYield;
                    $this->inAsync = $prevAsync;
                }
                // Per §15.7.1 early errors: a class field initializer
                // cannot contain `SuperCall` or reference `arguments`
                // (except inside a nested function/class boundary).
                self::validateClassFieldInitializer($value);
            }
            $this->consumeSemicolon();

            // Normalize PropName for field name checks: handles identifier,
            // string literal, and numeric literal keys. Computed keys skip
            // these checks (their name is not statically known).
            $propName = $computed ? null : self::staticPropName($key);
            if ($propName === 'constructor' && !$isStatic) {
                throw new ParseError(
                    'Classes may not have a field named \'constructor\'',
                    $this->current(),
                );
            }
            // Per §15.7.1: static field PropName may not be "prototype" or
            // "constructor" (computed keys are evaluated at runtime, so the
            // early error only applies to literal-name static fields).
            if ($isStatic && ($propName === 'prototype' || $propName === 'constructor')) {
                throw new ParseError(
                    "Classes may not have a static field named '{$propName}'",
                    $this->current(),
                );
            }

            return new ClassProperty($location, $key, $value, $isStatic, $computed, [], $isAccessor);
        }

        // It's a method. Only plain methods named 'constructor' become
        // the class constructor; getters/setters/generators/async named
        // 'constructor' keep their original kind (and are rejected by
        // the class-body validator). Per spec, StringLiteralPropertyName
        // with value "constructor" is treated identically to the bare
        // identifier — it's still the class constructor.
        $isConstructorName = (
            ($key instanceof Identifier && $key->name === 'constructor')
            || ($key instanceof Literal && $key->value === 'constructor')
        );
        if (
            $isConstructorName
            && !$isStatic
            && $kind === 'method'
            && !$isGenerator
            && !$isAsync
            && !$computed
        ) {
            $kind = 'constructor';
        }

        // Private identifiers cannot be named "constructor".
        if (
            $key instanceof PrivateIdentifier
            && (ltrim($key->name, '#') === 'constructor')
        ) {
            throw new \Phasis\Exceptions\SyntaxError(
                'Private name #constructor is not allowed in class body',
            );
        }

        // Set inAsync/inGenerator BEFORE parsing parameters so default
        // expressions and binding identifiers see the correct context.
        $prevGenerator = $this->inGenerator;
        $prevAsync = $this->inAsync;
        $prevTopLevel = $this->topLevel;
        $prevStaticBlock = $this->inStaticBlock;
        $this->inGenerator = $isGenerator;
        $this->inAsync = $isAsync;
        $this->topLevel = false;
        $this->inStaticBlock = false;
        $params = $this->parseFormalParameters();
        // Per §15.5.1 / §15.6.1: getters take 0 params, setters take 1.
        if ($kind === 'get' && count($params) !== 0) {
            throw new ParseError(
                'Getter must not have any formal parameters',
                new \Phasis\Lexer\Token(TokenType::Identifier, '', $location),
            );
        }
        if ($kind === 'set') {
            if (count($params) !== 1) {
                throw new ParseError(
                    'Setter must have exactly one formal parameter',
                    new \Phasis\Lexer\Token(TokenType::Identifier, '', $location),
                );
            }
            if ($params[0] instanceof \Phasis\Ast\Pattern\RestElement) {
                throw new ParseError(
                    'Setter parameter must not be a rest element',
                    new \Phasis\Lexer\Token(TokenType::Identifier, '', $location),
                );
            }
        }
        // Async/generator method params cannot contain Yield/Await expressions.
        if ($isAsync || $isGenerator) {
            foreach ($params as $p) {
                if (self::containsYieldOrAwaitExpression($p)) {
                    throw new ParseError(
                        'YieldExpression or AwaitExpression not permitted in async/generator method parameters',
                        new \Phasis\Lexer\Token(TokenType::Identifier, '', $location),
                    );
                }
            }
        }
        $body = $this->parseBlockStatement(true);
        $this->inGenerator = $prevGenerator;
        $this->inAsync = $prevAsync;
        $this->topLevel = $prevTopLevel;
        $this->inStaticBlock = $prevStaticBlock;

        $this->validateStrictDirectiveWithNonSimpleParams($params, $body, $location);
        $this->validateParamLexBindingOverlap($params, $body);
        // SuperCall (not super.prop) is only allowed inside a derived class
        // constructor. Reject it in any other method body or in any
        // method's parameter expressions.
        if ($kind !== 'constructor') {
            self::walkForSuperCallOnly($body);
        }
        // Per §15.7.1: parameter expressions cannot contain SuperCall in
        // any class method (only the derived constructor body can).
        foreach ($params as $p) {
            self::walkForSuperCallOnly($p);
        }

        $value = new FunctionExpression(
            $body->location,
            null,
            $params,
            $body,
            $isGenerator,
            $isAsync,
            $this->extractSource($methodStartOffset),
        );
        return new ClassMethod($location, $key, $value, $kind, $isStatic, $computed);
    }

    /**
     * Walk a method body rejecting SuperCall expressions (super()). Stops
     * at nested function/class boundaries like walkForSuperRef but only
     * looks for super call, not super property access.
     */
    private static function walkForSuperCallOnly(?Node $node): void
    {
        if ($node === null) {
            return;
        }
        if (
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Declaration\FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
        ) {
            return;
        }
        if (
            $node instanceof \Phasis\Ast\Expression\CallExpression
            && $node->callee instanceof \Phasis\Ast\Expression\Identifier
            && $node->callee->name === 'super'
        ) {
            throw new \Phasis\Exceptions\SyntaxError(
                "'super' call is only allowed in derived class constructors",
            );
        }
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                self::walkForSuperCallOnly($value);
            } elseif (is_array($value)) {
                foreach ($value as $v) {
                    if ($v instanceof Node) {
                        self::walkForSuperCallOnly($v);
                    }
                }
            }
        }
    }

    private function maybeParseAsyncFunction(): Node
    {
        $next = $this->peek();
        if ($next->type === TokenType::Function_ && !$next->lineTerminatorBefore) {
            // Contextual keyword `async` cannot be unicode-escaped here.
            if ($this->current()->rawValue === 'escaped') {
                throw new ParseError(
                    "Keyword 'async' must not contain escaped characters",
                    $this->current(),
                );
            }
            $location = $this->advance()->location; // consume 'async'
            $startOffset = $location->offset;
            $this->advance(); // consume 'function'
            $generator = $this->eat(TokenType::Star);
            $id = $this->parseIdentifier();
            $prevGenerator = $this->inGenerator;
            $prevAsync = $this->inAsync;
            $prevTopLevel = $this->topLevel;
            $prevStaticBlock = $this->inStaticBlock;
            $this->inGenerator = $generator;
            $this->inAsync = true;
            $this->topLevel = false;
            $this->inStaticBlock = false;
            $params = $this->parseFormalParameters();
            $body = $this->parseBlockStatement(true);
            $this->inGenerator = $prevGenerator;
            $this->inAsync = $prevAsync;
            $this->topLevel = $prevTopLevel;
            $this->inStaticBlock = $prevStaticBlock;
            $this->validateStrictDirectiveWithNonSimpleParams($params, $body, $location);
            $this->validateParamLexBindingOverlap($params, $body);
            // Plain async/generator functions cannot reference super.
            self::validateNoSuperInPlainFunctionBody($params, $body);
            // Async/generator function params cannot contain Yield/Await
            // expressions per §15.7/§15.8 early errors.
            foreach ($params as $p) {
                if (self::containsYieldOrAwaitExpression($p)) {
                    throw new ParseError(
                        'YieldExpression or AwaitExpression not permitted in async/generator parameters',
                        new \Phasis\Lexer\Token(TokenType::Identifier, '', $location),
                    );
                }
            }
            return new FunctionDeclaration(
                $location,
                $id,
                $params,
                $body,
                $generator,
                true,
                false,
                $this->extractSource($startOffset),
            );
        }

        return $this->parseExpressionStatement();
    }

    /** @return Node[] */
    private function parseFormalParameters(): array
    {
        $this->expect(TokenType::LeftParen);
        $params = [];

        while (!$this->check(TokenType::RightParen) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Ellipsis)) {
                $params[] = $this->parseRestElement();
                break;
            }

            $param = $this->parseBindingPattern();
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $param = new AssignmentPattern($param->location, $param, $default);
            }
            $params[] = $param;

            if (!$this->check(TokenType::RightParen)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightParen);
        return $params;
    }

    /**
     * Parse an if/else body: Annex B lets sloppy-mode code have a plain
     * FunctionDeclaration here, but strict mode and all Declaration-kinds
     * (generator, async, class, let, const, etc.) fall back to the normal
     * statement rules which reject them.
     */
    private function parseIfBodyStatement(): Node
    {
        $token = $this->current();
        if (
            $token->type === TokenType::Function_
            && !$this->strictMode
            && $this->peek()->type !== TokenType::Star
        ) {
            return $this->parseFunctionDeclaration();
        }
        $body = $this->parseStatement();
        self::rejectLabelledFunctionBody($body);
        return $body;
    }

    private function parseIfStatement(): IfStatement
    {
        $location = $this->expect(TokenType::If)->location;
        $this->expect(TokenType::LeftParen);
        $test = $this->parseExpression();
        $this->expect(TokenType::RightParen);

        // AnnexB B.3.3: in sloppy mode only, allow plain function
        // declarations (not generator/async) in if bodies. In strict mode
        // this is a parse error per spec.
        $consequent = $this->parseIfBodyStatement();
        $alternate = null;

        if ($this->eat(TokenType::Else)) {
            $alternate = $this->parseIfBodyStatement();
        }

        return new IfStatement($location, $test, $consequent, $alternate);
    }

    private function parseForStatement(): Node
    {
        $location = $this->expect(TokenType::For)->location;

        // for await (... of ...) -- only valid inside async functions/generators
        // and at module top level. Per §14.7.5.1, using `for await` outside
        // those contexts is a SyntaxError with a specific spec-mandated
        // message format that test262 checks for.
        $isAwait = false;
        if ($this->check(TokenType::Await)) {
            $awaitToken = $this->current();
            $isAwait = true;
            $this->advance();
            if (!$this->inAsync && !($this->topLevel && $this->moduleMode)) {
                throw new ParseError(
                    "for await (... of ...) is only valid in async functions and async generators",
                    $awaitToken,
                );
            }
        }

        $this->expect(TokenType::LeftParen);

        // Per spec: `let` is a declaration keyword in for-headers only when
        // followed by an identifier, `[`, or `{` (a binding pattern).
        // Otherwise (followed by `in`, `of`, `;`, `=`, etc.) treat `let` as
        // an identifier expression.
        $isLetDecl = $this->check(TokenType::Let)
            && ($this->peekIs(TokenType::Identifier)
                || $this->peekIs(TokenType::LeftBracket)
                || $this->peekIs(TokenType::LeftBrace)
                || $this->peekIs(TokenType::Let)
                || $this->peekIs(TokenType::Yield)
                || $this->peekIs(TokenType::Await)
                || $this->peekIs(TokenType::Static_)
                || $this->peekIs(TokenType::Of)
                || $this->peekIs(TokenType::Async));

        // for (var/let/const ...
        if ($this->check(TokenType::Var) || $isLetDecl || $this->check(TokenType::Const_)) {
            $kindToken = $this->advance();
            $kind = $kindToken->value;
            $id = $this->parseBindingPattern();
            // Per §13.3.1.1 early errors: let-bindings can't bind `let`,
            // and the pattern must not repeat a name.
            if ($kind === 'let' || $kind === 'const') {
                $seen = [];
                foreach (self::collectPatternNames($id) as $name) {
                    if ($name === 'let') {
                        throw new ParseError(
                            "'let' is not a valid binding identifier in let/const declarations",
                            $kindToken,
                        );
                    }
                    if (isset($seen[$name])) {
                        throw new ParseError(
                            "Identifier '{$name}' has already been declared",
                            $kindToken,
                        );
                    }
                    $seen[$name] = true;
                }
            }
            // Strict-mode: 'arguments' and 'eval' cannot be binding names.
            if ($this->strictMode) {
                foreach (self::collectPatternNames($id) as $name) {
                    if ($name === 'arguments' || $name === 'eval') {
                        throw new ParseError(
                            "Binding identifier '{$name}' may not be used in strict mode",
                            $kindToken,
                        );
                    }
                }
            }
            $init = null;

            // for (var x in ...) -- not valid with await
            if ($this->check(TokenType::In) && !$isAwait) {
                if ($this->current()->rawValue === 'escaped') {
                    throw new ParseError(
                        "Keyword 'in' must not contain escaped characters",
                        $this->current(),
                    );
                }
                $this->advance();
                $right = $this->parseExpression();
                $this->expect(TokenType::RightParen);
                $body = $this->parseSingleStmtBody();
                $left = new VariableDeclaration(
                    $kindToken->location,
                    $kind,
                    [new VariableDeclarator($id->location, $id, null)],
                );
                if ($kind !== 'var') {
                    self::validateForBindingNoVarOverlap($id, $body);
                }
                return new ForInStatement($location, $left, $right, $body);
            }

            // for (var x of ...)
            if ($this->check(TokenType::Of)) {
                if ($this->current()->rawValue === 'escaped') {
                    throw new ParseError(
                        "Keyword 'of' must not contain escaped characters",
                        $this->current(),
                    );
                }
                $this->advance();
                $right = $this->parseAssignmentExpression();
                $this->expect(TokenType::RightParen);
                $body = $this->parseSingleStmtBody();
                $left = new VariableDeclaration(
                    $kindToken->location,
                    $kind,
                    [new VariableDeclarator($id->location, $id, null)],
                );
                if ($kind !== 'var') {
                    self::validateForBindingNoVarOverlap($id, $body);
                }
                return new ForOfStatement($location, $left, $right, $body, $isAwait);
            }

            // Regular for with var declaration
            if ($this->eat(TokenType::Equal)) {
                // Parse with noIn so that `in` is not consumed as a binary operator.
                // This allows Annex B: for (var x = expr in obj).
                $this->noIn = true;
                $init = $this->parseAssignmentExpression();
                $this->noIn = false;
            }

            // Annex B: for (var x = expr in obj) is valid in sloppy mode.
            // The initializer is evaluated, then the for-in loop runs normally.
            if ($init !== null && $kind === 'var' && $this->check(TokenType::In)) {
                // `for await (var x = expr in obj)` is invalid: for-await
                // only takes for-of, never for-in.
                if ($isAwait) {
                    throw new ParseError(
                        "'for await' loops must be used with 'of'",
                        $this->current(),
                    );
                }
                // Strict mode: for-in initializers are always a SyntaxError.
                if ($this->strictMode) {
                    throw new ParseError(
                        'for-in initializer is not allowed in strict mode',
                        $this->current(),
                    );
                }
                // BindingPattern (array/object destructuring) with an initializer
                // is always a SyntaxError, even in non-strict mode.
                if (
                    $id instanceof \Phasis\Ast\Pattern\ArrayPattern
                    || $id instanceof \Phasis\Ast\Pattern\ObjectPattern
                ) {
                    throw new ParseError(
                        'for-in with destructuring pattern and initializer is not allowed',
                        $this->current(),
                    );
                }
                $this->advance();
                $right = $this->parseExpression();
                $this->expect(TokenType::RightParen);
                $body = $this->parseSingleStmtBody();
                $left = new VariableDeclaration(
                    $kindToken->location,
                    $kind,
                    [new VariableDeclarator($id->location, $id, $init)],
                );
                return new ForInStatement($location, $left, $right, $body);
            }

            $declarations = [new VariableDeclarator($id->location, $id, $init)];
            // Per spec 14.7.4: C-style for-init declarator initializers use
            // AssignmentExpressionNoIn, so a top-level `in` operator must be
            // rejected (the head can only be Annex B for-in if there is a
            // single declarator with an `in` token immediately after).
            $savedNoInList = $this->noIn;
            $this->noIn = true;
            while ($this->eat(TokenType::Comma)) {
                $declarations[] = $this->parseVariableDeclarator();
            }
            $this->noIn = $savedNoInList;
            // Per §13.3.1.1: const declarators in a C-style `for(;;)` head
            // must have initializers. The for-of/for-in branches bypass
            // this requirement (the iteration value initializes the
            // binding); we already returned for those above.
            if ($kind === 'const') {
                foreach ($declarations as $d) {
                    if ($d->init === null) {
                        throw new ParseError(
                            "Missing initializer in const declaration",
                            $kindToken,
                        );
                    }
                }
            }
            $varDecl = new VariableDeclaration($kindToken->location, $kind, $declarations);
            $this->expect(TokenType::Semicolon);
            $test = $this->check(TokenType::Semicolon) ? null : $this->parseExpression();
            $this->expect(TokenType::Semicolon);
            $update = $this->check(TokenType::RightParen) ? null : $this->parseExpression();
            $this->expect(TokenType::RightParen);
            $body = $this->parseSingleStmtBody();
            // Per §14.7.5.1: `for await` is only valid as for-of (not C-style
            // or for-in). Reject the C-style fall-through here.
            if ($isAwait) {
                throw new ParseError(
                    "'for await' loops must be used with 'of'",
                    $this->current(),
                );
            }
            // Per §14.7.4.1: lex-bound names in for-init can't overlap
            // with var-declared names in the body Statement.
            if ($kind !== 'var') {
                foreach ($declarations as $d) {
                    self::validateForBindingNoVarOverlap($d->id, $body);
                }
            }
            return new ForStatement($location, $varDecl, $test, $update, $body);
        }

        // for (;;)
        if ($this->check(TokenType::Semicolon)) {
            $this->advance();
            $test = $this->check(TokenType::Semicolon) ? null : $this->parseExpression();
            $this->expect(TokenType::Semicolon);
            $update = $this->check(TokenType::RightParen) ? null : $this->parseExpression();
            $this->expect(TokenType::RightParen);
            $body = $this->parseSingleStmtBody();
            // `for await (;;)` is a SyntaxError per §14.7.5.1.
            if ($isAwait) {
                throw new ParseError(
                    "'for await' loops must be used with 'of'",
                    $this->current(),
                );
            }
            return new ForStatement($location, null, $test, $update, $body);
        }

        // for (expr in/of ...) — use NoIn so `in` is treated as for-in
        // separator. The expression position can be a destructuring
        // assignment pattern (refined later when `in`/`of` is seen), so
        // allow CoverInitializedName temporarily.
        $this->noIn = true;
        $prevAllowCoverInit = $this->allowCoverInit;
        $this->allowCoverInit = true;
        try {
            $init = $this->parseExpression();
        } finally {
            $this->noIn = false;
            $this->allowCoverInit = $prevAllowCoverInit;
        }
        $isForInOrOf = $this->check(TokenType::In) || $this->check(TokenType::Of);
        if (!$isForInOrOf) {
            // It was an Expression after all; validate now that we know.
            self::rejectCoverInitializedName($init);
        }

        if ($this->check(TokenType::In) && !$isAwait) {
            // Per §14.7.5.1: for-in LHS must be a valid AssignmentTarget
            // (or destructuring pattern).
            if (!self::isValidAssignmentTarget($init, true)) {
                throw new ParseError(
                    'Invalid left-hand side in for-in',
                    $this->current(),
                );
            }
            $this->validateAsAssignmentPattern($init);
            if ($this->current()->rawValue === 'escaped') {
                throw new ParseError(
                    "Keyword 'in' must not contain escaped characters",
                    $this->current(),
                );
            }
            $this->advance();
            $right = $this->parseExpression();
            $this->expect(TokenType::RightParen);
            $body = $this->parseSingleStmtBody();
            return new ForInStatement($location, $init, $right, $body);
        }

        if ($this->check(TokenType::Of)) {
            // Per §14.7.5.1: for-of LHS must be a valid AssignmentTarget.
            if (!self::isValidAssignmentTarget($init, true)) {
                throw new ParseError(
                    'Invalid left-hand side in for-of',
                    $this->current(),
                );
            }
            // Per §14.7.5.1: literal `async` (not escaped, not
            // parenthesized) cannot be the LHS of a for-of (resolves an
            // ambiguity with `for await`).
            if (
                !$isAwait
                && $init instanceof Identifier
                && $init->name === 'async'
                && !$this->parenthesized->offsetExists($init)
                && substr($this->source, $init->location->offset, 5) === 'async'
            ) {
                throw new ParseError(
                    "Identifier 'async' is not allowed as for-of LHS",
                    $this->current(),
                );
            }
            $this->validateAsAssignmentPattern($init);
            if ($this->current()->rawValue === 'escaped') {
                throw new ParseError(
                    "Keyword 'of' must not contain escaped characters",
                    $this->current(),
                );
            }
            $this->advance();
            $right = $this->parseAssignmentExpression();
            $this->expect(TokenType::RightParen);
            $body = $this->parseSingleStmtBody();
            return new ForOfStatement($location, $init, $right, $body, $isAwait);
        }

        // Regular for. `for await (expr ;;)` is a SyntaxError per §14.7.5.1.
        if ($isAwait) {
            throw new ParseError(
                "'for await' loops must be used with 'of'",
                $this->current(),
            );
        }
        $this->expect(TokenType::Semicolon);
        $test = $this->check(TokenType::Semicolon) ? null : $this->parseExpression();
        $this->expect(TokenType::Semicolon);
        $update = $this->check(TokenType::RightParen) ? null : $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $body = $this->parseSingleStmtBody();
        return new ForStatement($location, $init, $test, $update, $body);
    }

    private function parseWhileStatement(): WhileStatement
    {
        $location = $this->expect(TokenType::While)->location;
        $this->expect(TokenType::LeftParen);
        $test = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $body = $this->parseSingleStmtBody();
        return new WhileStatement($location, $test, $body);
    }

    private function parseDoWhileStatement(): DoWhileStatement
    {
        $location = $this->expect(TokenType::Do)->location;
        $body = $this->parseSingleStmtBody();
        $this->expect(TokenType::While);
        $this->expect(TokenType::LeftParen);
        $test = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        // ASI special case: do-while always allows semicolon insertion after )
        $this->eat(TokenType::Semicolon);
        return new DoWhileStatement($location, $body, $test);
    }

    private function parseSwitchStatement(): SwitchStatement
    {
        $location = $this->expect(TokenType::Switch)->location;
        $this->expect(TokenType::LeftParen);
        $discriminant = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $this->expect(TokenType::LeftBrace);

        $cases = [];
        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            $cases[] = $this->parseSwitchCase();
        }

        $this->expect(TokenType::RightBrace);
        $this->parseSwitchStatementValidateCases($cases);
        return new SwitchStatement($location, $discriminant, $cases);
    }

    private function parseSwitchCase(): SwitchCase
    {
        $location = $this->current()->location;
        $test = null;

        if ($this->eat(TokenType::Case)) {
            $test = $this->parseExpression();
        } else {
            $this->expect(TokenType::Default_);
        }

        $this->expect(TokenType::Colon);

        $consequent = [];
        $prevModuleTopLevel = $this->moduleTopLevel;
        $this->moduleTopLevel = false;
        try {
            while (
                !$this->check(TokenType::Case)
                && !$this->check(TokenType::Default_)
                && !$this->check(TokenType::RightBrace)
                && !$this->isAtEnd()
            ) {
                $consequent[] = $this->parseStatementOrDeclaration();
            }
        } finally {
            $this->moduleTopLevel = $prevModuleTopLevel;
        }

        return new SwitchCase($location, $test, $consequent);
    }

    /**
     * @param array<mixed> $cases
     */
    private function parseSwitchStatementValidateCases(array $cases): void
    {
        // Per §14.12.1 Early Errors: a CaseBlock can have at most one
        // DefaultClause.
        $defaultSeen = false;
        foreach ($cases as $case) {
            if ($case->test === null) {
                if ($defaultSeen) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        'More than one default clause in switch statement',
                    );
                }
                $defaultSeen = true;
            }
        }
        // Per §14.12.1 Early Errors: the LexicallyDeclaredNames of
        // CaseBlock must be unique across all cases and must not overlap
        // with VarDeclaredNames.
        $allBody = [];
        foreach ($cases as $case) {
            foreach ($case->consequent as $stmt) {
                $allBody[] = $stmt;
            }
        }
        $this->validateBlockDuplicateLexNames($allBody);
    }

    private function parseReturnStatement(): ReturnStatement
    {
        $location = $this->expect(TokenType::Return)->location;

        $isVoidReturn = $this->check(TokenType::Semicolon)
            || $this->check(TokenType::RightBrace)
            || $this->current()->lineTerminatorBefore
            || $this->isAtEnd();
        if ($isVoidReturn) {
            $this->consumeSemicolon();
            return new ReturnStatement($location, null);
        }

        $argument = $this->parseExpression();
        $this->consumeSemicolon();
        return new ReturnStatement($location, $argument);
    }

    private function parseThrowStatement(): ThrowStatement
    {
        $location = $this->expect(TokenType::Throw)->location;

        if ($this->current()->lineTerminatorBefore) {
            throw new ParseError('Illegal newline after throw', $this->current());
        }

        $argument = $this->parseExpression();
        $this->consumeSemicolon();
        return new ThrowStatement($location, $argument);
    }

    private function parseTryStatement(): TryStatement
    {
        $location = $this->expect(TokenType::Try)->location;
        $block = $this->parseBlockStatement();
        $handler = null;
        $finalizer = null;

        if ($this->check(TokenType::Catch)) {
            $handlerLocation = $this->advance()->location;
            $param = null;
            if ($this->eat(TokenType::LeftParen)) {
                $param = $this->parseBindingPattern();
                $this->expect(TokenType::RightParen);
                // Per §14.15.1: BoundNames of CatchParameter must be unique.
                $names = self::collectPatternNames($param);
                $seen = [];
                foreach ($names as $n) {
                    if (isset($seen[$n])) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Identifier '{$n}' has already been declared",
                        );
                    }
                    $seen[$n] = true;
                    // Strict mode: catch parameter cannot bind 'eval' or
                    // 'arguments' anywhere in its (possibly destructured)
                    // shape. Identifier-form catch params are already
                    // validated by parseIdentifier; this catches the
                    // destructured cases like `catch ([eval])` or
                    // `catch ({x: arguments})`.
                    if (
                        $this->strictMode
                        && ($n === 'eval' || $n === 'arguments')
                    ) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Binding identifier '{$n}' may not be used in strict mode",
                        );
                    }
                }
            }
            $handlerBody = $this->parseBlockStatement();
            // Per §14.15.1: catch parameter names cannot overlap with the
            // LexicallyDeclaredNames of the catch Block. Annex B exempts
            // single-binding catch parameters when the body's `var` (or
            // top-level FunctionDeclaration in sloppy mode) has the same
            // name; the strict rule applies to let/const/class names.
            if ($param !== null) {
                $paramNames = self::collectPatternNames($param);
                if (!empty($paramNames)) {
                    $paramSet = array_flip($paramNames);
                    foreach ($handlerBody->body as $stmt) {
                        $declared = [];
                        if (
                            $stmt instanceof VariableDeclaration
                            && in_array($stmt->kind, ['let', 'const', 'using', 'await using'], true)
                        ) {
                            foreach ($stmt->declarations as $d) {
                                foreach (self::collectPatternNames($d->id) as $n) {
                                    $declared[] = $n;
                                }
                            }
                        } elseif ($stmt instanceof ClassDeclaration && $stmt->id !== null) {
                            $declared[] = $stmt->id->name;
                        } elseif (
                            $stmt instanceof FunctionDeclaration
                            && $stmt->id !== null
                        ) {
                            $declared[] = $stmt->id->name;
                        }
                        foreach ($declared as $n) {
                            if (isset($paramSet[$n])) {
                                throw new \Phasis\Exceptions\SyntaxError(
                                    "Identifier '{$n}' has already been declared",
                                );
                            }
                        }
                    }
                }
            }
            $handler = new CatchClause($handlerLocation, $param, $handlerBody);
        }

        if ($this->eat(TokenType::Finally)) {
            $finalizer = $this->parseBlockStatement();
        }

        if ($handler === null && $finalizer === null) {
            throw new ParseError('Missing catch or finally after try', $this->current());
        }

        return new TryStatement($location, $block, $handler, $finalizer);
    }

    private function parseBreakStatement(): BreakStatement
    {
        $location = $this->expect(TokenType::Break)->location;
        $label = null;

        if (!$this->current()->lineTerminatorBefore && $this->check(TokenType::Identifier)) {
            $label = $this->advance()->value;
        }

        $this->consumeSemicolon();
        return new BreakStatement($location, $label);
    }

    private function parseContinueStatement(): ContinueStatement
    {
        $location = $this->expect(TokenType::Continue)->location;
        $label = null;

        if (!$this->current()->lineTerminatorBefore && $this->check(TokenType::Identifier)) {
            $label = $this->advance()->value;
        }

        $this->consumeSemicolon();
        return new ContinueStatement($location, $label);
    }

    private function parseEmptyStatement(): EmptyStatement
    {
        $location = $this->expect(TokenType::Semicolon)->location;
        return new EmptyStatement($location);
    }

    private function parseDebuggerStatement(): DebuggerStatement
    {
        $location = $this->expect(TokenType::Debugger)->location;
        $this->consumeSemicolon();
        return new DebuggerStatement($location);
    }

    private function parseWithStatement(): WithStatement
    {
        $location = $this->expect(TokenType::With)->location;
        // Per §14.11.1: `with` statements are disallowed in strict mode.
        if ($this->strictMode) {
            throw new ParseError(
                "'with' statements are not allowed in strict mode",
                new \Phasis\Lexer\Token(TokenType::With, 'with', $location),
            );
        }
        $this->expect(TokenType::LeftParen);
        $object = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $body = $this->parseSingleStmtBody();
        return new WithStatement($location, $object, $body);
    }

    /**
     * Per §13.1/§13.13.1: the Statement body of if/for/while/do-while must
     * not be a LabelledStatement whose inner body is a FunctionDeclaration.
     * Applies in both strict and non-strict code.
     */
    private static function rejectLabelledFunctionBody(Node $stmt): void
    {
        $cur = $stmt;
        while ($cur instanceof LabeledStatement) {
            if ($cur->body instanceof FunctionDeclaration) {
                throw new \Phasis\Exceptions\SyntaxError(
                    'Labelled function declaration cannot appear as body of a control statement',
                );
            }
            $cur = $cur->body;
        }
    }

    /**
     * Parse a Statement for use as the body of a control-flow construct
     * (for, for-in, for-of, while, do-while, if). Validates that the body
     * is not a LabelledStatement whose innermost body is a FunctionDeclaration.
     */
    private function parseSingleStmtBody(): Node
    {
        $body = $this->parseStatement();
        self::rejectLabelledFunctionBody($body);
        return $body;
    }

    private function parseExpressionOrLabeledStatement(): Node
    {
        $this->rejectExpressionStatementLookahead();
        $expr = $this->parseExpression();

        // label: statement
        if ($expr instanceof Identifier && $this->check(TokenType::Colon)) {
            // Per §13.13: LabelIdentifier is BindingIdentifier — reserved
            // words (super, enum, this, etc.) are never valid as labels.
            // Strict-mode reserved words are validated by parseIdentifier
            // when they reach the binding-identifier slot, but `super`
            // (and `enum`) can sneak through via parsePrimary returning a
            // synthetic Identifier; reject them at the label slot here.
            if (
                $expr->name === 'super'
                || $expr->name === 'enum'
                || self::isReservedWordIdentifierName($expr->name)
            ) {
                throw new ParseError(
                    "Unexpected reserved word '{$expr->name}'",
                    $this->current(),
                );
            }
            $this->advance();
            // Per Annex B §B.3.2: `label: function f() {}` is permitted in
            // non-strict code at the top level of a Script, FunctionBody,
            // or Block, but is always a SyntaxError when used as the body
            // of a for/while/if statement. parseForStatement (and similar)
            // guard against this via validateNotLabelledFunction(body).
            if (
                !$this->strictMode
                && $this->check(TokenType::Function_)
                && $this->peek()->type !== TokenType::Star
            ) {
                $body = $this->parseFunctionDeclaration();
                return new LabeledStatement($expr->location, $expr->name, $body);
            }
            $body = $this->parseStatement();
            return new LabeledStatement($expr->location, $expr->name, $body);
        }

        $this->consumeSemicolon();
        return new ExpressionStatement($expr->location, $expr);
    }

    private function parseExpressionStatement(): Node
    {
        $this->rejectExpressionStatementLookahead();
        $expr = $this->parseExpression();
        $this->consumeSemicolon();
        return new ExpressionStatement($expr->location, $expr);
    }

    /**
     * Per §14.5: ExpressionStatement cannot start with one of:
     *   `{`, `function`, `async function`, `class`, `let [`.
     * Most are caught by the surrounding parseStatement match; the `let [`
     * restriction needs an explicit lookahead.
     */
    private function rejectExpressionStatementLookahead(): void
    {
        if ($this->check(TokenType::Let) && $this->peek()->type === TokenType::LeftBracket) {
            throw new ParseError(
                "Expression statement cannot start with 'let ['",
                $this->current(),
            );
        }
    }
}
