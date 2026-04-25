<?php

declare(strict_types=1);

namespace PhpJs\Parser;

use PhpJs\Ast\Declaration\ClassDeclaration;
use PhpJs\Ast\Declaration\ExportDeclaration;
use PhpJs\Ast\Declaration\ExportSpecifier;
use PhpJs\Ast\Declaration\FunctionDeclaration;
use PhpJs\Ast\Declaration\ImportDeclaration;
use PhpJs\Ast\Declaration\ImportSpecifier;
use PhpJs\Ast\Declaration\VariableDeclaration;
use PhpJs\Ast\Declaration\VariableDeclarator;
use PhpJs\Ast\Expression\ArrayExpression;
use PhpJs\Ast\Expression\ArrowFunction;
use PhpJs\Ast\Expression\AssignmentExpression;
use PhpJs\Ast\Expression\AwaitExpression;
use PhpJs\Ast\Expression\BinaryExpression;
use PhpJs\Ast\Expression\CallExpression;
use PhpJs\Ast\Expression\ClassExpression;
use PhpJs\Ast\Expression\ClassMethod;
use PhpJs\Ast\Expression\ClassProperty;
use PhpJs\Ast\Expression\PrivateIdentifier;
use PhpJs\Ast\Expression\StaticBlock;
use PhpJs\Ast\Expression\ConditionalExpression;
use PhpJs\Ast\Expression\ImportExpression;
use PhpJs\Ast\Expression\MetaProperty;
use PhpJs\Ast\Expression\FunctionExpression;
use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Expression\Literal;
use PhpJs\Ast\Expression\LogicalExpression;
use PhpJs\Ast\Expression\MemberExpression;
use PhpJs\Ast\Expression\NewExpression;
use PhpJs\Ast\Expression\ObjectExpression;
use PhpJs\Ast\Expression\Property;
use PhpJs\Ast\Expression\SequenceExpression;
use PhpJs\Ast\Expression\SpreadElement;
use PhpJs\Ast\Expression\TemplateLiteral;
use PhpJs\Ast\Expression\TemplateElement;
use PhpJs\Ast\Expression\TaggedTemplate;
use PhpJs\Ast\Expression\ThisExpression;
use PhpJs\Ast\Expression\UnaryExpression;
use PhpJs\Ast\Expression\UpdateExpression;
use PhpJs\Ast\Expression\YieldExpression;
use PhpJs\Ast\Node;
use PhpJs\Ast\Pattern\ArrayPattern;
use PhpJs\Ast\Pattern\AssignmentPattern;
use PhpJs\Ast\Pattern\AssignmentProperty;
use PhpJs\Ast\Pattern\ObjectPattern;
use PhpJs\Ast\Pattern\RestElement;
use PhpJs\Ast\Program;
use PhpJs\Ast\Statement\BlockStatement;
use PhpJs\Ast\Statement\BreakStatement;
use PhpJs\Ast\Statement\CatchClause;
use PhpJs\Ast\Statement\ContinueStatement;
use PhpJs\Ast\Statement\DebuggerStatement;
use PhpJs\Ast\Statement\DoWhileStatement;
use PhpJs\Ast\Statement\EmptyStatement;
use PhpJs\Ast\Statement\ExpressionStatement;
use PhpJs\Ast\Statement\ForInStatement;
use PhpJs\Ast\Statement\ForOfStatement;
use PhpJs\Ast\Statement\ForStatement;
use PhpJs\Ast\Statement\IfStatement;
use PhpJs\Ast\Statement\LabeledStatement;
use PhpJs\Ast\Statement\ReturnStatement;
use PhpJs\Ast\Statement\SwitchCase;
use PhpJs\Ast\Statement\SwitchStatement;
use PhpJs\Ast\Statement\ThrowStatement;
use PhpJs\Ast\Statement\TryStatement;
use PhpJs\Ast\Statement\WhileStatement;
use PhpJs\Ast\Statement\WithStatement;
use PhpJs\Lexer\Lexer;
use PhpJs\Lexer\SourceLocation;
use PhpJs\Lexer\Token;
use PhpJs\Lexer\TokenType;

class Parser
{
    private int $pos = 0;
    private bool $noIn = false;
    private bool $inGenerator = false;
    private bool $inAsync = false;
    private bool $inStaticBlock = false;
    /** True when we are parsing a member of a class body or an object method
     *  shorthand — i.e. a context that has a HomeObject and allows `super`. */
    private bool $inMethodLike = false;
    private bool $allowCoverInit = false;
    private bool $topLevel = true;

    /**
     * True at the top level of a Script or Module body. False inside any
     * nested block, switch case, function, or other inner statement list.
     * Import/Export declarations require this to be true. (Distinct from
     * `topLevel`, which also covers the outer statement level inside
     * nested blocks so that module-top-level await is recognized.)
     */
    private bool $moduleTopLevel = true;
    private bool $moduleMode = false;
    private bool $strictMode = false;
    private string $source = '';

    /** Tracks nodes that were wrapped in parentheses, for IsIdentifierRef checks. */
    private \SplObjectStorage $parenthesized;

    /** Tracks ArrayExpressions where a trailing comma followed a rest element. */
    private \SplObjectStorage $arrayExpressionsWithTrailingCommaAfterRest;

    /** Tracks string Literal nodes that contain a legacy octal escape. */
    private \SplObjectStorage $stringsWithLegacyOctal;

    public function __construct(string $source)
    {
        $this->source = $source;
        $this->parenthesized = new \SplObjectStorage();
        $this->arrayExpressionsWithTrailingCommaAfterRest = new \SplObjectStorage();
        $this->stringsWithLegacyOctal = new \SplObjectStorage();
        $this->lexer = new Lexer($source);
    }

    /** @var Lexer */
    private Lexer $lexer;

    /** @var Token[]|null */
    private ?array $tokens = null;

    private function ensureTokenized(): void
    {
        if ($this->tokens === null) {
            $this->lexer->setModuleMode($this->moduleMode);
            $this->tokens = $this->lexer->tokenize();
        }
    }

    /**
     * Pre-seed strict mode for this parser. Callers that know their caller's
     * context (e.g. direct eval inside strict code) should set this so
     * strict-mode early errors (legacy octal literals, reserved identifiers,
     * etc.) are enforced even when the source has no own "use strict"
     * directive.
     */
    /**
     * Enable module-goal parsing semantics. In script mode `await` at the top
     * level is an identifier; in module mode it is a reserved word / the
     * top-level-await keyword.
     */
    public function setModuleMode(bool $module): void
    {
        $this->moduleMode = $module;
        // Module source is implicitly strict per spec §11.2.2; apply that so
        // strict-only parse-time checks (yield/eval/arguments as binding
        // identifier, legacy octals, etc.) fire during module parsing.
        if ($module) {
            $this->strictMode = true;
        }
    }

    public function setStrictMode(bool $strict): void
    {
        $this->strictMode = $strict;
    }

    /**
     * Mark the parser as currently inside a method-like context. Direct eval
     * callers (from a class field initializer or method body) set this so
     * parser super-early-error checks inherit the enclosing context.
     */
    public function setInMethodLike(bool $v): void
    {
        $this->inMethodLike = $v;
    }

    /**
     * Extract source text from $startOffset to the end of the last consumed token.
     * Used for Function.prototype.toString().
     */
    private function extractSource(int $startOffset): string
    {
        $prev = $this->tokens[$this->pos - 1];
        $endOffset = $prev->location->offset + strlen($prev->value);
        return substr($this->source, $startOffset, $endOffset - $startOffset);
    }

    public function parse(): Program
    {
        // Tokenize lazily, so moduleMode set after construction affects it.
        $this->ensureTokenized();
        $location = $this->current()->location;
        $body = [];

        // Scan the directive prologue for "use strict" so downstream parsing
        // can enforce strict-mode early errors (e.g. legacy octal literals).
        // Preserve any strict mode already set by the caller (direct eval in
        // strict code, strict modules, etc.).
        if (!$this->strictMode) {
            $this->strictMode = $this->hasUseStrictPrologue();
        }

        while (!$this->isAtEnd()) {
            $body[] = $this->parseStatementOrDeclaration();
        }

        $program = new Program($location, $body);
        // Validate break/continue scopes at the script/module level. The
        // validator descends into nested function and arrow bodies and
        // re-applies the scope rules independently for each.
        self::validateBreakContinueProgram($program);
        // Module-level early errors (duplicate exports etc.) so that
        // syntax errors are reported before any module loading.
        if ($this->moduleMode) {
            $this->validateModuleExportNames($body);
        }
        return $program;
    }

