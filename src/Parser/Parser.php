<?php

declare(strict_types=1);

namespace Phasis\Parser;

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
use Phasis\Lexer\Lexer;
use Phasis\Lexer\SourceLocation;
use Phasis\Lexer\Token;
use Phasis\Lexer\TokenType;

class Parser
{
    use Parts\StatementParser;
    use Parts\ExpressionParser;
    use Parts\TokenNavigation;

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

    /**
     * Tracks nodes that were wrapped in parentheses, for IsIdentifierRef checks.
     *
     * @var \SplObjectStorage<\Phasis\Ast\Node, null>
     */
    private \SplObjectStorage $parenthesized;

    /**
     * Tracks ArrayExpressions where a trailing comma followed a rest element.
     *
     * @var \SplObjectStorage<\Phasis\Ast\Node, null>
     */
    private \SplObjectStorage $arrayExpressionsWithTrailingCommaAfterRest;

    /**
     * Tracks string Literal nodes that contain a legacy octal escape.
     *
     * @var \SplObjectStorage<\Phasis\Ast\Node, null>
     */
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

    private bool $collectComments = false;

    /**
     * Capture comments during tokenization so tooling (the formatter) can
     * reattach them. Must be set before parse() forces tokenization.
     */
    public function setCollectComments(bool $collectComments): void
    {
        $this->collectComments = $collectComments;
    }

    /** @return \Phasis\Lexer\Comment[] Comments in source order; empty unless collection was enabled. */
    public function comments(): array
    {
        return $this->lexer->comments();
    }

    private function ensureTokenized(): void
    {
        if ($this->tokens === null) {
            $this->lexer->setModuleMode($this->moduleMode);
            $this->lexer->setCollectComments($this->collectComments);
            $this->tokens = $this->lexer->tokenize();
        }
    }

    /**
     * Returns the value of the last `//# sourceURL=URL` (or legacy
     * `//@ sourceURL=URL`) directive comment seen while tokenizing this
     * source. Returns null if no such pragma was present. Callers that
     * read this should call `parse()` (or otherwise force tokenization)
     * first.
     */
    public function getSourceURL(): ?string
    {
        return $this->lexer->getSourceURL();
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

    public static function parseSource(string $source, string $sourceType = 'script'): Program
    {
        if ($sourceType !== 'script' && $sourceType !== 'module') {
            throw new \InvalidArgumentException(
                "sourceType must be \"script\" or \"module\", got \"{$sourceType}\"",
            );
        }
        $parser = new self($source);
        $parser->setModuleMode($sourceType === 'module');
        return $parser->parse();
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
                throw new \Phasis\Exceptions\SyntaxError(
                    "Duplicate export of '{$name}'",
                );
            }
            $exported[$name] = true;
        };
        foreach ($body as $stmt) {
            if (!($stmt instanceof \Phasis\Ast\Declaration\ExportDeclaration)) {
                continue;
            }
            if ($stmt->isDefault) {
                $addExport('default');
            }
            foreach ($stmt->specifiers as $spec) {
                $addExport($spec->exported ?? $spec->local);
            }
            if ($stmt->isAll && $stmt->allAs !== null) {
                $addExport($stmt->allAs);
            }
            if ($stmt->declaration !== null) {
                $inner = $stmt->declaration;
                if (
                    $inner instanceof \Phasis\Ast\Declaration\FunctionDeclaration
                    || $inner instanceof \Phasis\Ast\Declaration\ClassDeclaration
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
            $node instanceof \Phasis\Ast\Declaration\FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
        ) {
            self::validateBreakContinue($node->body);
            // Also walk the body to find nested functions/classes/arrows
            // whose bodies need their own validation.
            self::walkForNestedFunctionScopes($node->body);
            return;
        }
        if ($node instanceof \Phasis\Ast\Expression\ArrowFunction) {
            self::validateBreakContinue($node->body);
            self::walkForNestedFunctionScopes($node->body);
            return;
        }
        // For class bodies / static blocks: each method body is a separate
        // scope, validated when we hit its FunctionExpression. Static
        // blocks are also validated separately.
        if (
            $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
        ) {
            foreach ($node->body as $element) {
                if ($element instanceof \Phasis\Ast\Expression\StaticBlock) {
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
}