    /**
     * Per §16.1.7: ExportedNames must be unique. Detect duplicate
     * `default` exports and other duplicate ExportedName entries.
     *
     * @param Node[] $body
     */
    private function validateModuleExportNames(array $body): void
    {
        $exported = [];
        $addExport = function (string $name) use (&$exported): void {
            if (isset($exported[$name])) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Duplicate export of '{$name}'",
                );
            }
            $exported[$name] = true;
        };
        foreach ($body as $stmt) {
            if (!($stmt instanceof \PhpJs\Ast\Declaration\ExportDeclaration)) {
                continue;
            }
            if ($stmt->isDefault) {
                $addExport('default');
            }
            foreach ($stmt->specifiers ?? [] as $spec) {
                $addExport($spec->exported ?? $spec->local);
            }
            if ($stmt->isAll && $stmt->allAs !== null) {
                $addExport($stmt->allAs);
            }
            if ($stmt->declaration !== null) {
                $inner = $stmt->declaration;
                if (
                    $inner instanceof \PhpJs\Ast\Declaration\FunctionDeclaration
                    || $inner instanceof \PhpJs\Ast\Declaration\ClassDeclaration
                ) {
                    if ($inner->id !== null) {
                        $addExport($inner->id->name);
                    }
                } elseif ($inner instanceof VariableDeclaration) {
                    foreach ($inner->declarations as $d) {
                        foreach (self::collectPatternNames($d->id) as $n) {
                            $addExport($n);
                        }
                    }
                }
            }
        }
    }

    /**
     * Validate break/continue at every function-scope boundary in the
     * program. Walk the AST looking for function/arrow/class boundaries
     * and validate break/continue independently in each.
     */
    private static function validateBreakContinueProgram(Node $node): void
    {
        if (
            $node instanceof \PhpJs\Ast\Declaration\FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\FunctionExpression
        ) {
            self::validateBreakContinue($node->body);
            // Also walk the body to find nested functions/classes/arrows
            // whose bodies need their own validation.
            self::walkForNestedFunctionScopes($node->body);
            return;
        }
        if ($node instanceof \PhpJs\Ast\Expression\ArrowFunction) {
            self::validateBreakContinue($node->body);
            self::walkForNestedFunctionScopes($node->body);
            return;
        }
        // For class bodies / static blocks: each method body is a separate
        // scope, validated when we hit its FunctionExpression. Static
        // blocks are also validated separately.
        if (
            $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
        ) {
            foreach ($node->body as $element) {
                if ($element instanceof \PhpJs\Ast\Expression\StaticBlock) {
                    self::validateBreakContinue($element->body);
                }
                self::validateBreakContinueProgram($element);
            }
            return;
        }
        if ($node instanceof Program) {
            // Validate break/continue at the script/module top level too.
            foreach ($node->body as $stmt) {
                self::walkBreakContinue($stmt, [], 0, 0);
            }
            foreach ($node->body as $stmt) {
                self::validateBreakContinueProgram($stmt);
            }
            return;
        }
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                self::validateBreakContinueProgram($value);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        self::validateBreakContinueProgram($item);
                    }
                }
            }
        }
    }

    private static function walkForNestedFunctionScopes(Node $node): void
    {
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof Node) {
                self::validateBreakContinueProgram($value);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        self::validateBreakContinueProgram($item);
                    }
                }
            }
        }
    }

    /**
     * Check for a "use strict" directive in the leading Directive Prologue.
     * Only non-escaped string literals ("use strict" or 'use strict' without
     * any \-escapes) count as a directive per spec 10.2.1.
     */
    private function hasUseStrictPrologue(): bool
    {
        $i = $this->pos;
        while ($i < count($this->tokens)) {
            $t = $this->tokens[$i];
            if ($t->type !== TokenType::String) {
                return false;
            }
            // Only verbatim "use strict" (no escapes) counts per spec.
            if ($t->value === 'use strict' && $t->rawValue === 'verbatim') {
                return true;
            }
            $i++;
            if ($i < count($this->tokens) && $this->tokens[$i]->type === TokenType::Semicolon) {
                $i++;
            }
        }
        return false;
    }

    /**
     * Look ahead from the current position (just past `{`) for a leading
     * "use strict" directive in the function body's directive prologue.
     */
    private function blockStartHasUseStrictPrologue(): bool
    {
        $i = $this->pos;
        while ($i < count($this->tokens)) {
            $t = $this->tokens[$i];
            if ($t->type !== TokenType::String) {
                return false;
            }
            if ($t->value === 'use strict' && $t->rawValue === 'verbatim') {
                return true;
            }
            $i++;
            if ($i < count($this->tokens) && $this->tokens[$i]->type === TokenType::Semicolon) {
                $i++;
            }
        }
        return false;
    }

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
        try {
            while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
                $body[] = $this->parseStatementOrDeclaration();
            }
        } finally {
            $this->moduleTopLevel = $prevModuleTopLevel;
            $this->strictMode = $prevStrict;
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
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Identifier '{$n}' has already been declared",
                            );
                        }
                        $lexNames[$n] = true;
                    }
                }
            } elseif ($stmt instanceof ClassDeclaration && $stmt->id !== null) {
                $n = $stmt->id->name;
                if (isset($lexNames[$n]) || isset($plainFuncNames[$n])) {
                    throw new \PhpJs\Exceptions\SyntaxError(
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
                        throw new \PhpJs\Exceptions\SyntaxError(
                            "Identifier '{$n}' has already been declared",
                        );
                    }
                    $plainFuncNames[$n] = true;
                } else {
                    if (isset($lexNames[$n]) || isset($plainFuncNames[$n])) {
                        throw new \PhpJs\Exceptions\SyntaxError(
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
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Identifier '{$n}' has already been declared",
                );
            }
        }
        // Plain (Annex B) function names in a block still conflict with var
        // declarations in the same block — only dup-function is relaxed.
        foreach (array_keys($plainFuncNames) as $n) {
            if (isset($varNames[$n])) {
                throw new \PhpJs\Exceptions\SyntaxError(
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
        if ($node instanceof \PhpJs\Ast\Statement\IfStatement) {
            self::collectVarDeclaredNames($node->consequent, $out);
            if ($node->alternate !== null) {
                self::collectVarDeclaredNames($node->alternate, $out);
            }
            return;
        }
        if ($node instanceof \PhpJs\Ast\Statement\ForStatement) {
            if ($node->init instanceof Node) {
                self::collectVarDeclaredNames($node->init, $out);
            }
            self::collectVarDeclaredNames($node->body, $out);
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Statement\ForInStatement
            || $node instanceof \PhpJs\Ast\Statement\ForOfStatement
        ) {
            if ($node->left instanceof VariableDeclaration) {
                self::collectVarDeclaredNames($node->left, $out);
            }
            self::collectVarDeclaredNames($node->body, $out);
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Statement\WhileStatement
            || $node instanceof \PhpJs\Ast\Statement\DoWhileStatement
        ) {
            self::collectVarDeclaredNames($node->body, $out);
            return;
        }
        if ($node instanceof \PhpJs\Ast\Statement\WithStatement) {
            self::collectVarDeclaredNames($node->body, $out);
            return;
        }
        if ($node instanceof \PhpJs\Ast\Statement\SwitchStatement) {
            foreach ($node->cases as $case) {
                foreach ($case->consequent as $s) {
                    self::collectVarDeclaredNames($s, $out);
                }
            }
            return;
        }
        if ($node instanceof \PhpJs\Ast\Statement\TryStatement) {
            self::collectVarDeclaredNames($node->block, $out);
            if ($node->handler !== null) {
                self::collectVarDeclaredNames($node->handler->body, $out);
            }
            if ($node->finalizer !== null) {
                self::collectVarDeclaredNames($node->finalizer, $out);
            }
            return;
        }
        if ($node instanceof \PhpJs\Ast\Statement\LabeledStatement) {
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
        if ($pattern instanceof \PhpJs\Ast\Pattern\ArrayPattern) {
            $out = [];
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $out = array_merge($out, self::collectPatternNames($elem));
                }
            }
            return $out;
        }
        if ($pattern instanceof \PhpJs\Ast\Pattern\ObjectPattern) {
            $out = [];
            foreach ($pattern->properties as $p) {
                if ($p instanceof \PhpJs\Ast\Pattern\AssignmentProperty) {
                    $out = array_merge($out, self::collectPatternNames($p->value));
                } elseif ($p instanceof \PhpJs\Ast\Pattern\RestElement) {
                    $out = array_merge($out, self::collectPatternNames($p->argument));
                }
            }
            return $out;
        }
        if ($pattern instanceof \PhpJs\Ast\Pattern\AssignmentPattern) {
            return self::collectPatternNames($pattern->left);
        }
        if ($pattern instanceof \PhpJs\Ast\Pattern\RestElement) {
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

        // Stage-3 proposals: `import defer * as ns from ...` and
        // `import source * as ns from ...`. Treat the phase modifier as a
        // no-op (we do not distinguish evaluation phases). Only consume the
        // modifier when a `*` unambiguously follows — `import defer from ...`
        // treats `defer` as an ordinary default-binding identifier.
        if (
            $this->check(TokenType::Identifier)
            && ($this->current()->value === 'defer' || $this->current()->value === 'source')
            && $this->peek()->type === TokenType::Star
        ) {
            $this->advance();
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
            return new ImportDeclaration($location, $specifiers, $source);
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
        return new ImportDeclaration($location, $specifiers, $source);
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
            // binding identifier, so reserved words (e.g. `class`, `enum`,
            // `return`) must raise SyntaxError — BindingIdentifier cannot
            // be a reserved word even in sloppy mode. Check by token type
            // (Enum, Class_, etc.) and also by decoded value so Unicode
            // escape forms like `enum` are still rejected.
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
    private static function validateAssignmentTargetObjectShorthand(ObjectExpression $obj): void
    {
        foreach ($obj->properties as $prop) {
            if (
                $prop instanceof Property
                && $prop->shorthand
                && $prop->key instanceof Identifier
                && self::isReservedWordIdentifierName($prop->key->name)
            ) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Unexpected reserved word '{$prop->key->name}' as shorthand property",
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
    private static function validateDeleteArgument(Node $argument, \PhpJs\Lexer\Token $token): void
    {
        $node = $argument;
        // Unwrap conditional chain / sequence without evaluation.
        while (true) {
            if (
                $node instanceof \PhpJs\Ast\Expression\MemberExpression
                && $node->property instanceof \PhpJs\Ast\Expression\PrivateIdentifier
            ) {
                throw new ParseError(
                    "Cannot delete a private member",
                    $token,
                );
            }
            if ($node instanceof \PhpJs\Ast\Expression\SequenceExpression) {
                $last = end($node->expressions);
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
            return (new \PhpJs\Value\JsNumber($key->value))->toJsString();
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
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Declaration\FunctionDeclaration
        ) {
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
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
        if ($node instanceof \PhpJs\Ast\Expression\Identifier && $node->name === 'arguments') {
            throw new \PhpJs\Exceptions\SyntaxError(
                "'arguments' is not allowed in class field initializers",
            );
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\CallExpression
            && $node->callee instanceof \PhpJs\Ast\Expression\Identifier
            && $node->callee->name === 'super'
        ) {
            throw new \PhpJs\Exceptions\SyntaxError(
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
                $node instanceof \PhpJs\Ast\Expression\ObjectExpression
                || $node instanceof \PhpJs\Ast\Expression\ArrayExpression
            ) {
                return true;
            }
            // Patterns after reinterpretation.
            if (
                $node instanceof \PhpJs\Ast\Pattern\ObjectPattern
                || $node instanceof \PhpJs\Ast\Pattern\ArrayPattern
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
                if ($p !== null && self::containsYieldOrAwaitExpression($p)) {
                    throw new ParseError(
                        'YieldExpression not permitted in generator parameters',
                        new \PhpJs\Lexer\Token(TokenType::Identifier, '', $location),
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
            if ($p !== null) {
                self::walkForSuperCallOnly($p);
            }
        }
        self::walkForSuperCallOnly($body);
    }

    private static function walkForSuperRef(?Node $node): void
    {
        if ($node === null) {
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Declaration\FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            return;
        }
        // super.prop / super[expr]
        if (
            $node instanceof \PhpJs\Ast\Expression\MemberExpression
            && $node->object instanceof \PhpJs\Ast\Expression\Identifier
            && $node->object->name === 'super'
        ) {
            throw new \PhpJs\Exceptions\SyntaxError(
                "'super' keyword not allowed in this context",
            );
        }
        // super()
        if (
            $node instanceof \PhpJs\Ast\Expression\CallExpression
            && $node->callee instanceof \PhpJs\Ast\Expression\Identifier
            && $node->callee->name === 'super'
        ) {
            throw new \PhpJs\Exceptions\SyntaxError(
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
                throw new \PhpJs\Exceptions\SyntaxError(
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
        if ($node instanceof ObjectExpression) {
            $count = count($node->properties);
            for ($i = 0; $i < $count; $i++) {
                $prop = $node->properties[$i];
                if ($prop instanceof Property) {
                    if ($prop->kind !== 'init' || $prop->method) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            'Invalid destructuring pattern: method definition not allowed',
                        );
                    }
                    if ($prop->value !== null) {
                        $this->validateAsAssignmentPattern($prop->value);
                    }
                } elseif ($prop instanceof SpreadElement) {
                    if ($i !== $count - 1) {
                        throw new \PhpJs\Exceptions\SyntaxError(
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
                    throw new \PhpJs\Exceptions\SyntaxError(
                        'Invalid destructuring pattern: comma expression not allowed',
                    );
                }
                if ($el instanceof SpreadElement) {
                    if ($i !== $count - 1) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            'Array rest element must be last',
                        );
                    }
                    if ($el->argument instanceof AssignmentExpression) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            'Array rest element may not have a default initializer',
                        );
                    }
                    $this->validateAsAssignmentPattern($el->argument);
                    continue;
                }
                $this->validateAsAssignmentPattern($el);
            }
            // Reject trailing comma after a rest element in patterns.
            if ($this->arrayExpressionsWithTrailingCommaAfterRest->contains($node)) {
                throw new \PhpJs\Exceptions\SyntaxError(
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
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Binding identifier '{$node->left->name}' may not be used in strict mode",
                );
            }
            $this->validateAsAssignmentPattern($node->left);
            return;
        }
        if (
            $this->strictMode
            && $node instanceof Identifier
            && ($node->name === 'eval' || $node->name === 'arguments')
        ) {
            throw new \PhpJs\Exceptions\SyntaxError(
                "Binding identifier '{$node->name}' may not be used in strict mode",
            );
        }
        // Per §13.10.1: MetaProperty (new.target, import.meta) is never a
        // valid AssignmentTarget, including inside a destructuring pattern.
        if ($node instanceof \PhpJs\Ast\Expression\MetaProperty) {
            throw new \PhpJs\Exceptions\SyntaxError(
                'Invalid destructuring assignment target: MetaProperty',
            );
        }
        if (
            $node instanceof Identifier
            && ($node->name === '[[NewTarget]]' || $node->name === '[[ImportMeta]]')
        ) {
            throw new \PhpJs\Exceptions\SyntaxError(
                'Invalid destructuring assignment target: MetaProperty',
            );
        }
        // Other nodes like Identifier, MemberExpression, etc. are valid
        // assignment targets at the leaf level.
    }

    private static function containsYieldOrAwaitExpression(?Node $node): bool
    {
        if ($node === null) {
            return false;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\YieldExpression
            || $node instanceof \PhpJs\Ast\Expression\AwaitExpression
        ) {
            return true;
        }
        // Don't descend into nested function/class bodies — those define
        // their own [Yield]/[Await] context.
        if (
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Declaration\FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
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
        if ($node instanceof \PhpJs\Ast\Statement\ReturnStatement) {
            throw new \PhpJs\Exceptions\SyntaxError(
                'Illegal return statement inside class static block',
            );
        }
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof ClassDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
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
     *
     * @param array<string,bool> $labels Labels declared by enclosing
     *        LabeledStatements (only used at recursive entry; the walker
     *        builds its own table)
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
                    throw new \PhpJs\Exceptions\SyntaxError(
                        'Illegal break statement: no enclosing loop or switch',
                    );
                }
                return;
            }
            if (!isset($labels[$node->label])) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Undefined label '{$node->label}'",
                );
            }
            return;
        }
        if ($node instanceof ContinueStatement) {
            if ($node->label === null) {
                if ($loopDepth === 0) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        'Illegal continue statement: no enclosing loop',
                    );
                }
                return;
            }
            if (!isset($labels[$node->label]) || $labels[$node->label] !== 'loop') {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Undefined label '{$node->label}' for continue",
                );
            }
            return;
        }
        // Function/class boundaries reset all break/continue/label scopes.
        if (
            $node instanceof \PhpJs\Ast\Declaration\FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Expression\ArrowFunction
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
        ) {
            return;
        }
        $isLoop = $node instanceof ForStatement
            || $node instanceof \PhpJs\Ast\Statement\ForInStatement
            || $node instanceof \PhpJs\Ast\Statement\ForOfStatement
            || $node instanceof \PhpJs\Ast\Statement\WhileStatement
            || $node instanceof DoWhileStatement;
        $isSwitch = $node instanceof \PhpJs\Ast\Statement\SwitchStatement;
        if ($node instanceof LabeledStatement) {
            if (isset($labels[$node->label])) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Label '{$node->label}' has already been declared",
                );
            }
            $kind = ($node->body instanceof ForStatement
                || $node->body instanceof \PhpJs\Ast\Statement\ForInStatement
                || $node->body instanceof \PhpJs\Ast\Statement\ForOfStatement
                || $node->body instanceof \PhpJs\Ast\Statement\WhileStatement
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
        if (!$body instanceof \PhpJs\Ast\Statement\BlockStatement) {
            return;
        }
        $paramNames = [];
        foreach ($params as $p) {
            if ($p === null) {
                continue;
            }
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
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Identifier '{$n}' has already been declared",
                    );
                }
            }
        }
    }

    private function validateStrictDirectiveWithNonSimpleParams(
        array $params,
        Node $body,
        \PhpJs\Lexer\SourceLocation $location,
    ): void {
        if (!$body instanceof \PhpJs\Ast\Statement\BlockStatement) {
            return;
        }
        $nonSimple = self::hasNonSimpleParameterList($params);
        $bodyStrict = self::bodyHasUseStrictDirective($body);
        if ($bodyStrict && $nonSimple) {
            throw new ParseError(
                "Illegal 'use strict' directive in function with non-simple parameter list",
                new \PhpJs\Lexer\Token(TokenType::String, '', $location),
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
        // In strict mode, no parameter may be 'arguments' or 'eval'.
        if ($effectiveStrict) {
            foreach ($params as $p) {
                if ($p === null) {
                    continue;
                }
                foreach (self::collectPatternNames($p) as $name) {
                    if ($name === 'arguments' || $name === 'eval') {
                        throw new ParseError(
                            "Parameter name '{$name}' may not be used in strict mode",
                            new \PhpJs\Lexer\Token(TokenType::Identifier, $name, $location),
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
        \PhpJs\Lexer\SourceLocation $location,
    ): void {
        $seen = [];
        foreach ($params as $p) {
            foreach (self::collectPatternNames($p) as $name) {
                if (isset($seen[$name])) {
                    throw new ParseError(
                        "Duplicate parameter name '{$name}' not allowed in this context",
                        new \PhpJs\Lexer\Token(TokenType::Identifier, $name, $location),
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
        if ($node instanceof Literal && $this->stringsWithLegacyOctal->contains($node)) {
            throw new \PhpJs\Exceptions\SyntaxError(
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

    private static function bodyHasUseStrictDirective(\PhpJs\Ast\Statement\BlockStatement $body): bool
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
                $p instanceof \PhpJs\Ast\Pattern\AssignmentPattern
                || $p instanceof \PhpJs\Ast\Pattern\ArrayPattern
                || $p instanceof \PhpJs\Ast\Pattern\ObjectPattern
                || $p instanceof \PhpJs\Ast\Pattern\RestElement
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
                ($superClass instanceof \PhpJs\Ast\Expression\ArrowFunction
                    || $superClass instanceof \PhpJs\Ast\Expression\AssignmentExpression
                    || $superClass instanceof \PhpJs\Ast\Expression\ConditionalExpression)
                && !$this->parenthesized->contains($superClass)
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
                && $element->value instanceof \PhpJs\Ast\Expression\FunctionExpression
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
                    );
                }
            }
            // Track PrivateBoundNames for duplicate detection.
            if ($element instanceof ClassMethod && $element->key instanceof \PhpJs\Ast\Expression\PrivateIdentifier) {
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
            } elseif ($element instanceof ClassProperty && $element->key instanceof \PhpJs\Ast\Expression\PrivateIdentifier) {
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
                    throw new \PhpJs\Exceptions\SyntaxError(
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
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "A constructor cannot be a get/set method",
                    );
                }
                if (
                    $name === 'constructor'
                    && $el->value instanceof \PhpJs\Ast\Expression\FunctionExpression
                    && ($el->value->generator || $el->value->async)
                ) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        'Class constructor cannot be a generator or async',
                    );
                }
            }
            if ($el->static && !$el->computed) {
                $name = self::staticPropName($el->key);
                if ($name === 'prototype') {
                    throw new \PhpJs\Exceptions\SyntaxError(
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

        // Auto-accessor field: `accessor name[;| = init]`. We accept the
        // syntax and evaluate it as a plain field declaration; the
        // accessor/decorator semantics are not fully implemented.
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
                $value = $this->parseAssignmentExpression();
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

            return new ClassProperty($location, $key, $value, $isStatic, $computed);
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
            throw new \PhpJs\Exceptions\SyntaxError(
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
            throw new ParseError('Getter must not have any formal parameters', $location);
        }
        if ($kind === 'set') {
            if (count($params) !== 1) {
                throw new ParseError('Setter must have exactly one formal parameter', $location);
            }
            if ($params[0] instanceof \PhpJs\Ast\Pattern\RestElement) {
                throw new ParseError('Setter parameter must not be a rest element', $location);
            }
        }
        // Async/generator method params cannot contain Yield/Await expressions.
        if ($isAsync || $isGenerator) {
            foreach ($params as $p) {
                if ($p !== null && self::containsYieldOrAwaitExpression($p)) {
                    throw new ParseError(
                        'YieldExpression or AwaitExpression not permitted in async/generator method parameters',
                        new \PhpJs\Lexer\Token(TokenType::Identifier, '', $location),
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
            if ($p !== null) {
                self::walkForSuperCallOnly($p);
            }
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
            $node instanceof \PhpJs\Ast\Expression\FunctionExpression
            || $node instanceof \PhpJs\Ast\Declaration\FunctionDeclaration
            || $node instanceof \PhpJs\Ast\Expression\ClassExpression
            || $node instanceof \PhpJs\Ast\Declaration\ClassDeclaration
        ) {
            return;
        }
        if (
            $node instanceof \PhpJs\Ast\Expression\CallExpression
            && $node->callee instanceof \PhpJs\Ast\Expression\Identifier
            && $node->callee->name === 'super'
        ) {
            throw new \PhpJs\Exceptions\SyntaxError(
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
                if ($p !== null && self::containsYieldOrAwaitExpression($p)) {
                    throw new ParseError(
                        'YieldExpression or AwaitExpression not permitted in async/generator parameters',
                        new \PhpJs\Lexer\Token(TokenType::Identifier, '', $location),
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

        // for await (... of ...) -- only valid inside async functions/generators.
        $isAwait = false;
        if ($this->check(TokenType::Await)) {
            $isAwait = true;
            $this->advance();
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
                    $id instanceof \PhpJs\Ast\Pattern\ArrayPattern
                    || $id instanceof \PhpJs\Ast\Pattern\ObjectPattern
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
            while ($this->eat(TokenType::Comma)) {
                $declarations[] = $this->parseVariableDeclarator();
            }
            $varDecl = new VariableDeclaration($kindToken->location, $kind, $declarations);
            $this->expect(TokenType::Semicolon);
            $test = $this->check(TokenType::Semicolon) ? null : $this->parseExpression();
            $this->expect(TokenType::Semicolon);
            $update = $this->check(TokenType::RightParen) ? null : $this->parseExpression();
            $this->expect(TokenType::RightParen);
            $body = $this->parseSingleStmtBody();
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
                && !$this->parenthesized->contains($init)
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

        // Regular for
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

    private function parseSwitchStatementValidateCases(array $cases): void
    {
        // Per §14.12.1 Early Errors: a CaseBlock can have at most one
        // DefaultClause.
        $defaultSeen = false;
        foreach ($cases as $case) {
            if ($case->test === null) {
                if ($defaultSeen) {
                    throw new \PhpJs\Exceptions\SyntaxError(
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
                        throw new \PhpJs\Exceptions\SyntaxError(
                            "Identifier '{$n}' has already been declared",
                        );
                    }
                    $seen[$n] = true;
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
                                throw new \PhpJs\Exceptions\SyntaxError(
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
                new \PhpJs\Lexer\Token(TokenType::With, 'with', $location),
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
                throw new \PhpJs\Exceptions\SyntaxError(
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
        if ($expr instanceof Identifier && $this->eat(TokenType::Colon)) {
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

    // -------------------------------------------------------------------------
    // Expressions (Pratt parser)
    // -------------------------------------------------------------------------

    private function parseExpression(): Node
    {
        $expr = $this->parseAssignmentExpression();

        // Sequence expression (comma operator)
        if ($this->check(TokenType::Comma)) {
            $expressions = [$expr];
            while ($this->eat(TokenType::Comma)) {
                $expressions[] = $this->parseAssignmentExpression();
            }
            return new SequenceExpression($expr->location, $expressions);
        }

        return $expr;
    }

    private function parseAssignmentExpression(): Node
    {
        // YieldExpression lives at AssignmentExpression in the grammar, so
        // it must be recognized here (not inside UnaryExpression) or `void
        // yield`, `typeof yield`, etc. would incorrectly parse as unary
        // operators applied to a YieldExpression.
        if (
            $this->check(TokenType::Yield)
            && $this->inGenerator
        ) {
            return $this->parseYieldExpression();
        }
        // The LHS may turn out to be a destructuring assignment pattern
        // (e.g. `({...} = expr)`). Defer cover-init / __proto__-duplicate
        // validation until we know whether `=` follows.
        $prevAllowCoverInit = $this->allowCoverInit;
        $this->allowCoverInit = true;
        try {
            $left = $this->parseConditionalExpression();
        } finally {
            $this->allowCoverInit = $prevAllowCoverInit;
        }

        if ($this->current()->type->isAssignmentOperator()) {
            $op = $this->advance();
            // AwaitExpression and YieldExpression are not valid simple
            // assignment targets (spec §13.1.1 Early Errors).
            if (
                $left instanceof \PhpJs\Ast\Expression\AwaitExpression
                || $left instanceof \PhpJs\Ast\Expression\YieldExpression
            ) {
                throw new ParseError(
                    $left instanceof \PhpJs\Ast\Expression\AwaitExpression
                        ? "Invalid left-hand side in assignment: AwaitExpression"
                        : "Invalid left-hand side in assignment: YieldExpression",
                    $op,
                );
            }
            // new.target and import.meta are invalid assignment targets.
            if ($left instanceof \PhpJs\Ast\Expression\MetaProperty) {
                throw new ParseError(
                    "Invalid left-hand side in assignment: MetaProperty",
                    $op,
                );
            }
            if (
                $left instanceof Identifier
                && ($left->name === '[[NewTarget]]' || $left->name === '[[ImportMeta]]')
            ) {
                throw new ParseError(
                    "Invalid left-hand side in assignment: MetaProperty",
                    $op,
                );
            }
            // Per §13.15.1: a non-simple, non-pattern LHS (literal, call
            // expression, unary expression, etc.) is a parse-time
            // SyntaxError. Only = allows ObjectLiteral/ArrayLiteral as
            // destructuring patterns; compound assignments don't.
            if (!self::isValidAssignmentTarget($left, $op->value === '=')) {
                throw new ParseError(
                    "Invalid left-hand side in assignment",
                    $op,
                );
            }
            // ParenthesizedExpression around an ObjectLiteral or ArrayLiteral
            // has AssignmentTargetType "invalid" per §13.2.8.
            if (
                $op->value === '='
                && ($left instanceof ObjectExpression || $left instanceof \PhpJs\Ast\Expression\ArrayExpression)
                && $this->parenthesized->contains($left)
            ) {
                throw new ParseError(
                    'Invalid left-hand side in assignment: parenthesized pattern',
                    $op,
                );
            }
            // For `=` assignment with ObjectLiteral / ArrayLiteral LHS, validate
            // that it can be refined into an AssignmentPattern — this catches
            // rest-with-default, rest-with-trailing-comma, nested literals, etc.
            if (
                $op->value === '='
                && ($left instanceof ObjectExpression || $left instanceof \PhpJs\Ast\Expression\ArrayExpression)
            ) {
                $this->validateAsAssignmentPattern($left);
            }
            $leftParenthesized = $this->parenthesized->contains($left);
            $right = $this->parseAssignmentExpression();
            // For destructuring assignment (LHS is an object/array literal),
            // validate that shorthand properties cannot use reserved words.
            if ($op->value === '=' && $left instanceof ObjectExpression) {
                self::validateAssignmentTargetObjectShorthand($left);
            }
            return new AssignmentExpression($left->location, $op->value, $left, $right, $leftParenthesized);
        }

        // Arrow function: (params) => body OR ident => body
        if ($this->check(TokenType::Arrow)) {
            // Per §15.3.1: no LineTerminator between ArrowParameters and `=>`.
            if ($this->current()->lineTerminatorBefore) {
                throw new ParseError(
                    'Line terminator not allowed before arrow',
                    $this->current(),
                );
            }
            return $this->parseArrowFunction($left, false);
        }

        // Per §13.2.5.1 / §13.15.1: a CoverInitializedName (`{ a = 1 }`)
        // is only valid when the surrounding ObjectLiteral is refined into
        // an AssignmentPattern (i.e. is the LHS of `=`). Otherwise it is a
        // SyntaxError. We've already returned above if `=` followed.
        // The for-header LHS is parsed eagerly and may be refined into an
        // AssignmentPattern by the trailing `in`/`of` keyword; in that
        // context, the parser sets $allowCoverInit so we skip the check.
        if (!$this->allowCoverInit) {
            self::rejectCoverInitializedName($left);
        }

        return $left;
    }

    /**
     * Walk an expression looking for a Property with shorthand=true and an
     * AssignmentExpression as value (the cover-initialized-name form).
     * Throws SyntaxError if found. Also runs the deferred __proto__
     * duplicate check on ObjectExpressions encountered.
     */
    private static function rejectCoverInitializedName(?Node $node): void
    {
        if ($node === null) {
            return;
        }
        if ($node instanceof ObjectExpression) {
            self::validateObjectLiteralProtoDuplicate($node);
            foreach ($node->properties as $prop) {
                if (
                    $prop instanceof Property
                    && $prop->shorthand
                    && $prop->value instanceof AssignmentExpression
                    && $prop->value->operator === '='
                    && $prop->value->left === $prop->key
                ) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        'Invalid shorthand property initializer outside destructuring',
                    );
                }
                if ($prop instanceof Property) {
                    self::rejectCoverInitializedName($prop->value);
                }
            }
        }
    }

    private function parseConditionalExpression(): Node
    {
        $expr = $this->parseBinaryExpression(Precedence::NONE);

        if ($this->eat(TokenType::Question)) {
            // Per spec: consequent is AssignmentExpression[+In], always allows `in`.
            $savedNoIn = $this->noIn;
            $this->noIn = false;
            $consequent = $this->parseAssignmentExpression();
            $this->noIn = $savedNoIn;
            $this->expect(TokenType::Colon);
            // Alternate is AssignmentExpression[?In], inherits enclosing context.
            $alternate = $this->parseAssignmentExpression();
            return new ConditionalExpression($expr->location, $expr, $consequent, $alternate);
        }

        return $expr;
    }

    private function parseBinaryExpression(int $minPrec): Node
    {
        // Handle #name in obj (private field brand check). Per §13.10 the
        // PrivateIdentifier-in production lives at the RelationalExpression
        // level. When we recurse for a higher-precedence operand (minPrec >=
        // RELATIONAL), PrivateIdentifier is not allowed as the operand start,
        // so `#a in #b in c` rejects the inner nested form.
        if (
            $this->check(TokenType::PrivateIdentifier)
            && $this->peekIs(TokenType::In)
            && $minPrec < Precedence::RELATIONAL
        ) {
            $token = $this->advance();
            $left = new PrivateIdentifier($token->location, $token->value);
        } else {
            $left = $this->parseUnaryExpression();
        }

        while (true) {
            $token = $this->current();
            $prec = Precedence::infixFor($token->type);

            if ($prec <= $minPrec) {
                break;
            }

            // Skip 'in' in for-loop init (NoIn production)
            if ($token->type === TokenType::In && $this->noIn) {
                break;
            }

            $this->advance();
            $op = $token->value;

            // Right-associative: **
            $nextPrec = $token->type === TokenType::Exponent ? $prec - 1 : $prec;

            // Per §13.6: ExponentiationExpression requires an UpdateExpression
            // on the LHS. A UnaryExpression with a prefix operator
            // (`!`, `~`, `+`, `-`, `typeof`, `void`, `delete`, `await`)
            // cannot be the LHS of `**` — it must be parenthesized.
            if (
                $token->type === TokenType::Exponent
                && (
                    $left instanceof UnaryExpression
                    || $left instanceof AwaitExpression
                )
                && !$this->parenthesized->contains($left)
            ) {
                throw new ParseError(
                    'Unary operator used immediately before exponentiation expression. '
                    . 'Parentheses must be used to disambiguate operator precedence',
                    $token,
                );
            }

            // Logical operators use LogicalExpression
            if (
                $token->type === TokenType::LogicalAnd
                || $token->type === TokenType::LogicalOr
                || $token->type === TokenType::NullishCoalescing
            ) {
                // Per §13.13.1 coalesce-expression cannot chain with && or ||
                // without explicit grouping. Reject `a ?? b && c`, `a && b ?? c`,
                // and equivalents with || unless one side is parenthesized.
                if ($token->type === TokenType::NullishCoalescing) {
                    if (
                        $left instanceof LogicalExpression
                        && ($left->operator === '&&' || $left->operator === '||')
                        && !$this->parenthesized->contains($left)
                    ) {
                        throw new ParseError(
                            "?? cannot be chained with && or || without grouping",
                            $token,
                        );
                    }
                }
                if ($token->type === TokenType::LogicalAnd || $token->type === TokenType::LogicalOr) {
                    if (
                        $left instanceof LogicalExpression
                        && $left->operator === '??'
                        && !$this->parenthesized->contains($left)
                    ) {
                        throw new ParseError(
                            "&& or || cannot be chained with ?? without grouping",
                            $token,
                        );
                    }
                }
                $right = $this->parseBinaryExpression($nextPrec);
                // Also reject when the right side starts with the incompatible
                // logical operator (after binding the right subexpression).
                if ($token->type === TokenType::NullishCoalescing) {
                    if (
                        $right instanceof LogicalExpression
                        && ($right->operator === '&&' || $right->operator === '||')
                        && !$this->parenthesized->contains($right)
                    ) {
                        throw new ParseError(
                            "?? cannot be chained with && or || without grouping",
                            $token,
                        );
                    }
                }
                if ($token->type === TokenType::LogicalAnd || $token->type === TokenType::LogicalOr) {
                    if (
                        $right instanceof LogicalExpression
                        && $right->operator === '??'
                        && !$this->parenthesized->contains($right)
                    ) {
                        throw new ParseError(
                            "&& or || cannot be chained with ?? without grouping",
                            $token,
                        );
                    }
                }
                $left = new LogicalExpression($left->location, $op, $left, $right);
                continue;
            }

            // Member access, call, optional chaining handled separately
            if ($token->type === TokenType::Dot) {
                $property = $this->parseIdentifierOrKeyword();
                $left = new MemberExpression($left->location, $left, $property, false, false);
                continue;
            }

            if ($token->type === TokenType::OptionalChaining) {
                if ($this->check(TokenType::LeftParen)) {
                    $this->advance();
                    $args = $this->parseArguments();
                    $left = new CallExpression($left->location, $left, $args, true);
                } elseif ($this->check(TokenType::LeftBracket)) {
                    $this->advance();
                    $property = $this->parseExpression();
                    $this->expect(TokenType::RightBracket);
                    $left = new MemberExpression($left->location, $left, $property, true, true);
                } else {
                    $property = $this->parseIdentifierOrKeyword();
                    $left = new MemberExpression($left->location, $left, $property, false, true);
                }
                continue;
            }

            if ($token->type === TokenType::LeftBracket) {
                $property = $this->parseExpression();
                $this->expect(TokenType::RightBracket);
                $left = new MemberExpression($left->location, $left, $property, true, false);
                continue;
            }

            if ($token->type === TokenType::LeftParen) {
                $args = $this->parseArguments();
                $left = new CallExpression($left->location, $left, $args, false);
                continue;
            }

            $right = $this->parseBinaryExpression($nextPrec);
            // Per §13.10, the right-hand side of `PrivateIdentifier in X`
            // must be a ShiftExpression. parseBinaryExpression accepts
            // ArrowFunctions through parsePrimary's cover-grammar handling,
            // so explicitly reject them here.
            if (
                $left instanceof PrivateIdentifier
                && $token->type === TokenType::In
                && $right instanceof ArrowFunction
            ) {
                throw new ParseError(
                    "Invalid right-hand side of 'in': arrow function not allowed",
                    $token,
                );
            }
            $left = new BinaryExpression($left->location, $op, $left, $right);
        }

        // Postfix ++ and --
        if (
            ($this->check(TokenType::PlusPlus) || $this->check(TokenType::MinusMinus))
            && !$this->current()->lineTerminatorBefore
        ) {
            $op = $this->advance();
            if (!self::isSimpleAssignmentTarget($left)) {
                throw new ParseError(
                    "Invalid left-hand side expression in postfix operation",
                    $op,
                );
            }
            // Per §13.4.1.1: in strict mode, `eval` and `arguments` cannot
            // be the target of UpdateExpression (their AssignmentTargetType
            // is "strict").
            if (
                $this->strictMode
                && $left instanceof Identifier
                && ($left->name === 'eval' || $left->name === 'arguments')
            ) {
                throw new ParseError(
                    "Invalid left-hand side '{$left->name}' in postfix operation in strict mode",
                    $op,
                );
            }
            if ($left instanceof Identifier && $left->name === '[[NewTarget]]') {
                throw new ParseError(
                    'Invalid left-hand side in postfix operation: MetaProperty',
                    $op,
                );
            }
            $left = new UpdateExpression($left->location, $op->value, $left, false);
        }

        // Tagged template literal
        if ($this->check(TokenType::NoSubstitutionTemplate) || $this->check(TokenType::TemplateHead)) {
            // Per §13.3.7.1: tagged templates cannot follow an optional chain.
            if (self::memberExpressionIsOptional($left)) {
                throw new ParseError(
                    'Tagged template literal cannot follow an optional chain',
                    $this->current(),
                );
            }
            $quasi = $this->parseTemplateLiteral(true);
            $left = new TaggedTemplate($left->location, $left, $quasi);
        }

        return $left;
    }

    private function parseUnaryExpression(): Node
    {
        $token = $this->current();

        // Prefix update: ++ --
        if ($token->type === TokenType::PlusPlus || $token->type === TokenType::MinusMinus) {
            $this->advance();
            $argument = $this->parseUnaryExpression();
            if (!self::isSimpleAssignmentTarget($argument)) {
                throw new ParseError(
                    "Invalid left-hand side expression in prefix operation",
                    $token,
                );
            }
            // Per §13.4.1.1: `eval`/`arguments` can't be UpdateExpression
            // targets in strict mode; `new.target` is never a valid target.
            if (
                $this->strictMode
                && $argument instanceof Identifier
                && ($argument->name === 'eval' || $argument->name === 'arguments')
            ) {
                throw new ParseError(
                    "Invalid left-hand side '{$argument->name}' in prefix operation in strict mode",
                    $token,
                );
            }
            if ($argument instanceof Identifier && $argument->name === '[[NewTarget]]') {
                throw new ParseError(
                    'Invalid left-hand side in prefix operation: MetaProperty',
                    $token,
                );
            }
            return new UpdateExpression($token->location, $token->value, $argument, true);
        }

        // Unary operators: ! ~ + - typeof void delete
        if (
            $token->type === TokenType::Bang
            || $token->type === TokenType::Tilde
            || $token->type === TokenType::Plus
            || $token->type === TokenType::Minus
            || $token->type === TokenType::Typeof
            || $token->type === TokenType::Void
            || $token->type === TokenType::Delete
        ) {
            $this->advance();
            $argument = $this->parseUnaryExpression();
            if ($token->type === TokenType::Delete) {
                // Per spec §13.5.1.1 Early Errors: it is a SyntaxError if
                // the UnaryExpression following `delete` is a direct member
                // access of a PrivateIdentifier (covered or not).
                self::validateDeleteArgument($argument, $token);
                // Per §13.5.1.1: in strict mode, `delete` applied to a bare
                // IdentifierReference is a SyntaxError.
                if ($this->strictMode && $argument instanceof Identifier) {
                    throw new ParseError(
                        'Delete of an unqualified identifier in strict mode',
                        $token,
                    );
                }
            }
            return new UnaryExpression($token->location, $token->value, $argument, true);
        }

        // await (inside async functions or at the top level of a module per
        // top-level-await). In script top-level, `await` is an identifier.
        if (
            $token->type === TokenType::Await
            && ($this->inAsync || ($this->topLevel && $this->moduleMode))
        ) {
            // Per spec, the `await` keyword cannot be unicode-escaped.
            if ($token->rawValue === 'escaped') {
                throw new ParseError(
                    "Keyword 'await' must not contain escaped characters",
                    $token,
                );
            }
            $this->advance();
            $argument = $this->parseUnaryExpression();
            return new AwaitExpression($token->location, $argument);
        }

        // YieldExpression used to live here, but per spec it is an
        // AssignmentExpression — not a UnaryExpression — so recognize
        // it at parseAssignmentExpression instead.

        return $this->parsePostfixExpression();
    }

    /**
     * Parse postfix operations: member access, computed access, and call expressions.
     *
     * This sits between unary and primary expressions so that
     * `!obj.method()` is parsed as `!(obj.method())`, not `(!obj).method()`.
     */
    private function parsePostfixExpression(): Node
    {
        $expr = $this->parsePrimaryExpression();

        while (true) {
            if ($this->check(TokenType::Dot) && !$this->current()->lineTerminatorBefore) {
                $this->advance();
                $property = $this->parseIdentifierOrKeyword();
                $expr = new MemberExpression($expr->location, $expr, $property, false, false);
                continue;
            }
            if ($this->check(TokenType::LeftBracket)) {
                $this->advance();
                $property = $this->parseExpression();
                $this->expect(TokenType::RightBracket);
                $expr = new MemberExpression($expr->location, $expr, $property, true, false);
                continue;
            }
            if ($this->check(TokenType::LeftParen)) {
                $this->advance();
                $args = $this->parseArguments();
                $expr = new CallExpression($expr->location, $expr, $args, false);
                continue;
            }
            if ($this->check(TokenType::OptionalChaining)) {
                $this->advance();
                if ($this->check(TokenType::LeftParen)) {
                    $this->advance();
                    $args = $this->parseArguments();
                    $expr = new CallExpression($expr->location, $expr, $args, true);
                } elseif ($this->check(TokenType::LeftBracket)) {
                    $this->advance();
                    $property = $this->parseExpression();
                    $this->expect(TokenType::RightBracket);
                    $expr = new MemberExpression(
                        $expr->location,
                        $expr,
                        $property,
                        true,
                        true,
                    );
                } else {
                    $property = $this->parseIdentifierOrKeyword();
                    $expr = new MemberExpression(
                        $expr->location,
                        $expr,
                        $property,
                        false,
                        true,
                    );
                }
                continue;
            }
            // Tagged template literal in postfix position.
            if (
                $this->check(TokenType::NoSubstitutionTemplate)
                || $this->check(TokenType::TemplateHead)
            ) {
                if (self::memberExpressionIsOptional($expr)) {
                    throw new ParseError(
                        'Tagged template literal cannot follow an optional chain',
                        $this->current(),
                    );
                }
                $quasi = $this->parseTemplateLiteral(true);
                $expr = new TaggedTemplate($expr->location, $expr, $quasi);
                continue;
            }
            break;
        }

        // Postfix ++ and --
        if (
            ($this->check(TokenType::PlusPlus) || $this->check(TokenType::MinusMinus))
            && !$this->current()->lineTerminatorBefore
        ) {
            $op = $this->advance();
            if (!self::isSimpleAssignmentTarget($expr)) {
                throw new ParseError(
                    'Invalid left-hand side expression in postfix operation',
                    $op,
                );
            }
            // Per §13.4.1.1 strict-mode early errors.
            if (
                $this->strictMode
                && $expr instanceof Identifier
                && ($expr->name === 'eval' || $expr->name === 'arguments')
            ) {
                throw new ParseError(
                    "Invalid left-hand side '{$expr->name}' in postfix operation in strict mode",
                    $op,
                );
            }
            if ($expr instanceof Identifier && $expr->name === '[[NewTarget]]') {
                throw new ParseError(
                    'Invalid left-hand side in postfix operation: MetaProperty',
                    $op,
                );
            }
            $expr = new UpdateExpression($expr->location, $op->value, $expr, false);
        }

        return $expr;
    }

    private function parseYieldExpression(): YieldExpression
    {
        $yieldToken = $this->expect(TokenType::Yield);
        // Per spec, the `yield` keyword cannot be unicode-escaped.
        if ($yieldToken->rawValue === 'escaped') {
            throw new ParseError(
                "Keyword 'yield' must not contain escaped characters",
                $yieldToken,
            );
        }
        $location = $yieldToken->location;
        // Per §15.5.4 / Annex grammar: no LineTerminator between `yield`
        // and `*` in YieldExpression.
        if ($this->check(TokenType::Star) && $this->current()->lineTerminatorBefore) {
            // Treat as `yield;` then `*expr;` — but `*` cannot start an
            // expression statement, so the parser will error downstream.
            return new YieldExpression($location, null, false);
        }
        $delegate = $this->eat(TokenType::Star);
        $argument = null;

        // For yield* (delegate), the AssignmentExpression is required and a line
        // terminator between * and the expression does NOT trigger ASI. For plain
        // yield, a line terminator before the next token means no argument (ASI).
        // Template middle/tail tokens (after ${...}) also terminate the yield
        // argument because the closing '}' is part of the template literal.
        if (
            !$this->check(TokenType::Semicolon)
            && !$this->check(TokenType::RightBrace)
            && !$this->check(TokenType::RightParen)
            && !$this->check(TokenType::RightBracket)
            && !$this->check(TokenType::Comma)
            && !$this->check(TokenType::Colon)
            && !$this->check(TokenType::TemplateMiddle)
            && !$this->check(TokenType::TemplateTail)
            && !$this->isAtEnd()
            && ($delegate || !$this->current()->lineTerminatorBefore)
        ) {
            $argument = $this->parseAssignmentExpression();
        }

        return new YieldExpression($location, $argument, $delegate);
    }

    private function parsePrimaryExpression(): Node
    {
        $token = $this->current();

        if ($token->type === TokenType::Slash || $token->type === TokenType::SlashEqual) {
            $this->rescanSlashAsRegExp();
            $token = $this->current();
        }

        // Decorators on class expressions: @dec class { ... }.
        if ($token->type === TokenType::At) {
            $decorators = $this->parseDecoratorList();
            if ($this->check(TokenType::Class_)) {
                return $this->parseClassExpression($decorators);
            }
            throw new ParseError('Expected class expression after decorator list', $this->current());
        }

        return match ($token->type) {
            TokenType::Number => $this->parseNumericLiteral(),
            TokenType::String => $this->parseStringLiteral(),
            TokenType::True, TokenType::False => $this->parseBooleanLiteral(),
            TokenType::Null => $this->parseNullLiteral(),
            TokenType::Await => $this->parseAwaitAsIdentifier($token),
            TokenType::Yield => $this->parseYieldAsIdentifier($token),
            TokenType::Identifier, TokenType::Let,
            TokenType::Static_, TokenType::Of => $this->parseIdentifierExpression(),
            TokenType::This => $this->parseThisExpression(),
            TokenType::LeftParen => $this->parseParenthesizedOrArrow(),
            TokenType::LeftBracket => $this->parseArrayExpression(),
            TokenType::LeftBrace => $this->parseObjectExpression(),
            TokenType::Function_ => $this->parseFunctionExpression(),
            TokenType::Class_ => $this->parseClassExpression(),
            TokenType::New => $this->parseNewExpression(),
            TokenType::NoSubstitutionTemplate, TokenType::TemplateHead => $this->parseTemplateLiteral(),
            TokenType::RegExp => $this->parseRegExpLiteral(),
            TokenType::Ellipsis => throw new ParseError('Unexpected ...', $token),
            TokenType::Async => $this->parseAsyncExpression(),
            TokenType::Super => $this->parseSuperExpression(),
            TokenType::Import => $this->parseImportExpression(),
            default => throw new ParseError('Unexpected token', $token),
        };
    }

    private function parseNumericLiteral(): Literal
    {
        $token = $this->advance();
        $raw = $token->value;
        // Per Annex B.1.1, NonOctalDecimalIntegerLiteral (e.g. `08`, `09`)
        // is forbidden in strict mode.
        if ($token->rawValue === 'nonoctaldecimal' && $this->strictMode) {
            throw new \PhpJs\Exceptions\SyntaxError(
                'Decimal integer literal with leading 0 is not allowed in strict mode',
            );
        }

        if (str_ends_with($raw, 'n')) {
            // BigInt literal: normalize non-decimal bases (0xN, 0oN, 0bN) to
            // their decimal digit-string form so consumers that key off the
            // value (e.g. property name evaluation) see the canonical BigInt
            // representation (e.g. 0xan → "10").
            $body = substr($raw, 0, -1);
            if (str_starts_with($body, '0x') || str_starts_with($body, '0X')) {
                $value = self::nonDecimalDigitsToBigIntString(substr($body, 2), 16);
            } elseif (str_starts_with($body, '0o') || str_starts_with($body, '0O')) {
                $value = self::nonDecimalDigitsToBigIntString(substr($body, 2), 8);
            } elseif (str_starts_with($body, '0b') || str_starts_with($body, '0B')) {
                $value = self::nonDecimalDigitsToBigIntString(substr($body, 2), 2);
            } else {
                $value = $body;
            }
            return new Literal($token->location, $value, '__BIGINT__' . $raw);
        } elseif (str_starts_with($raw, '0x') || str_starts_with($raw, '0X')) {
            $value = hexdec($raw);
        } elseif (str_starts_with($raw, '0o') || str_starts_with($raw, '0O')) {
            $value = octdec(substr($raw, 2));
        } elseif (str_starts_with($raw, '0lo')) {
            // Annex B legacy octal integer literal. Forbidden in strict code.
            if ($this->strictMode) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    'Octal literals are not allowed in strict mode',
                    $token->location,
                );
            }
            $value = octdec(substr($raw, 3));
        } elseif (str_starts_with($raw, '0b') || str_starts_with($raw, '0B')) {
            $value = bindec(substr($raw, 2));
        } else {
            $value = (float) $raw;
            $isInt = $value == (int) $value
                && !str_contains($raw, '.')
                && !str_contains($raw, 'e')
                && !str_contains($raw, 'E')
                && abs($value) < PHP_INT_MAX;
            if ($isInt) {
                $value = (int) $raw;
            }
        }

        return new Literal($token->location, $value, $raw);
    }

    /**
     * Convert digit text in a non-decimal base (2, 8, 16) to an arbitrary-
     * precision decimal digit string. Used to normalize BigInt literals
     * like 0xa, 0b10, 0o7 to their canonical decimal form.
     */
    private static function nonDecimalDigitsToBigIntString(string $digits, int $base): string
    {
        $digits = strtolower($digits);
        $result = '0';
        $baseStr = (string) $base;
        $map = [
            '0' => '0', '1' => '1', '2' => '2', '3' => '3',
            '4' => '4', '5' => '5', '6' => '6', '7' => '7',
            '8' => '8', '9' => '9', 'a' => '10', 'b' => '11',
            'c' => '12', 'd' => '13', 'e' => '14', 'f' => '15',
        ];
        for ($i = 0; $i < strlen($digits); $i++) {
            $d = $digits[$i];
            if (!isset($map[$d])) {
                continue;
            }
            $result = bcmul($result, $baseStr);
            $result = bcadd($result, $map[$d]);
        }
        return $result;
    }

    private function parseRegExpLiteral(): Literal
    {
        $token = $this->advance();
        // Eagerly validate the regex pattern. Per §22.2.1, RegExp literals
        // must be Pattern-grammar valid at parse time.
        $literal = $token->value;
        if (preg_match('#^/(.*)/([a-zA-Z]*)$#s', $literal, $m) === 1) {
            $pattern = $m[1];
            $flags = $m[2];
            self::validateRegExpFlagsAtParseTime($flags, $token);
            \PhpJs\Runtime\Interpreter::validateRegExpModifierGroups($pattern);
            if (\PhpJs\Runtime\Interpreter::hasDuplicateNamedGroupsInSameAlternative($pattern)) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Invalid regular expression: /{$pattern}/: Duplicate capture group name",
                );
            }
            self::validateRegExpPatternAtParseTime($pattern, $flags);
        }
        // Use '__REGEXP__' prefix in raw to distinguish from string literals
        return new Literal($token->location, $token->value, '__REGEXP__' . $token->value);
    }

    /**
     * Per §22.2.1.5 RegularExpressionFlags. Each flag may appear at most
     * once and must be one of the recognized characters.
     */
    private static function validateRegExpFlagsAtParseTime(string $flags, \PhpJs\Lexer\Token $token): void
    {
        static $allowed = ['g', 'i', 'm', 's', 'u', 'v', 'y', 'd'];
        $seen = [];
        for ($i = 0, $n = strlen($flags); $i < $n; $i++) {
            $c = $flags[$i];
            if (!in_array($c, $allowed, true)) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Invalid regular expression flag '{$c}'",
                );
            }
            if (isset($seen[$c])) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Invalid regular expression flag '{$c}'",
                );
            }
            $seen[$c] = true;
        }
        if (isset($seen['u']) && isset($seen['v'])) {
            throw new \PhpJs\Exceptions\SyntaxError(
                'Invalid regular expression: cannot combine u and v flags',
            );
        }
    }

    /**
     * Lightweight ECMAScript regex pattern validator. Catches the most
     * common parse-time errors. Not a full Pattern parser.
     */
    private static function validateRegExpPatternAtParseTime(string $pattern, string $flags): void
    {
        $unicode = str_contains($flags, 'u') || str_contains($flags, 'v');
        $groupNames = [];
        $kRefs = [];
        $hasNamedGroup = self::collectRegExpGroupNamesAndKRefs($pattern, $groupNames, $kRefs);
        $captureCount = self::countCapturingGroupsInRegExp($pattern);
        // Pass 1: structural validation.
        $len = strlen($pattern);
        $i = 0;
        $inClass = false;
        $groupOpen = 0;
        $groupKindStack = [];
        $lastClosedGroupWasLookbehind = false;
        $lastClosedGroupWasLookahead = false;
        // Tracks whether the previous token can be quantified (an Atom).
        $prevAtom = false;
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === '\\') {
                if ($i + 1 >= $len) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: \\ at end of pattern",
                    );
                }
                $next = $pattern[$i + 1];
                if ($next === 'u') {
                    if ($i + 2 < $len && $pattern[$i + 2] === '{') {
                        $j = $i + 3;
                        $hex = '';
                        while ($j < $len && $pattern[$j] !== '}') {
                            if (!ctype_xdigit($pattern[$j])) {
                                if ($unicode) {
                                    throw new \PhpJs\Exceptions\SyntaxError(
                                        "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                                    );
                                }
                                break;
                            }
                            $hex .= $pattern[$j];
                            $j++;
                        }
                        if ($unicode && ($j >= $len || $pattern[$j] !== '}' || $hex === '')) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                            );
                        }
                        if ($unicode && $hex !== '' && hexdec($hex) > 0x10FFFF) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Code point out of range",
                            );
                        }
                        $i = $j + 1;
                        $prevAtom = true;
                        continue;
                    }
                    if ($unicode) {
                        if ($i + 5 >= $len) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                            );
                        }
                        for ($k = 2; $k < 6; $k++) {
                            if (!ctype_xdigit($pattern[$i + $k])) {
                                throw new \PhpJs\Exceptions\SyntaxError(
                                    "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                                );
                            }
                        }
                        $i += 6;
                        $prevAtom = true;
                        continue;
                    }
                }
                if (ctype_digit($next) && !$inClass) {
                    // Collect the full decimal escape (could be multi-digit).
                    $j = $i + 1;
                    $digits = '';
                    while ($j < $len && ctype_digit($pattern[$j])) {
                        $digits .= $pattern[$j];
                        $j++;
                    }
                    $num = (int) $digits;
                    if ($unicode) {
                        if ($digits === '0' && strlen($digits) === 1) {
                            // \0 is the NUL character (allowed).
                        } elseif ($digits[0] === '0') {
                            // Leading-zero octals are forbidden in u-mode.
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid escape",
                            );
                        } elseif ($num > $captureCount) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid back reference",
                            );
                        }
                    }
                    $i = $j;
                    $prevAtom = true;
                    continue;
                }
                if ($next === 'k' && !$inClass) {
                    // \k<Name> reference. With named groups present, must
                    // reference an existing group.
                    if ($i + 2 >= $len || $pattern[$i + 2] !== '<') {
                        if ($unicode || $hasNamedGroup) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid named back reference",
                            );
                        }
                    } else {
                        $j = $i + 3;
                        $name = '';
                        while ($j < $len && $pattern[$j] !== '>') {
                            $name .= $pattern[$j];
                            $j++;
                        }
                        if ($j >= $len || $name === '' || !self::isValidGroupName($name)) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid named back reference",
                            );
                        }
                        if (($unicode || $hasNamedGroup) && !isset($groupNames[$name])) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid named capture referenced",
                            );
                        }
                        $i = $j + 1;
                        $prevAtom = true;
                        continue;
                    }
                }
                // u-flag IdentityEscape: only SyntaxCharacter / / are allowed
                if ($unicode && !$inClass) {
                    static $allowedIdEscape = ['^', '$', '.', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|', '/', '\\',
                        'd', 'D', 's', 'S', 'w', 'W', 'b', 'B', 'f', 'n', 'r', 't', 'v', '0', 'c', 'x', 'u', 'p', 'P', 'k'];
                    if (!in_array($next, $allowedIdEscape, true) && !ctype_digit($next)) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid escape",
                        );
                    }
                }
                // \c must be followed by A-Za-z in u/v mode. In non-u, \c
                // followed by a non-letter was historically accepted but the
                // Annex B extension that allowed it is precluded under u.
                if ($next === 'c' && $unicode) {
                    $after = $i + 2 < $len ? $pattern[$i + 2] : '';
                    if (!($after >= 'A' && $after <= 'Z') && !($after >= 'a' && $after <= 'z')) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: \\c must be followed by a letter in unicode mode",
                        );
                    }
                }
                // \p{Property} / \P{Property} consume the trailing {…}.
                if (($next === 'p' || $next === 'P') && $i + 2 < $len && $pattern[$i + 2] === '{') {
                    $j = $i + 3;
                    while ($j < $len && $pattern[$j] !== '}') {
                        $j++;
                    }
                    if ($j < $len) {
                        $i = $j + 1;
                        $prevAtom = true;
                        continue;
                    }
                }
                $i += 2;
                $prevAtom = true;
                continue;
            }
            if (!$inClass && $c === '[') {
                if ($unicode) {
                    $endPos = self::validateCharClassUnicode($pattern, $i);
                    $i = $endPos;
                    $prevAtom = true;
                    continue;
                }
                $inClass = true;
                $i++;
                continue;
            }
            if ($inClass && $c === ']') {
                $inClass = false;
                $i++;
                $prevAtom = true;
                continue;
            }
            if (!$inClass && $c === '(' && $i + 1 < $len && $pattern[$i + 1] === '?') {
                // Validate construct after `(?`.
                $third = $i + 2 < $len ? $pattern[$i + 2] : '';
                if ($third === ':') {
                    $i += 3;
                    $groupOpen++;
                    $groupKindStack[] = 'normal';
                    continue;
                }
                if ($third === '=' || $third === '!') {
                    // Lookahead: in u-mode, cannot be quantified.
                    $i += 3;
                    $groupOpen++;
                    $groupKindStack[] = 'lookahead';
                    continue;
                }
                if ($third === '<') {
                    $fourth = $i + 3 < $len ? $pattern[$i + 3] : '';
                    if ($fourth === '=' || $fourth === '!') {
                        // Lookbehind.
                        $i += 4;
                        $groupOpen++;
                        $groupKindStack[] = 'lookbehind';
                        continue;
                    }
                    // Named group: scan name to '>'.
                    $j = $i + 3;
                    $name = '';
                    while ($j < $len && $pattern[$j] !== '>') {
                        $name .= $pattern[$j];
                        $j++;
                    }
                    if ($j >= $len || $name === '' || !self::isValidGroupName($name)) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid named capture group",
                        );
                    }
                    $i = $j + 1;
                    $groupOpen++;
                    $groupKindStack[] = 'normal';
                    continue;
                }
                // No other `(?X` constructs are valid (modifier groups are
                // handled by validateRegExpModifierGroups already).
                if (!in_array($third, ['i', 'm', 's', '-'], true)) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Invalid group",
                    );
                }
                // Modifier group already validated; advance past the
                // entire `(?…:` and treat as a normal group.
                $j = $i + 2;
                while ($j < $len && $pattern[$j] !== ':') {
                    $j++;
                }
                $i = $j + 1;
                $groupOpen++;
                $groupKindStack[] = 'normal';
                continue;
            }
            if (!$inClass && $c === '(') {
                $i++;
                $groupOpen++;
                $groupKindStack[] = 'normal';
                continue;
            }
            if (!$inClass && $c === ')') {
                if ($groupOpen === 0) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Unmatched ')'",
                    );
                }
                $groupOpen--;
                $closedKind = array_pop($groupKindStack) ?? 'normal';
                $lastClosedGroupWasLookbehind = ($closedKind === 'lookbehind');
                $lastClosedGroupWasLookahead = ($closedKind === 'lookahead');
                $i++;
                $prevAtom = true;
                continue;
            }
            if (!$inClass && ($c === '*' || $c === '+' || $c === '?')) {
                if (!$prevAtom) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Nothing to repeat",
                    );
                }
                if ($lastClosedGroupWasLookbehind) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Lookbehind cannot be quantified",
                    );
                }
                if ($lastClosedGroupWasLookahead && $unicode) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Lookahead cannot be quantified in unicode mode",
                    );
                }
                $lastClosedGroupWasLookbehind = false;
                $lastClosedGroupWasLookahead = false;
                $prevAtom = false;
                $i++;
                // Optional lazy modifier `?` after a quantifier (e.g. *?, +?, ??).
                if ($i < $len && $pattern[$i] === '?') {
                    $i++;
                }
                continue;
            }
            if (!$inClass && $c === '{') {
                // Validate {n,m} braced quantifier ranges per §22.2.1.5
                $j = $i + 1;
                $first = '';
                while ($j < $len && ctype_digit($pattern[$j])) {
                    $first .= $pattern[$j];
                    $j++;
                }
                if ($first !== '' && $j < $len && ($pattern[$j] === ',' || $pattern[$j] === '}')) {
                    $second = '';
                    $hasComma = false;
                    if ($pattern[$j] === ',') {
                        $hasComma = true;
                        $j++;
                        while ($j < $len && ctype_digit($pattern[$j])) {
                            $second .= $pattern[$j];
                            $j++;
                        }
                    }
                    if ($j < $len && $pattern[$j] === '}') {
                        if (!$prevAtom) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Nothing to repeat",
                            );
                        }
                        if ($lastClosedGroupWasLookbehind) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Lookbehind cannot be quantified",
                            );
                        }
                        if ($lastClosedGroupWasLookahead && $unicode) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Lookahead cannot be quantified in unicode mode",
                            );
                        }
                        if ($hasComma && $second !== '' && (int) $first > (int) $second) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: numbers out of order in {n,m} quantifier",
                            );
                        }
                        $i = $j + 1;
                        // Optional lazy modifier after {n,m}.
                        if ($i < $len && $pattern[$i] === '?') {
                            $i++;
                        }
                        $prevAtom = false;
                        $lastClosedGroupWasLookbehind = false;
                        $lastClosedGroupWasLookahead = false;
                        continue;
                    }
                }
                if ($unicode) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Lone quantifier brackets",
                    );
                }
            }
            if (!$inClass && $c === '|') {
                $prevAtom = false;
                $i++;
                continue;
            }
            $prevAtom = !$inClass;
            $i++;
        }
        if ($inClass) {
            throw new \PhpJs\Exceptions\SyntaxError(
                "Invalid regular expression: /{$pattern}/: Unterminated character class",
            );
        }
        if ($groupOpen !== 0) {
            throw new \PhpJs\Exceptions\SyntaxError(
                "Invalid regular expression: /{$pattern}/: Unmatched '('",
            );
        }
    }

    /**
     * Collect named-group declarations and \k<Name> references in one pass.
     * Returns true if at least one named group is declared.
     *
     * @param array<string,bool> $names
     * @param array<int,string> $kRefs
     */
    private static function collectRegExpGroupNamesAndKRefs(string $pattern, array &$names, array &$kRefs): bool
    {
        $len = strlen($pattern);
        $i = 0;
        $inClass = false;
        $hasNamed = false;
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === '\\') {
                if ($i + 1 < $len && $pattern[$i + 1] === 'k' && !$inClass) {
                    if ($i + 2 < $len && $pattern[$i + 2] === '<') {
                        $j = $i + 3;
                        $n = '';
                        while ($j < $len && $pattern[$j] !== '>') {
                            $n .= $pattern[$j];
                            $j++;
                        }
                        if ($j < $len) {
                            $kRefs[] = $n;
                            $i = $j + 1;
                            continue;
                        }
                    }
                }
                $i += 2;
                continue;
            }
            if (!$inClass && $c === '[') {
                $inClass = true;
                $i++;
                continue;
            }
            if ($inClass && $c === ']') {
                $inClass = false;
                $i++;
                continue;
            }
            if (
                !$inClass
                && $c === '('
                && $i + 3 < $len
                && $pattern[$i + 1] === '?'
                && $pattern[$i + 2] === '<'
            ) {
                $third = $pattern[$i + 3];
                if ($third !== '=' && $third !== '!') {
                    $j = $i + 3;
                    $n = '';
                    while ($j < $len && $pattern[$j] !== '>') {
                        $n .= $pattern[$j];
                        $j++;
                    }
                    if ($j < $len && $n !== '') {
                        $names[$n] = true;
                        $hasNamed = true;
                        $i = $j + 1;
                        continue;
                    }
                }
            }
            $i++;
        }
        return $hasNamed;
    }

    /**
     * Count actual capturing groups in a regex pattern: `(...)` and
     * `(?<name>...)` count; `(?:...)`, `(?=...)`, `(?!...)`, `(?<=...)`,
     * `(?<!...)`, and modifier groups `(?ims-ims:...)` do not.
     */
    private static function countCapturingGroupsInRegExp(string $pattern): int
    {
        $len = strlen($pattern);
        $i = 0;
        $inClass = false;
        $count = 0;
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === '\\') {
                $i += 2;
                continue;
            }
            if (!$inClass && $c === '[') {
                $inClass = true;
                $i++;
                continue;
            }
            if ($inClass && $c === ']') {
                $inClass = false;
                $i++;
                continue;
            }
            if (!$inClass && $c === '(') {
                if ($i + 1 < $len && $pattern[$i + 1] === '?') {
                    $third = $i + 2 < $len ? $pattern[$i + 2] : '';
                    if ($third === '<' && $i + 3 < $len) {
                        $fourth = $pattern[$i + 3];
                        if ($fourth !== '=' && $fourth !== '!') {
                            // Named capturing group.
                            $count++;
                        }
                    }
                    // Other (?...) forms are non-capturing.
                } else {
                    // Plain capturing group.
                    $count++;
                }
            }
            $i++;
        }
        return $count;
    }

    /**
     * Validate a character class under u-flag semantics. The u flag forbids
     * unescaped `-` adjacent to a CharacterClassEscape (`\d`, `\w`, `\s`,
     * etc.). Returns the position just past the closing `]`.
     */
    private static function validateCharClassUnicode(string $pattern, int $start): int
    {
        $len = strlen($pattern);
        $i = $start + 1;
        if ($i < $len && $pattern[$i] === '^') {
            $i++;
        }
        // Each "atom" inside the class is either a single char, an escape,
        // or a class-escape. We validate that `-` is not adjacent to a
        // class-escape (e.g. `\d-a` or `\d-\w` is invalid in u-mode).
        $prevWasClassEscape = false;
        $prevValue = null;
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === ']') {
                return $i + 1;
            }
            if ($c === '\\') {
                if ($i + 1 >= $len) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: \\ at end of pattern",
                    );
                }
                $next = $pattern[$i + 1];
                if (in_array($next, ['d', 'D', 's', 'S', 'w', 'W', 'p', 'P'], true)) {
                    if ($i + 2 < $len && $pattern[$i + 2] === '-' && $i + 3 < $len && $pattern[$i + 3] !== ']') {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid character class",
                        );
                    }
                    // Skip past possible \p{Property}.
                    if ($next === 'p' || $next === 'P') {
                        if ($i + 2 < $len && $pattern[$i + 2] === '{') {
                            $j = $i + 3;
                            while ($j < $len && $pattern[$j] !== '}') {
                                $j++;
                            }
                            if ($j >= $len) {
                                throw new \PhpJs\Exceptions\SyntaxError(
                                    "Invalid regular expression: /{$pattern}/: Invalid Unicode property",
                                );
                            }
                            $i = $j + 1;
                            $prevWasClassEscape = true;
                            continue;
                        }
                    }
                    $i += 2;
                    $prevWasClassEscape = true;
                    continue;
                }
                // Unicode escape inside class.
                if ($next === 'u') {
                    if ($i + 2 < $len && $pattern[$i + 2] === '{') {
                        $j = $i + 3;
                        $hex = '';
                        while ($j < $len && $pattern[$j] !== '}') {
                            if (!ctype_xdigit($pattern[$j])) {
                                throw new \PhpJs\Exceptions\SyntaxError(
                                    "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                                );
                            }
                            $hex .= $pattern[$j];
                            $j++;
                        }
                        if ($j >= $len || $hex === '' || hexdec($hex) > 0x10FFFF) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                            );
                        }
                        $i = $j + 1;
                        $prevWasClassEscape = false;
                        continue;
                    }
                    if ($i + 5 >= $len) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                        );
                    }
                    for ($k = 2; $k < 6; $k++) {
                        if (!ctype_xdigit($pattern[$i + $k])) {
                            throw new \PhpJs\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                            );
                        }
                    }
                    $i += 6;
                    $prevWasClassEscape = false;
                    continue;
                }
                // Identity escape: only specific chars allowed in u-mode.
                static $allowedClassIdEscape = ['^', '$', '.', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|', '/', '\\', '-',
                    'b', 'B', 'f', 'n', 'r', 't', 'v', '0', 'c', 'x', 'k'];
                if (!in_array($next, $allowedClassIdEscape, true)) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Invalid escape",
                    );
                }
                $i += 2;
                $prevWasClassEscape = false;
                continue;
            }
            if ($c === '-') {
                // Check if this `-` introduces a range whose right side is a
                // class-escape. Look ahead.
                $next = $i + 1 < $len ? $pattern[$i + 1] : '';
                if ($prevWasClassEscape && $next !== ']') {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Invalid character class range",
                    );
                }
                if ($next === '\\') {
                    $nn = $i + 2 < $len ? $pattern[$i + 2] : '';
                    if (in_array($nn, ['d', 'D', 's', 'S', 'w', 'W', 'p', 'P'], true)) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid character class range",
                        );
                    }
                }
                $prevWasClassEscape = false;
                $i++;
                continue;
            }
            $prevWasClassEscape = false;
            $i++;
        }
        throw new \PhpJs\Exceptions\SyntaxError(
            "Invalid regular expression: /{$pattern}/: Unterminated character class",
        );
    }

    private static function isValidGroupName(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        $first = $name[0];
        if (!ctype_alpha($first) && $first !== '_' && $first !== '$') {
            return false;
        }
        for ($i = 1, $n = strlen($name); $i < $n; $i++) {
            $c = $name[$i];
            if (!ctype_alnum($c) && $c !== '_' && $c !== '$') {
                return false;
            }
        }
        return true;
    }

    private function parseStringLiteral(): Literal
    {
        $token = $this->advance();
        // rawValue 'verbatim' means no escape sequences were used in the string literal.
        // This is needed to correctly detect "use strict" directives (escape-containing
        // strings like 'use\u0020strict' must NOT be treated as directives per spec).
        $verbatim = ($token->rawValue === 'verbatim');
        // Legacy octal escapes (\1-\7, \0 followed by digit, \8, \9) are a
        // SyntaxError in strict mode per §12.9.4.1. Reject now if strict mode
        // is already active. Otherwise stash the literal for retroactive
        // rejection when a directive-prologue promotes the function to strict.
        if ($token->rawValue === 'legacy-octal' && $this->strictMode) {
            throw new ParseError(
                'Octal escape sequences are not allowed in strict mode',
                $token,
            );
        }
        $literal = new Literal($token->location, $token->value, $token->value, verbatim: $verbatim);
        if ($token->rawValue === 'legacy-octal') {
            $this->stringsWithLegacyOctal->attach($literal);
        }
        return $literal;
    }

    private function parseBooleanLiteral(): Literal
    {
        $token = $this->current();
        // Per §12.7.1: keywords cannot contain Unicode escape sequences.
        if ($token->rawValue === 'escaped') {
            throw new ParseError(
                "Keyword '{$token->value}' must not contain escaped characters",
                $token,
            );
        }
        $this->advance();
        return new Literal($token->location, $token->type === TokenType::True, $token->value);
    }

    private function parseNullLiteral(): Literal
    {
        $token = $this->current();
        // Per §12.7.1: keywords cannot contain Unicode escape sequences.
        if ($token->rawValue === 'escaped') {
            throw new ParseError(
                "Keyword 'null' must not contain escaped characters",
                $token,
            );
        }
        $this->advance();
        return new Literal($token->location, null, 'null');
    }

    private function parseIdentifier(): Identifier
    {
        $token = $this->current();
        // In sloppy mode, `let`, `static`, `of`, `yield`, and `async` can be
        // used as identifiers. In strict mode `yield` is reserved. In async
        // functions or at module top level, `await` is reserved.
        if (
            $token->type !== TokenType::Identifier
            && $token->type !== TokenType::Let
            && $token->type !== TokenType::Static_
            && $token->type !== TokenType::Of
            && $token->type !== TokenType::Yield
            && $token->type !== TokenType::Await
            && $token->type !== TokenType::Async
        ) {
            throw new ParseError('Expected identifier', $token);
        }
        if (
            $token->type === TokenType::Await
            && (
                $this->inAsync
                || ($this->topLevel && $this->moduleMode)
                || $this->inStaticBlock
            )
        ) {
            throw new ParseError(
                "Unexpected reserved word 'await' as binding identifier",
                $token,
            );
        }
        if ($token->type === TokenType::Yield && ($this->inGenerator || $this->strictMode)) {
            throw new ParseError(
                "Unexpected reserved word 'yield' as binding identifier",
                $token,
            );
        }
        // ReservedWords (including `enum`) are never valid binding
        // identifiers, even in sloppy mode. They are only legal as
        // IdentifierName in property keys / member access.
        if (
            $token->type === TokenType::Identifier
            && $token->value === 'enum'
        ) {
            throw new ParseError(
                "Unexpected reserved word 'enum' as binding identifier",
                $token,
            );
        }
        // In strict mode, `let` and `static` cannot be binding identifiers.
        if (
            $this->strictMode
            && ($token->type === TokenType::Let || $token->type === TokenType::Static_)
        ) {
            throw new ParseError(
                "Unexpected reserved word '{$token->value}' as binding identifier",
                $token,
            );
        }
        // In strict mode, FutureReservedWords (implements, interface, etc.)
        // cannot be binding identifiers.
        if (
            $this->strictMode
            && $token->type === TokenType::Identifier
            && self::isStrictModeFutureReserved($token->value)
        ) {
            throw new ParseError(
                "Unexpected reserved word '{$token->value}' as binding identifier in strict mode",
                $token,
            );
        }
        $this->advance();
        return new Identifier($token->location, $token->value);
    }

    /**
     * Parse `await` in IdentifierReference position. In async function
     * bodies and in module top-level code, `await` is a reserved keyword
     * and cannot be an identifier; using it there (e.g. `new await`) is a
     * SyntaxError. In script top-level code or non-async functions, it is
     * a normal identifier.
     */
    private function parseAwaitAsIdentifier(\PhpJs\Lexer\Token $token): Node
    {
        if (
            $this->inAsync
            || ($this->topLevel && $this->moduleMode)
            || $this->inStaticBlock
        ) {
            throw new ParseError(
                "Unexpected reserved word 'await'",
                $token,
            );
        }
        return $this->parseIdentifierExpression();
    }

    /**
     * Parse `yield` in IdentifierReference position. In generators it is
     * always a keyword (and handled earlier as YieldExpression). In strict
     * mode outside generators, it is a reserved word.
     */
    private function parseYieldAsIdentifier(\PhpJs\Lexer\Token $token): Node
    {
        if ($this->strictMode || $this->inGenerator) {
            throw new ParseError(
                "Unexpected reserved word 'yield'",
                $token,
            );
        }
        return $this->parseIdentifierExpression();
    }

    private function parseIdentifierExpression(): Node
    {
        $token = $this->current();
        $this->advance();

        // async arrow: async (params) => body or async ident => body
        if ($token->value === 'async' && !$this->current()->lineTerminatorBefore) {
            if ($this->check(TokenType::Function_)) {
                return $this->parseAsyncFunctionExpression($token->location);
            }
            if ($this->check(TokenType::Identifier)) {
                $id = $this->parseIdentifier();
                if ($this->check(TokenType::Arrow)) {
                    // Source starts at 'async', not the identifier.
                    return $this->parseArrowFunctionFromParams($token->location, [$id], true);
                }
                // Back up: this was just an identifier "async" followed by another identifier
                // This case shouldn't normally happen in well-formed code; treat as "async" identifier
                $this->pos--;
            }
        }

        // FutureReservedWords cannot be IdentifierReference in strict mode.
        if ($this->strictMode && self::isStrictModeFutureReserved($token->value)) {
            throw new ParseError(
                "Unexpected reserved word '{$token->value}'",
                $token,
            );
        }
        // `enum` is a reserved word and cannot be IdentifierReference.
        if ($token->value === 'enum') {
            throw new ParseError(
                "Unexpected reserved word 'enum'",
                $token,
            );
        }
        // `let` is reserved as IdentifierReference in strict mode.
        if ($this->strictMode && $token->value === 'let') {
            throw new ParseError(
                "Unexpected reserved word 'let'",
                $token,
            );
        }

        return new Identifier($token->location, $token->value);
    }

    private static function isStrictModeFutureReserved(string $name): bool
    {
        return match ($name) {
            'implements', 'interface', 'package', 'private',
            'protected', 'public' => true,
            default => false,
        };
    }

    private function parseIdentifierOrKeyword(): Node
    {
        $token = $this->current();
        // Private identifier (#name) for private field/method access
        if ($token->type === TokenType::PrivateIdentifier) {
            $this->advance();
            return new PrivateIdentifier($token->location, $token->value);
        }
        // Accept identifiers and most keywords as property names
        if ($token->type === TokenType::Identifier || $token->type->isKeyword()) {
            $this->advance();
            return new Identifier($token->location, $token->value);
        }
        throw new ParseError('Expected identifier', $token);
    }

    private function parseThisExpression(): ThisExpression
    {
        $token = $this->advance();
        return new ThisExpression($token->location);
    }

    private function parseParenthesizedOrArrow(): Node
    {
        $location = $this->current()->location;

        // Try to determine if this is an arrow function or parenthesized expression.
        // Simple heuristic: save state, try parsing as arrow params.
        $savedPos = $this->pos;

        // () => ...
        if ($this->peek()->type === TokenType::RightParen) {
            $this->advance(); // (
            $this->advance(); // )
            if ($this->check(TokenType::Arrow)) {
                return $this->parseArrowFunctionFromParams($location, [], false);
            }
            // Empty parens without arrow: syntax error
            $this->pos = $savedPos;
        }

        // (...rest) => ...
        if ($this->peek()->type === TokenType::Ellipsis) {
            $this->advance(); // (
            $params = $this->parseArrowParams();
            if ($this->check(TokenType::Arrow)) {
                return $this->parseArrowFunctionFromParams($location, $params, false);
            }
            $this->pos = $savedPos;
        }

        // Try parsing as arrow params first (saves position in case it fails).
        // This handles trailing commas: (a,) => ... and (a, b,) => ...
        {
            $arrowSaved = $this->pos;
        try {
            $this->advance(); // consume (
            $params = $this->parseArrowParams();
            if ($this->check(TokenType::Arrow)) {
                return $this->parseArrowFunctionFromParams($location, $params, false);
            }
        } catch (\Throwable) {
            // Not arrow params, fall through to expression parsing.
        }
            $this->pos = $arrowSaved;
        }

        // Normal parenthesized expression (may still be arrow params)
        $this->advance(); // consume (
        $expr = $this->parseExpression();
        $this->expect(TokenType::RightParen);

        // Check for arrow
        if ($this->check(TokenType::Arrow)) {
            $params = $this->expressionToParams($expr);
            return $this->parseArrowFunctionFromParams($location, $params, false);
        }

        // Mark the expression as parenthesized so IsIdentifierRef returns false.
        $this->parenthesized->attach($expr);

        return $expr;
    }

    /**
     * Parse comma-separated parameters inside already-opened parens.
     * @return Node[]
     */
    private function parseArrowParams(): array
    {
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

    private function parseArrowFunction(Node $paramExpr, bool $async): ArrowFunction
    {
        $location = $paramExpr->location;
        $params = $this->expressionToParams($paramExpr);
        return $this->parseArrowFunctionFromParams($location, $params, $async);
    }

    /** @param Node[] $params */
    private function parseArrowFunctionFromParams(SourceLocation $location, array $params, bool $async): ArrowFunction
    {
        $startOffset = $location->offset;
        // Per §15.3.1: no LineTerminator between ArrowParameters and `=>`.
        if ($this->check(TokenType::Arrow) && $this->current()->lineTerminatorBefore) {
            throw new ParseError(
                'Line terminator not allowed before arrow',
                $this->current(),
            );
        }
        $this->expect(TokenType::Arrow);

        // Per §15.3.1 / §15.9.1: arrow params (sync or async) must be
        // unique and must not contain YieldExpression or AwaitExpression.
        self::validateUniqueParameterNames($params, $location);
        foreach ($params as $p) {
            if ($p !== null && self::containsYieldOrAwaitExpression($p)) {
                throw new ParseError(
                    'YieldExpression or AwaitExpression not permitted in arrow function parameters',
                    new \PhpJs\Lexer\Token(TokenType::Identifier, '', $location),
                );
            }
        }

        $prevAsync = $this->inAsync;
        $prevTopLevel = $this->topLevel;
        $prevStaticBlock = $this->inStaticBlock;
        $this->inAsync = $async;
        $this->topLevel = false;
        $this->inStaticBlock = false;

        if ($this->check(TokenType::LeftBrace)) {
            $body = $this->parseBlockStatement(true);
            $this->inAsync = $prevAsync;
            $this->topLevel = $prevTopLevel;
            $this->inStaticBlock = $prevStaticBlock;
            $this->validateStrictDirectiveWithNonSimpleParams($params, $body, $location);
            $this->validateParamLexBindingOverlap($params, $body);
            // Arrow functions inherit super binding from the enclosing
            // non-arrow scope. When not inside a method-like context,
            // reject super in params and body.
            if (!$this->inMethodLike) {
                self::validateNoSuperInPlainFunctionBody($params, $body);
            } else {
                // Even inside methods, a SuperCall is invalid in arrow params
                // (would run before super binding is usable per §8.2.3).
                foreach ($params as $p) {
                    if ($p !== null) {
                        self::walkForSuperCallOnly($p);
                    }
                }
            }
            return new ArrowFunction($location, $params, $body, false, $async, $this->extractSource($startOffset));
        }

        $body = $this->parseAssignmentExpression();
        $this->inAsync = $prevAsync;
        $this->topLevel = $prevTopLevel;
        $this->inStaticBlock = $prevStaticBlock;
        if (!$this->inMethodLike) {
            self::validateNoSuperInPlainFunctionBody($params, $body);
        } else {
            foreach ($params as $p) {
                if ($p !== null) {
                    self::walkForSuperCallOnly($p);
                }
            }
        }
        return new ArrowFunction($location, $params, $body, true, $async, $this->extractSource($startOffset));
    }

    /**
     * Convert a parsed expression back into parameter nodes.
     * @return Node[]
     */
    private function expressionToParams(Node $expr): array
    {
        if ($expr instanceof SequenceExpression) {
            return array_map(fn(Node $e) => $this->expressionToParam($e), $expr->expressions);
        }
        return [$this->expressionToParam($expr)];
    }

    private function expressionToParam(Node $expr): Node
    {
        if ($expr instanceof Identifier) {
            return $expr;
        }
        if ($expr instanceof AssignmentExpression && $expr->operator === '=') {
            return new AssignmentPattern(
                $expr->location,
                $this->expressionToParam($expr->left),
                $expr->right,
            );
        }
        if ($expr instanceof SpreadElement) {
            return new RestElement($expr->location, $this->expressionToParam($expr->argument));
        }
        if ($expr instanceof ArrayExpression) {
            $elements = array_map(
                fn(?Node $e) => $e === null ? null : $this->expressionToParam($e),
                $expr->elements,
            );
            // BindingRestElement must be the last element of an
            // ArrayBindingPattern, and it cannot have an initializer.
            $count = count($elements);
            for ($i = 0; $i < $count; $i++) {
                $el = $elements[$i];
                if ($el instanceof RestElement) {
                    if ($i !== $count - 1) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            'Rest element must be last element',
                        );
                    }
                    if ($el->argument instanceof AssignmentPattern) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            'Rest element may not have a default initializer',
                        );
                    }
                }
            }
            return new ArrayPattern($expr->location, $elements);
        }
        if ($expr instanceof ObjectExpression) {
            $props = [];
            foreach ($expr->properties as $prop) {
                if ($prop instanceof SpreadElement) {
                    $props[] = new RestElement($prop->location, $this->expressionToParam($prop->argument));
                } elseif ($prop instanceof Property) {
                    // Shorthand with a reserved-word identifier is a SyntaxError
                    // per spec: BindingIdentifier cannot be a reserved word even
                    // in sloppy mode (e.g. `({ class } = x)` must throw).
                    if (
                        $prop->shorthand
                        && $prop->key instanceof Identifier
                        && self::isReservedWordIdentifierName($prop->key->name)
                    ) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            "Unexpected reserved word '{$prop->key->name}' as shorthand property",
                            $prop->key->location,
                        );
                    }
                    $value = $this->expressionToParam($prop->value);
                    $props[] = new AssignmentProperty(
                        $prop->location,
                        $prop->key,
                        $value,
                        $prop->computed,
                        $prop->shorthand,
                    );
                }
            }
            return new ObjectPattern($expr->location, $props);
        }

        // CallExpression, MemberExpression, etc. are not valid arrow params.
        if (
            $expr instanceof CallExpression
            || $expr instanceof MemberExpression
            || $expr instanceof BinaryExpression
            || $expr instanceof UnaryExpression
            || $expr instanceof ConditionalExpression
            || $expr instanceof Literal
            || $expr instanceof TemplateLiteral
            || $expr instanceof TaggedTemplate
        ) {
            throw new \PhpJs\Exceptions\SyntaxError(
                'Invalid arrow function parameter list',
            );
        }
        // Fallback: just return the expression as-is (will be caught at runtime)
        return $expr;
    }

    private function parseArrayExpression(): ArrayExpression
    {
        $location = $this->expect(TokenType::LeftBracket)->location;
        $elements = [];
        $trailingCommaAfterRest = false;

        // Inside an array literal, `in` is always allowed as a binary operator
        // even if noIn is set by a surrounding for-header context.
        $savedNoIn = $this->noIn;
        $this->noIn = false;
        while (!$this->check(TokenType::RightBracket) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Comma)) {
                $elements[] = null; // elision
                $this->advance();
                continue;
            }

            $isRest = $this->check(TokenType::Ellipsis);
            if ($isRest) {
                $elements[] = $this->parseSpreadElement();
            } else {
                $elements[] = $this->parseAssignmentExpression();
            }

            if (!$this->check(TokenType::RightBracket)) {
                $this->expect(TokenType::Comma);
                if ($isRest && $this->check(TokenType::RightBracket)) {
                    $trailingCommaAfterRest = true;
                }
            }
        }
        $this->noIn = $savedNoIn;

        $this->expect(TokenType::RightBracket);
        $expr = new ArrayExpression($location, $elements);
        if ($trailingCommaAfterRest) {
            $this->arrayExpressionsWithTrailingCommaAfterRest->attach($expr);
        }
        return $expr;
    }

    private function parseObjectExpression(): ObjectExpression
    {
        $location = $this->expect(TokenType::LeftBrace)->location;
        $properties = [];

        // Inside an object literal, `in` is always allowed even if noIn is
        // set by a surrounding for-header context.
        $savedNoIn = $this->noIn;
        $this->noIn = false;
        while (!$this->check(TokenType::RightBrace) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Ellipsis)) {
                $properties[] = $this->parseSpreadElement();
            } else {
                $properties[] = $this->parsePropertyDefinition();
            }

            if (!$this->check(TokenType::RightBrace)) {
                $this->expect(TokenType::Comma);
            }
        }
        $this->noIn = $savedNoIn;

        $this->expect(TokenType::RightBrace);
        $obj = new ObjectExpression($location, $properties);
        // Per §13.2.5.1 Early Errors: an ObjectLiteral with two
        // PropertyDefinitions of the form `__proto__: AssignmentExpression`
        // is a SyntaxError. This restriction does not apply when the
        // object is reinterpreted as an ObjectAssignmentPattern (the
        // surrounding parser may set allowCoverInit to defer this check).
        if (!$this->allowCoverInit) {
            self::validateObjectLiteralProtoDuplicate($obj);
        }
        return $obj;
    }

    private static function validateObjectLiteralProtoDuplicate(ObjectExpression $obj): void
    {
        $protoCount = 0;
        foreach ($obj->properties as $prop) {
            if (!($prop instanceof Property)) {
                continue;
            }
            if ($prop->computed || $prop->shorthand || $prop->method) {
                continue;
            }
            if ($prop->kind !== 'init') {
                continue;
            }
            $name = null;
            if ($prop->key instanceof Identifier) {
                $name = $prop->key->name;
            } elseif ($prop->key instanceof Literal && is_string($prop->key->value)) {
                $name = $prop->key->value;
            }
            if ($name === '__proto__') {
                $protoCount++;
                if ($protoCount > 1) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Duplicate __proto__ fields are not allowed in object literals",
                    );
                }
            }
        }
    }

    private function parsePropertyDefinition(): Property
    {
        $location = $this->current()->location;
        $computed = false;
        $shorthand = false;
        $method = false;
        $kind = 'init';

        // Generator method: * method() {}
        $isGenerator = false;
        if ($this->eat(TokenType::Star)) {
            $isGenerator = true;
        }

        // Async method: async method() {}
        // Note: 'async' is tokenized as a keyword (TokenType::Async), not an Identifier.
        $isAsync = false;
        $notTrailingPunct = !$this->peekIs(TokenType::Colon)
            && !$this->peekIs(TokenType::Comma)
            && !$this->peekIs(TokenType::RightBrace);
        if (!$isGenerator && $this->check(TokenType::Async) && $notTrailingPunct) {
            $next = $this->peek();
            if (!$next->lineTerminatorBefore && $next->type !== TokenType::LeftParen) {
                // Per §12.7.2 / §15.8: the `async` contextual keyword cannot
                // contain Unicode escapes when introducing an async method,
                // async function, async arrow, etc.
                if ($this->current()->rawValue === 'escaped') {
                    throw new ParseError(
                        "Keyword 'async' must not contain escaped characters",
                        $this->current(),
                    );
                }
                $this->advance();
                $isAsync = true;
                if ($this->eat(TokenType::Star)) {
                    $isGenerator = true;
                }
            }
        }

        // get/set methods
        $isGetOrSet = !$isGenerator && !$isAsync
            && ($this->checkContextual('get') || $this->checkContextual('set'))
            && !$this->peekIs(TokenType::Colon)
            && !$this->peekIs(TokenType::LeftParen)
            && !$this->peekIs(TokenType::Comma)
            && !$this->peekIs(TokenType::RightBrace);

        if ($isGetOrSet && $this->checkContextual('get')) {
            // Per §13.2.5.1 / §12.7.2: contextual keywords `get` and `set`
            // must appear without Unicode escapes.
            if ($this->current()->rawValue === 'escaped') {
                throw new ParseError(
                    "Keyword 'get' must not contain escaped characters",
                    $this->current(),
                );
            }
            $this->advance();
            $kind = 'get';
            [$key, $computed] = $this->parsePropertyKey($computed);
        } elseif ($isGetOrSet && $this->checkContextual('set')) {
            if ($this->current()->rawValue === 'escaped') {
                throw new ParseError(
                    "Keyword 'set' must not contain escaped characters",
                    $this->current(),
                );
            }
            $this->advance();
            $kind = 'set';
            [$key, $computed] = $this->parsePropertyKey($computed);
        } else {
            [$key, $computed] = $this->parsePropertyKey($computed);
        }

        // Method shorthand: { foo() {} } or { *foo() {} } or { async foo() {} }
        if ($kind !== 'get' && $kind !== 'set' && $this->check(TokenType::LeftParen)) {
            $method = true;
            $methodStartOffset = $location->offset;
            $prevMethodLike = $this->inMethodLike;
            $this->inMethodLike = true;
            // Reset the surrounding function-kind flags before parsing params
            // so reserved-word checks on binding identifiers use the inner
            // method's context (e.g. `await` is a valid identifier inside a
            // non-async method's formal parameters even if the enclosing
            // static block forbids await).
            $prevGenerator = $this->inGenerator;
            $prevAsync = $this->inAsync;
            $prevTopLevel = $this->topLevel;
            $prevStaticBlock = $this->inStaticBlock;
            $this->inGenerator = $isGenerator;
            $this->inAsync = $isAsync;
            $this->topLevel = false;
            $this->inStaticBlock = false;
            $params = $this->parseFormalParameters();
            $body = $this->parseBlockStatement(true);
            $this->inGenerator = $prevGenerator;
            $this->inAsync = $prevAsync;
            $this->topLevel = $prevTopLevel;
            $this->inStaticBlock = $prevStaticBlock;
            $this->inMethodLike = $prevMethodLike;
            $this->validateStrictDirectiveWithNonSimpleParams($params, $body, $location);
            $this->validateParamLexBindingOverlap($params, $body);
            // Per §13.2.5.1: object method definitions cannot contain
            // SuperCall. Only class constructors may call super(). SuperProperty
            // remains allowed via inMethodLike.
            self::validateNoSuperCallInMethod($params, $body);
            // Per §15.5.1: MethodDefinition uses UniqueFormalParameters,
            // which forbids duplicate bound names unconditionally
            // (independent of strict mode).
            self::validateUniqueParameterNames($params, $location);
            // Per §15.5.1 / §15.8.1: generator method parameters cannot
            // contain YieldExpression; async method parameters cannot
            // contain AwaitExpression.
            if ($isGenerator || $isAsync) {
                foreach ($params as $p) {
                    if ($p !== null && self::containsYieldOrAwaitExpression($p)) {
                        throw new ParseError(
                            $isGenerator
                                ? 'YieldExpression not permitted in generator parameters'
                                : 'AwaitExpression not permitted in async method parameters',
                            new \PhpJs\Lexer\Token(TokenType::Identifier, '', $location),
                        );
                    }
                }
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
            return new Property($location, $key, $value, $kind, $computed, $shorthand, $method);
        }

        // Generator/async requires method syntax
        if ($isGenerator || $isAsync) {
            $method = true;
            $methodStartOffset = $location->offset;
            $prevMethodLike = $this->inMethodLike;
            $this->inMethodLike = true;
            $prevGenerator = $this->inGenerator;
            $prevAsync = $this->inAsync;
            $prevTopLevel = $this->topLevel;
            $prevStaticBlock = $this->inStaticBlock;
            $this->inGenerator = $isGenerator;
            $this->inAsync = $isAsync;
            $this->topLevel = false;
            $this->inStaticBlock = false;
            $params = $this->parseFormalParameters();
            $body = $this->parseBlockStatement(true);
            $this->inGenerator = $prevGenerator;
            $this->inAsync = $prevAsync;
            $this->topLevel = $prevTopLevel;
            $this->inStaticBlock = $prevStaticBlock;
            $this->inMethodLike = $prevMethodLike;
            $this->validateStrictDirectiveWithNonSimpleParams($params, $body, $location);
            $this->validateParamLexBindingOverlap($params, $body);
            self::validateNoSuperCallInMethod($params, $body);
            self::validateUniqueParameterNames($params, $location);
            // Per §15.5.1 / §15.8.1: generator method parameters cannot
            // contain YieldExpression, async method parameters cannot
            // contain AwaitExpression. containsYieldOrAwaitExpression
            // covers both; the expression form is only introduced in the
            // corresponding method kind, so the combined check is safe.
            if ($isGenerator || $isAsync) {
                foreach ($params as $p) {
                    if ($p !== null && self::containsYieldOrAwaitExpression($p)) {
                        throw new ParseError(
                            $isGenerator
                                ? 'YieldExpression not permitted in generator parameters'
                                : 'AwaitExpression not permitted in async method parameters',
                            new \PhpJs\Lexer\Token(TokenType::Identifier, '', $location),
                        );
                    }
                }
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
            return new Property($location, $key, $value, $kind, $computed, $shorthand, $method);
        }

        // get/set: parse params and body
        if ($kind === 'get' || $kind === 'set') {
            $method = true;
            $methodStartOffset = $location->offset;
            $prevMethodLike = $this->inMethodLike;
            $this->inMethodLike = true;
            $prevTopLevel = $this->topLevel;
            $prevStaticBlock = $this->inStaticBlock;
            $prevGenerator = $this->inGenerator;
            $prevAsync = $this->inAsync;
            $this->topLevel = false;
            $this->inStaticBlock = false;
            $this->inGenerator = false;
            $this->inAsync = false;
            $params = $this->parseFormalParameters();
            // Per §15.5.1 / §15.6.1: getters take exactly 0 parameters,
            // setters take exactly 1 (and it cannot be a rest element).
            $errToken = new \PhpJs\Lexer\Token(TokenType::Identifier, '', $location);
            if ($kind === 'get' && count($params) !== 0) {
                throw new ParseError(
                    'Getter must not have any formal parameters',
                    $errToken,
                );
            }
            if ($kind === 'set') {
                if (count($params) !== 1) {
                    throw new ParseError(
                        'Setter must have exactly one formal parameter',
                        $errToken,
                    );
                }
                if ($params[0] instanceof \PhpJs\Ast\Pattern\RestElement) {
                    throw new ParseError(
                        'Setter parameter must not be a rest element',
                        $errToken,
                    );
                }
                if (
                    $params[0] instanceof \PhpJs\Ast\Pattern\AssignmentPattern
                    && $params[0]->left instanceof Identifier
                    && ($params[0]->left->name === 'arguments' || $params[0]->left->name === 'eval')
                    && $this->strictMode
                ) {
                    throw new ParseError(
                        "Setter parameter '{$params[0]->left->name}' may not be used in strict mode",
                        $errToken,
                    );
                }
            }
            $body = $this->parseBlockStatement(true);
            $this->topLevel = $prevTopLevel;
            $this->inStaticBlock = $prevStaticBlock;
            $this->inGenerator = $prevGenerator;
            $this->inAsync = $prevAsync;
            $this->inMethodLike = $prevMethodLike;
            $this->validateStrictDirectiveWithNonSimpleParams($params, $body, $location);
            $this->validateParamLexBindingOverlap($params, $body);
            self::validateNoSuperCallInMethod($params, $body);
            $value = new FunctionExpression(
                $body->location,
                null,
                $params,
                $body,
                false,
                false,
                $this->extractSource($methodStartOffset),
            );
            return new Property($location, $key, $value, $kind, $computed, $shorthand, $method);
        }

        // { key: value }
        if ($this->eat(TokenType::Colon)) {
            // The value side may itself be a destructuring pattern that
            // contains a CoverInitializedName. Defer that validation to
            // the outer context (which is also an ObjectExpression).
            $prevAllowCoverInit = $this->allowCoverInit;
            $this->allowCoverInit = true;
            try {
                $value = $this->parseAssignmentExpression();
            } finally {
                $this->allowCoverInit = $prevAllowCoverInit;
            }
            return new Property($location, $key, $value, $kind, $computed, $shorthand, $method);
        }

        // Shorthand { x } or { x = default }
        if ($key instanceof Identifier) {
            // Computed keys ({[expr]}) cannot be used as shorthand — a
            // shorthand requires an IdentifierReference binding name, not a
            // runtime-computed key.
            if ($computed) {
                throw new ParseError(
                    'Computed property key cannot be used as shorthand',
                    new \PhpJs\Lexer\Token(TokenType::Identifier, $key->name, $key->location),
                );
            }
            // The shorthand value side is an IdentifierReference, which can
            // never be a reserved word (null/true/false/this/etc.).
            if (self::isReservedWordIdentifierName($key->name)) {
                throw new ParseError(
                    "Unexpected reserved word '{$key->name}' as shorthand property",
                    new \PhpJs\Lexer\Token(TokenType::Identifier, $key->name, $key->location),
                );
            }
            // The shorthand value side is an IdentifierReference, which in
            // strict mode rejects FutureReservedWords (package, private,
            // protected, etc.) as well as reserved keywords.
            if ($this->strictMode && self::isStrictReservedWordIdentifier($key->name)) {
                throw new ParseError(
                    "Unexpected reserved word '{$key->name}'",
                    new \PhpJs\Lexer\Token(TokenType::Identifier, $key->name, $key->location),
                );
            }
            // 'eval' and 'arguments' are restricted as IdentifierReference
            // in strict mode (per §13.1.1).
            if (
                $this->strictMode
                && ($key->name === 'eval' || $key->name === 'arguments')
            ) {
                throw new ParseError(
                    "Unexpected '{$key->name}' as binding identifier in strict mode",
                    new \PhpJs\Lexer\Token(TokenType::Identifier, $key->name, $key->location),
                );
            }
            // `await` cannot be IdentifierReference inside static blocks,
            // async functions, or module top-level.
            if (
                $key->name === 'await'
                && (
                    $this->inAsync
                    || ($this->topLevel && $this->moduleMode)
                    || $this->inStaticBlock
                )
            ) {
                throw new ParseError(
                    "Unexpected reserved word 'await' as binding identifier",
                    new \PhpJs\Lexer\Token(TokenType::Await, $key->name, $key->location),
                );
            }
            // `yield` cannot be IdentifierReference inside generators or
            // strict-mode code.
            if (
                $key->name === 'yield'
                && ($this->inGenerator || $this->strictMode)
            ) {
                throw new ParseError(
                    "Unexpected reserved word 'yield' as binding identifier",
                    new \PhpJs\Lexer\Token(TokenType::Yield, $key->name, $key->location),
                );
            }
            $shorthand = true;
            $value = $key;
            if ($this->eat(TokenType::Equal)) {
                $default = $this->parseAssignmentExpression();
                $value = new AssignmentExpression($key->location, '=', $key, $default);
            }
            return new Property($location, $key, $value, $kind, $computed, $shorthand, $method);
        }

        throw new ParseError('Expected property value', $this->current());
    }

    /**
     * @return array{0: Node, 1: bool} [key, computed]
     */
    private function parsePropertyKey(bool &$computed): array
    {
        if ($this->check(TokenType::LeftBracket)) {
            $computed = true;
            $this->advance();
            // Per spec: ComputedPropertyName uses AssignmentExpression[+In],
            // so `in` is always allowed inside [...] even when noIn is set.
            $savedNoIn = $this->noIn;
            $this->noIn = false;
            $key = $this->parseAssignmentExpression();
            $this->noIn = $savedNoIn;
            $this->expect(TokenType::RightBracket);
            return [$key, true];
        }

        if ($this->check(TokenType::Number)) {
            $token = $this->advance();
            $raw = $token->value;
            // BigInt literal as property name: use its canonical decimal
            // digit-string representation (per ToString for BigInt). Handle
            // 0xN, 0oN, 0bN bases explicitly — ltrim("0xa", "0") would leave
            // "xa" which is not a valid property key for 0xan (decimal 10).
            if (str_ends_with($raw, 'n')) {
                $body = substr($raw, 0, -1);
                if (str_starts_with($body, '0x') || str_starts_with($body, '0X')) {
                    $digits = self::nonDecimalDigitsToBigIntString(substr($body, 2), 16);
                } elseif (str_starts_with($body, '0o') || str_starts_with($body, '0O')) {
                    $digits = self::nonDecimalDigitsToBigIntString(substr($body, 2), 8);
                } elseif (str_starts_with($body, '0b') || str_starts_with($body, '0B')) {
                    $digits = self::nonDecimalDigitsToBigIntString(substr($body, 2), 2);
                } else {
                    $digits = ltrim($body, '0') ?: '0';
                }
                return [new Literal($token->location, $digits, $raw), false];
            }
            $value = (float) $raw;
            if ($value == (int) $value) {
                $value = (int) $raw;
            }
            return [new Literal($token->location, $value, $raw), false];
        }

        if ($this->check(TokenType::String)) {
            $token = $this->advance();
            return [new Literal($token->location, $token->value, $token->value), false];
        }

        return [$this->parsePropertyName(), false];
    }

    private function parsePropertyName(): Node
    {
        $token = $this->current();
        if ($token->type === TokenType::Identifier || $token->type->isKeyword()) {
            $this->advance();
            return new Identifier($token->location, $token->value);
        }
        if ($token->type === TokenType::Number) {
            return $this->parseNumericLiteral();
        }
        if ($token->type === TokenType::String) {
            return $this->parseStringLiteral();
        }
        throw new ParseError('Expected property name', $token);
    }

    private function parseFunctionExpression(): FunctionExpression
    {
        $location = $this->expect(TokenType::Function_)->location;
        $startOffset = $location->offset;
        $generator = $this->eat(TokenType::Star);
        $name = null;

        // Yield can appear as identifier name in non-generator, non-strict
        // functions. Await can appear as identifier name in non-async,
        // non-module-top-level functions.
        if ($this->check(TokenType::Identifier)) {
            $name = $this->advance()->value;
        } elseif (
            !$generator
            && !$this->strictMode
            && $this->check(TokenType::Yield)
        ) {
            $name = $this->advance()->value;
        } elseif ($this->check(TokenType::Await)) {
            // Per §15.2.1: FunctionExpression BindingIdentifier is [~Yield,
            // ~Await] — `await` is allowed as the function-expression name
            // regardless of outer context, including inside static blocks
            // and module top-level. The function's own body then parses
            // with await as an identifier too.
            $name = $this->advance()->value;
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
        // Plain function expressions cannot reference super.
        self::validateNoSuperInPlainFunctionBody($params, $body);
        // Generator function params cannot contain YieldExpression.
        if ($generator) {
            foreach ($params as $p) {
                if ($p !== null && self::containsYieldOrAwaitExpression($p)) {
                    throw new ParseError(
                        'YieldExpression not permitted in generator parameters',
                        new \PhpJs\Lexer\Token(TokenType::Identifier, '', $location),
                    );
                }
            }
        }

        return new FunctionExpression(
            $location,
            $name,
            $params,
            $body,
            $generator,
            false,
            $this->extractSource($startOffset),
        );
    }

    private function parseAsyncExpression(): Node
    {
        $token = $this->current();
        $next = $this->peek();

        // async function
        if ($next->type === TokenType::Function_ && !$next->lineTerminatorBefore) {
            // `async` here introduces an AsyncFunctionExpression keyword.
            if ($token->rawValue === 'escaped') {
                throw new ParseError(
                    "Keyword 'async' must not contain escaped characters",
                    $token,
                );
            }
            $location = $this->advance()->location;
            return $this->parseAsyncFunctionExpression($location);
        }

        // async (params) => body
        if ($next->type === TokenType::LeftParen && !$next->lineTerminatorBefore) {
            // `async` here introduces an AsyncArrowFunction.
            if ($token->rawValue === 'escaped') {
                throw new ParseError(
                    "Keyword 'async' must not contain escaped characters",
                    $token,
                );
            }
            $this->advance(); // consume 'async'
            $location = $token->location;
            $this->advance(); // consume (
            // Async arrow parameters are parsed with [+Await] so that
            // `await` is reserved in parameter expressions per spec.
            $prevAsync = $this->inAsync;
            $this->inAsync = true;
            try {
                $params = $this->parseArrowParams();
            } finally {
                $this->inAsync = $prevAsync;
            }
            if ($this->check(TokenType::Arrow)) {
                return $this->parseArrowFunctionFromParams($location, $params, true);
            }
            // Not an arrow function; restore is impractical so throw
            throw new ParseError('Expected =>', $this->current());
        }

        // async ident => body: source starts at 'async', pass async location.
        // Contextual keywords like `of`, `let`, `yield` are valid parameter names here.
        $isAsyncArrowParam = $next->type === TokenType::Identifier
            || $next->type === TokenType::Of
            || $next->type === TokenType::Let
            || $next->type === TokenType::Yield;
        if ($isAsyncArrowParam && !$next->lineTerminatorBefore) {
            $asyncLocation = $token->location;
            $asyncTokenEscaped = $token->rawValue === 'escaped';
            $this->advance(); // consume 'async'
            $id = $this->parseIdentifier();
            if ($this->check(TokenType::Arrow)) {
                // We're committing to AsyncArrowFunction: `async` was used
                // as a keyword and must not be unicode-escaped here.
                if ($asyncTokenEscaped) {
                    throw new ParseError(
                        "Keyword 'async' must not contain escaped characters",
                        $token,
                    );
                }
                return $this->parseArrowFunctionFromParams($asyncLocation, [$id], true);
            }
            // Not an arrow: "async" as identifier, rewind
            $this->pos -= 1;
            return new Identifier($token->location, 'async');
        }

        // Just the identifier "async"
        $this->advance();
        return new Identifier($token->location, 'async');
    }

    private function parseAsyncFunctionExpression(SourceLocation $location): FunctionExpression
    {
        $startOffset = $location->offset;
        // The `async` token has already been consumed; no escape check here.
        $this->expect(TokenType::Function_);
        $generator = $this->eat(TokenType::Star);
        $name = null;

        if ($this->check(TokenType::Identifier)) {
            $name = $this->advance()->value;
        }

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
        // Plain async function expressions cannot reference super.
        self::validateNoSuperInPlainFunctionBody($params, $body);
        // Async/generator function params cannot contain Yield/Await
        // expressions. (For async, [+Await]; for generator, [+Yield].)
        foreach ($params as $p) {
            if ($p !== null && self::containsYieldOrAwaitExpression($p)) {
                throw new ParseError(
                    'YieldExpression or AwaitExpression not permitted in async/generator parameters',
                    new \PhpJs\Lexer\Token(TokenType::Identifier, '', $location),
                );
            }
        }

        return new FunctionExpression(
            $location,
            $name,
            $params,
            $body,
            $generator,
            true,
            $this->extractSource($startOffset),
        );
    }

    /** @param Node[] $decorators */
    private function parseClassExpression(array $decorators = []): ClassExpression
    {
        $location = $this->expect(TokenType::Class_)->location;
        $startOffset = $location->offset;
        $id = null;
        // Class expressions are always strict (§15.7.1). Reject `yield`,
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
            $prevStrict = $this->strictMode;
            $this->strictMode = true;
            try {
                $superClass = $this->parseLeftHandSideExpression();
            } finally {
                $this->strictMode = $prevStrict;
            }
            if (
                ($superClass instanceof \PhpJs\Ast\Expression\ArrowFunction
                    || $superClass instanceof \PhpJs\Ast\Expression\AssignmentExpression
                    || $superClass instanceof \PhpJs\Ast\Expression\ConditionalExpression)
                && !$this->parenthesized->contains($superClass)
            ) {
                throw new ParseError(
                    'Invalid class heritage expression',
                    $this->current(),
                );
            }
        }
        $body = $this->parseClassBody();
        if ($superClass === null) {
            self::validateNoSuperCallInConstructor($body);
        }
        return new ClassExpression(
            $location,
            $id,
            $superClass,
            $body,
            $this->extractSource($startOffset),
            $decorators,
        );
    }

    private function parseLeftHandSideExpression(): Node
    {
        $expr = $this->parsePrimaryExpression();

        while (true) {
            if ($this->check(TokenType::Dot)) {
                $this->advance();
                $property = $this->parseIdentifierOrKeyword();
                $expr = new MemberExpression($expr->location, $expr, $property, false, false);
            } elseif ($this->check(TokenType::LeftBracket)) {
                $this->advance();
                $property = $this->parseExpression();
                $this->expect(TokenType::RightBracket);
                $expr = new MemberExpression($expr->location, $expr, $property, true, false);
            } elseif ($this->check(TokenType::LeftParen)) {
                $this->advance();
                $args = $this->parseArguments();
                $expr = new CallExpression($expr->location, $expr, $args, false);
            } else {
                break;
            }
        }

        return $expr;
    }

    private function parseNewExpression(): Node
    {
        $newToken = $this->expect(TokenType::New);
        $location = $newToken->location;
        // Per §12.7.2, the `new` keyword itself must not contain Unicode
        // escapes — `new.target` and `new Foo()` are syntax errors.
        if ($newToken->rawValue === 'escaped') {
            throw new ParseError(
                "Keyword 'new' must not contain escaped characters",
                $newToken,
            );
        }

        // new.target: meta-property, resolves to the [[NewTarget]] env binding.
        if ($this->eat(TokenType::Dot)) {
            $token = $this->current();
            if ($token->type === TokenType::Identifier && $token->value === 'target') {
                // Per §12.7.2, `target` in new.target is a reserved terminal
                // and must appear literally.
                if ($token->rawValue === 'escaped') {
                    throw new ParseError(
                        "'target' in new.target must not contain escaped characters",
                        $token,
                    );
                }
                $this->advance();
                return new Identifier($location, '[[NewTarget]]');
            }
            throw new ParseError('Expected "target" after "new."', $token);
        }

        // Nested new: new new Foo()
        if ($this->check(TokenType::New)) {
            $callee = $this->parseNewExpression();
        } else {
            $calleeStartToken = $this->current();
            $callee = $this->parsePrimaryExpression();
            // Per §13.3.5 and §13.3.10.1, ImportCall (import(x) and the
            // source/defer phase variants) is a CallExpression, not a
            // MemberExpression, so `new import(x)` and `new import.source(x)`
            // are SyntaxErrors. `new import.meta` is a valid MemberExpression
            // (runtime TypeError when evaluated). Parenthesized ImportCall
            // like `new (import(x))` is also valid because the parens make
            // it a CoverParenthesizedExpressionAndArrowParameterList (a
            // PrimaryExpression, i.e. MemberExpression).
            if (
                $callee instanceof \PhpJs\Ast\Expression\ImportExpression
                && !$this->parenthesized->contains($callee)
            ) {
                throw new ParseError(
                    'import() cannot be preceded by the new keyword',
                    $calleeStartToken,
                );
            }

            // Allow member access and tagged templates on callee: new Foo.Bar(), new tag`tmpl`
            while (
                $this->check(TokenType::Dot)
                || $this->check(TokenType::LeftBracket)
                || $this->check(TokenType::NoSubstitutionTemplate)
                || $this->check(TokenType::TemplateHead)
            ) {
                if ($this->eat(TokenType::Dot)) {
                    $property = $this->parseIdentifierOrKeyword();
                    $callee = new MemberExpression($callee->location, $callee, $property, false, false);
                } elseif ($this->check(TokenType::LeftBracket)) {
                    $this->advance();
                    $property = $this->parseExpression();
                    $this->expect(TokenType::RightBracket);
                    $callee = new MemberExpression($callee->location, $callee, $property, true, false);
                } else {
                    // Tagged template: new tag`template` means new (tag`template`)
                    $quasi = $this->parseTemplateLiteral(true);
                    $callee = new TaggedTemplate($callee->location, $callee, $quasi);
                }
            }
        }

        $args = [];
        if ($this->eat(TokenType::LeftParen)) {
            $args = $this->parseArguments();
        }

        return new NewExpression($location, $callee, $args);
    }

    private function parseSpreadElement(): SpreadElement
    {
        $location = $this->expect(TokenType::Ellipsis)->location;
        $argument = $this->parseAssignmentExpression();
        return new SpreadElement($location, $argument);
    }

    private function parseTemplateLiteral(bool $taggedContext = false): TemplateLiteral
    {
        $token = $this->current();
        $location = $token->location;
        $quasis = [];
        $expressions = [];

        if ($token->type === TokenType::NoSubstitutionTemplate) {
            $this->advance();
            if ($token->cookedInvalid && !$taggedContext) {
                throw new ParseError(
                    'Invalid escape sequence in untagged template literal',
                    $token,
                );
            }
            $cooked = $token->cookedInvalid ? null : $token->value;
            $quasis[] = new TemplateElement($token->location, $token->rawValue ?? $token->value, $cooked, true);
            return new TemplateLiteral($location, $quasis, $expressions);
        }

        // TemplateHead: tokens are already split by the lexer.
        $this->advance();
        if ($token->cookedInvalid && !$taggedContext) {
            throw new ParseError(
                'Invalid escape sequence in untagged template literal',
                $token,
            );
        }
        $cooked = $token->cookedInvalid ? null : $token->value;
        $quasis[] = new TemplateElement($token->location, $token->rawValue ?? $token->value, $cooked, false);

        while (true) {
            $expressions[] = $this->parseExpression();

            // The lexer has already tokenized the continuation as TemplateTail or TemplateMiddle.
            $cont = $this->current();

            if ($cont->type === TokenType::TemplateTail) {
                $this->advance();
                if ($cont->cookedInvalid && !$taggedContext) {
                    throw new ParseError(
                        'Invalid escape sequence in untagged template literal',
                        $cont,
                    );
                }
                $cooked = $cont->cookedInvalid ? null : $cont->value;
                $quasis[] = new TemplateElement($cont->location, $cont->rawValue ?? $cont->value, $cooked, true);
                break;
            }

            if ($cont->type === TokenType::TemplateMiddle) {
                $this->advance();
                if ($cont->cookedInvalid && !$taggedContext) {
                    throw new ParseError(
                        'Invalid escape sequence in untagged template literal',
                        $cont,
                    );
                }
                $cooked = $cont->cookedInvalid ? null : $cont->value;
                $quasis[] = new TemplateElement($cont->location, $cont->rawValue ?? $cont->value, $cooked, false);
                continue;
            }

            throw new ParseError('Expected template continuation', $cont);
        }

        return new TemplateLiteral($location, $quasis, $expressions);
    }

    /** @return Node[] */
    private function parseArguments(): array
    {
        $args = [];

        while (!$this->check(TokenType::RightParen) && !$this->isAtEnd()) {
            if ($this->check(TokenType::Ellipsis)) {
                $args[] = $this->parseSpreadElement();
            } else {
                $args[] = $this->parseAssignmentExpression();
            }

            if (!$this->check(TokenType::RightParen)) {
                $this->expect(TokenType::Comma);
            }
        }

        $this->expect(TokenType::RightParen);
        return $args;
    }

    /**
     * Parse import() call or import.meta meta-property.
     *
     * Per spec, `import` as a keyword must not contain escape sequences
     * when used as ImportCall or import.meta. The lexer emits the token
     * with rawValue tracking.
     */
    private function parseImportExpression(): Node
    {
        $token = $this->advance(); // consume 'import'
        $location = $token->location;

        // Per spec: import keyword must not contain escape sequences.
        if ($token->rawValue === 'escaped') {
            throw new ParseError('Unexpected token', $token);
        }

        // import.meta, import.source(...), import.defer(...)
        if ($this->check(TokenType::Dot)) {
            $this->advance();
            $prop = $this->current();
            if ($prop->type === TokenType::Identifier && $prop->value === 'meta') {
                // Per §16.2.1.1: `import.meta` is only valid when the
                // syntactic goal is Module. In Script mode, inside eval
                // code, in Function("...") bodies, etc., it is a parse
                // SyntaxError.
                if (!$this->moduleMode) {
                    throw new ParseError(
                        "'import.meta' may only appear in a module",
                        $token,
                    );
                }
                // The `meta` identifier must appear literally — Unicode
                // escapes are rejected.
                if ($prop->rawValue === 'escaped') {
                    throw new ParseError(
                        "'meta' in import.meta must not contain escaped characters",
                        $prop,
                    );
                }
                $this->advance();
                return new MetaProperty($location, 'import', 'meta');
            }
            // ES2024+ proposals: `import.source(x)` and `import.defer(x)` —
            // parse as a dynamic import for compatibility; semantic phase is
            // not distinguished (we evaluate the same way as import(x)).
            if (
                $prop->type === TokenType::Identifier
                && ($prop->value === 'source' || $prop->value === 'defer')
            ) {
                $this->advance();
                if (!$this->check(TokenType::LeftParen)) {
                    throw new ParseError(
                        'Expected "(" after "import.' . $prop->value . '"',
                        $this->current(),
                    );
                }
                $this->expect(TokenType::LeftParen);
                if ($this->check(TokenType::RightParen)) {
                    throw new ParseError('import requires a specifier', $this->current());
                }
                $source = $this->parseAssignmentExpression();
                $options = null;
                if ($this->eat(TokenType::Comma)) {
                    if (!$this->check(TokenType::RightParen)) {
                        $options = $this->parseAssignmentExpression();
                        $this->eat(TokenType::Comma);
                    }
                }
                $this->expect(TokenType::RightParen);
                return new \PhpJs\Ast\Expression\ImportExpression($location, $source, $options);
            }
            throw new ParseError('Expected "meta" after "import."', $prop);
        }

        // import(assignmentExpression) or import(assignmentExpression, options)
        if (!$this->check(TokenType::LeftParen)) {
            throw new ParseError('Expected "(" or "." after "import"', $this->current());
        }

        $this->expect(TokenType::LeftParen);

        // import() with no arguments is a syntax error per spec.
        if ($this->check(TokenType::RightParen)) {
            throw new ParseError(
                'import() requires a specifier',
                $this->current(),
            );
        }

        // Per spec, import() arguments use AssignmentExpression[+In], so `in`
        // is allowed even when the surrounding context has noIn set (e.g.
        // inside a for-loop init).
        $savedNoIn = $this->noIn;
        $this->noIn = false;
        $source = $this->parseAssignmentExpression();
        $options = null;

        // Optional second argument (import attributes / options).
        if ($this->eat(TokenType::Comma)) {
            if (!$this->check(TokenType::RightParen)) {
                $options = $this->parseAssignmentExpression();
                // Allow trailing comma.
                $this->eat(TokenType::Comma);
            }
        }
        $this->noIn = $savedNoIn;

        $this->expect(TokenType::RightParen);

        return new ImportExpression($location, $source, $options);
    }

    private function parseSuperExpression(): Node
    {
        $location = $this->advance()->location;
        $superIdent = new Identifier($location, 'super');

        if ($this->check(TokenType::Dot)) {
            $this->advance();
            // Per §15.7.1: super.PrivateName is forbidden — private names
            // live on the lexically enclosing class, not on the prototype.
            if ($this->check(TokenType::PrivateIdentifier)) {
                throw new ParseError(
                    'Cannot access private name via super',
                    $this->current(),
                );
            }
            $property = $this->parseIdentifierOrKeyword();
            return new MemberExpression($location, $superIdent, $property, false, false);
        }

        if ($this->check(TokenType::LeftBracket)) {
            $this->advance();
            $property = $this->parseExpression();
            $this->expect(TokenType::RightBracket);
            return new MemberExpression($location, $superIdent, $property, true, false);
        }

        if ($this->check(TokenType::LeftParen)) {
            $this->advance();
            $args = $this->parseArguments();
            return new CallExpression($location, $superIdent, $args, false);
        }

        return $superIdent;
    }

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
