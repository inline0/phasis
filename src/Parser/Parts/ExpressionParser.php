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
use Phasis\Parser\Precedence;
use Phasis\Parser\ParseError;
use Phasis\Lexer\Lexer;
use Phasis\Lexer\SourceLocation;
use Phasis\Lexer\Token;
use Phasis\Lexer\TokenType;

/**
 * Parser part: ExpressionParser. Composed into Parser via
 * `use Parts\ExpressionParser;`. `self::`/`$this->` references resolve
 * into the composing class.
 */
trait ExpressionParser
{
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
                $left instanceof \Phasis\Ast\Expression\AwaitExpression
                || $left instanceof \Phasis\Ast\Expression\YieldExpression
            ) {
                throw new ParseError(
                    $left instanceof \Phasis\Ast\Expression\AwaitExpression
                        ? "Invalid left-hand side in assignment: AwaitExpression"
                        : "Invalid left-hand side in assignment: YieldExpression",
                    $op,
                );
            }
            // new.target and import.meta are invalid assignment targets.
            if ($left instanceof \Phasis\Ast\Expression\MetaProperty) {
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
                && ($left instanceof ObjectExpression || $left instanceof \Phasis\Ast\Expression\ArrayExpression)
                && $this->parenthesized->offsetExists($left)
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
                && ($left instanceof ObjectExpression || $left instanceof \Phasis\Ast\Expression\ArrayExpression)
            ) {
                $this->validateAsAssignmentPattern($left);
            }
            $leftParenthesized = $this->parenthesized->offsetExists($left);
            $right = $this->parseAssignmentExpression();
            // For destructuring assignment (LHS is an object/array literal),
            // validate that shorthand properties cannot use reserved words.
            if ($op->value === '=' && $left instanceof ObjectExpression) {
                $this->validateAssignmentTargetObjectShorthand($left);
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
                    throw new \Phasis\Exceptions\SyntaxError(
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
                && !$this->parenthesized->offsetExists($left)
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
                        && !$this->parenthesized->offsetExists($left)
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
                        && !$this->parenthesized->offsetExists($left)
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
                        && !$this->parenthesized->offsetExists($right)
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
                        && !$this->parenthesized->offsetExists($right)
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
                // Property access forces the object operand to be evaluated
                // as an expression — a CoverInitializedName like `{ a = 0 }`
                // can no longer be salvaged into an AssignmentPattern, so it
                // must surface as a SyntaxError per spec 13.2.5.1 / 13.15.1.
                self::rejectCoverInitializedName($left);
                $property = $this->parseIdentifierOrKeyword();
                $left = new MemberExpression($left->location, $left, $property, false, false);
                continue;
            }

            if ($token->type === TokenType::OptionalChaining) {
                // Per the optional-chaining proposal: `super` is not a
                // valid LHS for OptionalChain. `super?.x` and `super?.()`
                // are SyntaxErrors regardless of context.
                if ($left instanceof Identifier && $left->name === 'super') {
                    throw new ParseError(
                        'Invalid optional chain from super property',
                        $token,
                    );
                }
                // Per the optional-chaining proposal: `new C ?.` is invalid
                // because `new C` is a NewExpression (not a MemberExpression
                // or CallExpression), and only those can begin an
                // OptionalExpression. `new C() ?.x` is fine — the args turn
                // it into a MemberExpression.
                if ($left instanceof NewExpression && !$left->hasArguments) {
                    throw new ParseError(
                        'Invalid optional chain from new expression',
                        $token,
                    );
                }
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
                // Per spec, an ArrowFunction is at AssignmentExpression
                // level — `()=>{}` cannot be extended directly with member
                // access. Parens force LHS shape: `(()=>{})[0]` is valid.
                if ($left instanceof ArrowFunction && !$this->parenthesized->offsetExists($left)) {
                    $this->pos--;
                    return $left;
                }
                self::rejectCoverInitializedName($left);
                $property = $this->parseExpression();
                $this->expect(TokenType::RightBracket);
                $left = new MemberExpression($left->location, $left, $property, true, false);
                continue;
            }

            if ($token->type === TokenType::LeftParen) {
                if ($left instanceof ArrowFunction && !$this->parenthesized->offsetExists($left)) {
                    $this->pos--;
                    return $left;
                }
                self::rejectCoverInitializedName($left);
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
        // In non-async script code, `await` is normally an identifier — but
        // V8 / SpiderMonkey detect `await ExpressionStart` and throw a
        // dedicated SyntaxError so users see the actual problem rather than
        // a "missing ;" generic message. Only fire when no LineTerminator
        // separates `await` from the following token (otherwise ASI applies
        // and `await` is a valid bare identifier-statement).
        if ($token->type === TokenType::Await) {
            $next = $this->peek();
            // The lexer may have eagerly tokenized `await /…/` as
            // RegExp because Yield/Await aren't in its value-producing
            // list. In non-async context `await` is an identifier, so
            // `/` is division — re-lex the regex token before deciding
            // whether the rest of the expression is even ill-formed.
            if ($next->type === TokenType::RegExp) {
                if ($this->reinterpretRegExpAsDivision($this->pos + 1)) {
                    $next = $this->peek();
                }
            }
            // Only fire when the following token unambiguously starts an
            // operand for the await operator. `await(...)` and `await[...]`
            // are valid CallExpression / MemberExpression continuations on
            // the `await` identifier, so they are not errors here.
            $nextStartsExpr = match ($next->type) {
                TokenType::Number, TokenType::String,
                TokenType::NoSubstitutionTemplate, TokenType::TemplateHead,
                TokenType::Identifier, TokenType::PrivateIdentifier,
                TokenType::True, TokenType::False, TokenType::Null,
                TokenType::This, TokenType::Function_, TokenType::New,
                TokenType::LeftBrace,
                TokenType::RegExp, TokenType::Class_, TokenType::Async => true,
                default => false,
            };
            if ($nextStartsExpr && !$next->lineTerminatorBefore) {
                throw new ParseError(
                    'await is only valid in async functions and the top level bodies of modules',
                    $token,
                );
            }
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

        // Per spec, ArrowFunction and ClassExpression / FunctionExpression
        // with body are AssignmentExpression-level — they cannot be extended
        // with member/call/optional chains directly. To do so the source
        // must wrap them in parens, which marks them as parenthesized
        // (and therefore valid LHS continuations). The existing tests we
        // care about wrap arrows in parens before extending.
        $isUnparenthesizedArrow = $expr instanceof ArrowFunction
            && !$this->parenthesized->offsetExists($expr);

        while (true) {
            if ($isUnparenthesizedArrow) {
                break;
            }
            if ($this->check(TokenType::Dot) && !$this->current()->lineTerminatorBefore) {
                // Property access forces the receiver to be a normal
                // expression — a CoverInitializedName like `{ a = 0 }` can
                // no longer be salvaged into an AssignmentPattern, so any
                // such inner cover-init is a SyntaxError per spec
                // 13.2.5.1 / 13.15.1.
                self::rejectCoverInitializedName($expr);
                $this->advance();
                $property = $this->parseIdentifierOrKeyword();
                $expr = new MemberExpression($expr->location, $expr, $property, false, false);
                continue;
            }
            if ($this->check(TokenType::LeftBracket)) {
                self::rejectCoverInitializedName($expr);
                $this->advance();
                $property = $this->parseExpression();
                $this->expect(TokenType::RightBracket);
                $expr = new MemberExpression($expr->location, $expr, $property, true, false);
                continue;
            }
            if ($this->check(TokenType::LeftParen)) {
                self::rejectCoverInitializedName($expr);
                $this->advance();
                $args = $this->parseArguments();
                $expr = new CallExpression($expr->location, $expr, $args, false);
                continue;
            }
            if ($this->check(TokenType::OptionalChaining)) {
                // Per the optional-chaining proposal: `super` and a bare
                // NewExpression cannot begin an OptionalChain.
                if ($expr instanceof Identifier && $expr->name === 'super') {
                    throw new ParseError(
                        'Invalid optional chain from super property',
                        $this->current(),
                    );
                }
                if ($expr instanceof NewExpression && !$expr->hasArguments) {
                    throw new ParseError(
                        'Invalid optional chain from new expression',
                        $this->current(),
                    );
                }
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
            throw new \Phasis\Exceptions\SyntaxError(
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
                throw new \Phasis\Exceptions\SyntaxError(
                    'Octal literals are not allowed in strict mode',
                    $token->location,
                );
            }
            $value = octdec(substr($raw, 3));
        } elseif (str_starts_with($raw, '0b') || str_starts_with($raw, '0B')) {
            $value = bindec(substr($raw, 2));
        } else {
            $value = (float) $raw;
            // Test "fits in a PHP int and is integral" without ever
            // casting an out-of-range float to int (PHP 8.5 emits a
            // deprecation when the float exceeds PHP_INT_MAX, which
            // poisons strict harnesses like PHPUnit's failOnWarning).
            // Number.MAX_VALUE (1.7976e308) hits this when lodash and
            // other libraries embed it as a numeric literal.
            $isInt = !str_contains($raw, '.')
                && !str_contains($raw, 'e')
                && !str_contains($raw, 'E')
                && abs($value) < PHP_INT_MAX
                && $value == (int) $value;
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
            \Phasis\Runtime\Interpreter::validateRegExpModifierGroups($pattern);
            if (\Phasis\Runtime\Interpreter::hasDuplicateNamedGroupsInSameAlternative($pattern)) {
                throw new \Phasis\Exceptions\SyntaxError(
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
    private static function validateRegExpFlagsAtParseTime(string $flags, \Phasis\Lexer\Token $token): void
    {
        static $allowed = ['g', 'i', 'm', 's', 'u', 'v', 'y', 'd'];
        $seen = [];
        for ($i = 0, $n = strlen($flags); $i < $n; $i++) {
            $c = $flags[$i];
            if (!in_array($c, $allowed, true)) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Invalid regular expression flag '{$c}'",
                );
            }
            if (isset($seen[$c])) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Invalid regular expression flag '{$c}'",
                );
            }
            $seen[$c] = true;
        }
        if (isset($seen['u']) && isset($seen['v'])) {
            throw new \Phasis\Exceptions\SyntaxError(
                'Invalid regular expression: cannot combine u and v flags',
            );
        }
    }

    /**
     * Lightweight ECMAScript regex pattern validator. Catches the most
     * common parse-time errors. Not a full Pattern parser.
     */
    /**
     * Public wrapper so the runtime RegExp constructor can re-run the
     * same parse-time validation when flags differ from the originating
     * regex literal.
     */
    public static function validateRegExpAtRuntime(string $pattern, string $flags): void
    {
        self::validateRegExpPatternAtParseTime($pattern, $flags);
    }

    private static function validateRegExpPatternAtParseTime(string $pattern, string $flags): void
    {
        $unicode = str_contains($flags, 'u') || str_contains($flags, 'v');
        $isVFlag = str_contains($flags, 'v');
        $groupNames = [];
        $kRefs = [];
        $hasNamedGroup = self::collectRegExpGroupNamesAndKRefs($pattern, $groupNames, $kRefs);
        // The +N grammar parameter is set whenever the pattern contains a
        // GroupSpecifier `(?<Name>...)` anywhere — even if our tokenising
        // pre-pass swallowed it inside an attempted \k<...> name. This second
        // check ensures the strict grammar still fires for inputs like
        // `\k<a(?<a>a)`.
        if (!$hasNamedGroup) {
            $hasNamedGroup = self::regexpHasNamedGroupDeclaration($pattern);
        }
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
        // Bytes that change state during the structural walk — anything
        // else is a plain atom that just sets prevAtom=true and advances
        // the cursor. Skipping such runs collapses the 16 MiB \u{…} body
        // to a single C-level strcspn call.
        $stopOpen = "\\[](){}*+?|^\$";
        $stopClass = "\\]";
        while ($i < $len) {
            $c = $pattern[$i];
            if ($inClass) {
                if ($c !== '\\' && $c !== ']') {
                    $skip = strcspn($pattern, $stopClass, $i);
                    if ($skip > 0) {
                        // prevAtom stays false inside a class.
                        $i += $skip;
                        continue;
                    }
                }
            } elseif (
                $c !== '\\' && $c !== '[' && $c !== ']'
                && $c !== '(' && $c !== ')' && $c !== '{' && $c !== '}'
                && $c !== '*' && $c !== '+' && $c !== '?' && $c !== '|'
                && $c !== '^' && $c !== '$'
            ) {
                $skip = strcspn($pattern, $stopOpen, $i);
                if ($skip > 0) {
                    $lastClosedGroupWasLookbehind = false;
                    $lastClosedGroupWasLookahead = false;
                    $prevAtom = true;
                    $i += $skip;
                    continue;
                }
            }
            if ($c === '\\') {
                // Escape sequences are atoms — once consumed, any
                // immediately following quantifier no longer applies to a
                // bare lookbehind close-paren.
                $lastClosedGroupWasLookbehind = false;
                $lastClosedGroupWasLookahead = false;
                if ($i + 1 >= $len) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: \\ at end of pattern",
                    );
                }
                $next = $pattern[$i + 1];
                if ($next === 'u') {
                    if ($i + 2 < $len && $pattern[$i + 2] === '{') {
                        // Bulk-extract the hex run via strcspn so a 16 MiB
                        // zero-padded \\u{…} body validates in one C call
                        // instead of 16M PHP iterations.
                        $hexStart = $i + 3;
                        $closeBrace = strpos($pattern, '}', $hexStart);
                        $end = $closeBrace === false ? $len : $closeBrace;
                        // hexLen = run of hex digits starting at $hexStart.
                        // strspn returns 0 if the first byte isn't a hex digit.
                        $hexLen = strspn($pattern, '0123456789abcdefABCDEF', $hexStart, $end - $hexStart);
                        $j = $hexStart + $hexLen;
                        if ($j < $end) {
                            // Non-hex byte before close-brace.
                            if ($unicode) {
                                throw new \Phasis\Exceptions\SyntaxError(
                                    "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                                );
                            }
                        }
                        $hex = $hexLen === 0 ? '' : substr($pattern, $hexStart, $hexLen);
                        if ($unicode && ($j >= $len || $pattern[$j] !== '}' || $hex === '')) {
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                            );
                        }
                        // hexdec on a 16 MiB string is itself O(N); use the
                        // simple "more than 7 leading non-zero hex digits"
                        // check first since 0x10FFFF fits in 6 nybbles.
                        if ($unicode) {
                            $stripped = ltrim($hex, '0');
                            if (strlen($stripped) > 6 || ($stripped !== '' && hexdec($stripped) > 0x10FFFF)) {
                                throw new \Phasis\Exceptions\SyntaxError(
                                    "Invalid regular expression: /{$pattern}/: Code point out of range",
                                );
                            }
                        }
                        $i = $j + 1;
                        $prevAtom = true;
                        continue;
                    }
                    if ($unicode) {
                        if ($i + 5 >= $len) {
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                            );
                        }
                        for ($k = 2; $k < 6; $k++) {
                            if (!ctype_xdigit($pattern[$i + $k])) {
                                throw new \Phasis\Exceptions\SyntaxError(
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
                        if ($digits === '0') {
                            // \0 is the NUL character (allowed).
                        } elseif ($digits[0] === '0') {
                            // Leading-zero octals are forbidden in u-mode.
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid escape",
                            );
                        } elseif ($num > $captureCount) {
                            throw new \Phasis\Exceptions\SyntaxError(
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
                    // reference an existing group. In non-unicode mode
                    // without any named groups, malformed \k<...> falls
                    // back to treating \k as a literal escape.
                    if ($i + 2 >= $len || $pattern[$i + 2] !== '<') {
                        if ($unicode || $hasNamedGroup) {
                            throw new \Phasis\Exceptions\SyntaxError(
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
                        $invalidName = $j >= $len || $name === '' || !self::isValidGroupName($name);
                        $unknownName = !$invalidName && !isset($groupNames[$name]);
                        if ($invalidName) {
                            if ($unicode || $hasNamedGroup) {
                                throw new \Phasis\Exceptions\SyntaxError(
                                    "Invalid regular expression: /{$pattern}/: Invalid named back reference",
                                );
                            }
                            // Non-unicode without named groups: fall through
                            // and treat \k as literal "k".
                        } elseif ($unknownName) {
                            if ($unicode || $hasNamedGroup) {
                                throw new \Phasis\Exceptions\SyntaxError(
                                    "Invalid regular expression: /{$pattern}/: Invalid named capture referenced",
                                );
                            }
                            // Non-unicode without named groups: fall through.
                        } else {
                            $i = $j + 1;
                            $prevAtom = true;
                            continue;
                        }
                    }
                }
                // u-flag IdentityEscape: only SyntaxCharacter / / are allowed
                if ($unicode && !$inClass) {
                    static $allowedIdEscape = ['^', '$', '.', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|', '/', '\\',
                        'd', 'D', 's', 'S', 'w', 'W', 'b', 'B', 'f', 'n', 'r', 't', 'v', '0', 'c', 'x', 'u', 'p', 'P', 'k'];
                    if (!in_array($next, $allowedIdEscape, true) && !ctype_digit($next)) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid escape",
                        );
                    }
                }
                // \xNN must consume exactly 2 hex digits in u-mode.
                if ($next === 'x' && $unicode) {
                    $hex1 = $i + 2 < $len ? $pattern[$i + 2] : '';
                    $hex2 = $i + 3 < $len ? $pattern[$i + 3] : '';
                    if (!ctype_xdigit($hex1) || !ctype_xdigit($hex2)) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid \\x escape",
                        );
                    }
                }
                // \c must be followed by A-Za-z in u/v mode. In non-u, \c
                // followed by a non-letter was historically accepted but the
                // Annex B extension that allowed it is precluded under u.
                if ($next === 'c' && $unicode) {
                    $after = $i + 2 < $len ? $pattern[$i + 2] : '';
                    if (!($after >= 'A' && $after <= 'Z') && !($after >= 'a' && $after <= 'z')) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: \\c must be followed by a letter in unicode mode",
                        );
                    }
                }
                // \p{Property} / \P{Property} consume the trailing {…}.
                if ($next === 'p' || $next === 'P') {
                    if ($i + 2 < $len && $pattern[$i + 2] === '{') {
                        $j = $i + 3;
                        while ($j < $len && $pattern[$j] !== '}') {
                            $j++;
                        }
                        if ($j < $len) {
                            $propExpr = substr($pattern, $i + 3, $j - ($i + 3));
                            // Reject only the syntactic-shape errors we
                            // can be sure about. Specific value names we
                            // do not enumerate (e.g. recent Script values)
                            // are deferred to runtime to avoid
                            // false-positive SyntaxErrors.
                            if ($unicode && $propExpr === '') {
                                throw new \Phasis\Exceptions\SyntaxError(
                                    "Invalid regular expression: /{$pattern}/: Empty property name",
                                );
                            }
                            if ($unicode && (str_starts_with($propExpr, '^') || str_contains($propExpr, '^'))) {
                                throw new \Phasis\Exceptions\SyntaxError(
                                    "Invalid regular expression: /{$pattern}/: Invalid property name",
                                );
                            }
                            if ($unicode && str_contains($propExpr, '=')) {
                                $parts = explode('=', $propExpr, 2);
                                if ($parts[0] === '' || $parts[1] === '') {
                                    throw new \Phasis\Exceptions\SyntaxError(
                                        "Invalid regular expression: /{$pattern}/: Invalid property name",
                                    );
                                }
                                if (\Phasis\Runtime\Interpreter::isBinaryUnicodePropertyName($parts[0])) {
                                    throw new \Phasis\Exceptions\SyntaxError(
                                        "Invalid regular expression: /{$pattern}/: Binary property used with value",
                                    );
                                }
                                if (\Phasis\Runtime\Interpreter::isNonBinaryUnicodePropertyName($parts[0]) === false) {
                                    throw new \Phasis\Exceptions\SyntaxError(
                                        "Invalid regular expression: /{$pattern}/: Invalid property name",
                                    );
                                }
                                // Per spec the value side is also matched
                                // exactly: if we recognise the property name
                                // as General_Category, the value must be a
                                // canonical-cased GC value.
                                $normName = \Phasis\Runtime\Interpreter::normalizeUnicodePropertyName($parts[0]);
                                if (
                                    $normName === 'General_Category'
                                    && \Phasis\Runtime\Interpreter::isGeneralCategoryValue($parts[1]) === false
                                ) {
                                    throw new \Phasis\Exceptions\SyntaxError(
                                        "Invalid regular expression: /{$pattern}/: Invalid property value",
                                    );
                                }
                            }
                            if ($unicode && !str_contains($propExpr, '=')) {
                                if (\Phasis\Runtime\Interpreter::isLoneUnicodePropertyKnown($propExpr) === false) {
                                    throw new \Phasis\Exceptions\SyntaxError(
                                        "Invalid regular expression: /{$pattern}/: Invalid property name",
                                    );
                                }
                                // Properties of strings (\q-eligible) only
                                // legal in v-mode.
                                if (
                                    \Phasis\Runtime\Interpreter::isVStringBinaryPropertyPublic($propExpr)
                                ) {
                                    if (!$isVFlag) {
                                        throw new \Phasis\Exceptions\SyntaxError(
                                            "Invalid regular expression: /{$pattern}/: Property of strings only allowed in /v",
                                        );
                                    }
                                    // Even in /v, a string-property may not
                                    // be negated; \P{Emoji_Keycap_Sequence}
                                    // is an early error.
                                    if ($next === 'P') {
                                        throw new \Phasis\Exceptions\SyntaxError(
                                            "Invalid regular expression: /{$pattern}/: \\P{} property of strings cannot be negated",
                                        );
                                    }
                                }
                            }
                            $i = $j + 1;
                            $prevAtom = true;
                            continue;
                        }
                        if ($unicode) {
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Unterminated property",
                            );
                        }
                    } elseif ($unicode) {
                        // In u-mode, \p / \P MUST be followed by `{...}`.
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid escape",
                        );
                    }
                }
                $i += 2;
                $prevAtom = true;
                continue;
            }
            if (!$inClass && $c === '[') {
                $lastClosedGroupWasLookbehind = false;
                $lastClosedGroupWasLookahead = false;
                if ($unicode) {
                    $endPos = self::validateCharClassUnicode($pattern, $i, $isVFlag);
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
                // Opening a new group resets the
                // "directly-follows-lookbehind" flag.
                $lastClosedGroupWasLookbehind = false;
                $lastClosedGroupWasLookahead = false;
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
                    $decodedName = self::decodeRegExpGroupName($name);
                    if (
                        $j >= $len || $decodedName === '' || $decodedName === null
                        || !self::isValidGroupName($decodedName)
                    ) {
                        throw new \Phasis\Exceptions\SyntaxError(
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
                    throw new \Phasis\Exceptions\SyntaxError(
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
                $lastClosedGroupWasLookbehind = false;
                $lastClosedGroupWasLookahead = false;
                $i++;
                $groupOpen++;
                $groupKindStack[] = 'normal';
                continue;
            }
            if (!$inClass && $c === ')') {
                if ($groupOpen === 0) {
                    throw new \Phasis\Exceptions\SyntaxError(
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
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Nothing to repeat",
                    );
                }
                if ($lastClosedGroupWasLookbehind) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Lookbehind cannot be quantified",
                    );
                }
                if ($lastClosedGroupWasLookahead && $unicode) {
                    throw new \Phasis\Exceptions\SyntaxError(
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
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Nothing to repeat",
                            );
                        }
                        if ($lastClosedGroupWasLookbehind) {
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Lookbehind cannot be quantified",
                            );
                        }
                        if ($lastClosedGroupWasLookahead && $unicode) {
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Lookahead cannot be quantified in unicode mode",
                            );
                        }
                        if ($hasComma && $second !== '' && (int) $first > (int) $second) {
                            throw new \Phasis\Exceptions\SyntaxError(
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
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Lone quantifier brackets",
                    );
                }
            }
            if (!$inClass && $c === '|') {
                $prevAtom = false;
                $lastClosedGroupWasLookbehind = false;
                $lastClosedGroupWasLookahead = false;
                $i++;
                continue;
            }
            // u-mode forbids stray `}` and `]` outside a quantifier or
            // character class. /v allows nested character classes whose
            // brackets land here during the structural pass; the v-flag
            // transform handles them later.
            if ($unicode && !$isVFlag && !$inClass && ($c === '}' || $c === ']')) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Invalid regular expression: /{$pattern}/: Lone quantifier brackets",
                );
            }
            // Any other character is an atom; the lookbehind-quantifier
            // restriction only fires when a quantifier IMMEDIATELY follows
            // the lookbehind close, so consume the flag here.
            $lastClosedGroupWasLookbehind = false;
            $lastClosedGroupWasLookahead = false;
            $prevAtom = !$inClass;
            $i++;
        }
        if ($inClass) {
            throw new \Phasis\Exceptions\SyntaxError(
                "Invalid regular expression: /{$pattern}/: Unterminated character class",
            );
        }
        if ($groupOpen !== 0) {
            throw new \Phasis\Exceptions\SyntaxError(
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
        // Only `\\`, `[`, `]`, `(` change state — bulk-skip everything else
        // so a 16 MiB \u{…} body doesn't burn O(N) iterations of $i++.
        $stopOpen = "\\[(";
        $stopClass = "\\]";
        while ($i < $len) {
            $c = $pattern[$i];
            if ($inClass) {
                if ($c !== '\\' && $c !== ']') {
                    $skip = strcspn($pattern, $stopClass, $i);
                    if ($skip > 0) {
                        $i += $skip;
                        continue;
                    }
                }
            } elseif ($c !== '\\' && $c !== '[' && $c !== '(') {
                $skip = strcspn($pattern, $stopOpen, $i);
                if ($skip > 0) {
                    $i += $skip;
                    continue;
                }
            }
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
     * Detect whether `pattern` contains a GroupSpecifier `(?<Name>…)` whose
     * name starts with a valid identifier character. Unlike
     * collectRegExpGroupNamesAndKRefs this does not swallow `(?<` inside an
     * attempted `\k<…>` name, so it is reliable for the +N grammar
     * lookahead.
     */
    private static function regexpHasNamedGroupDeclaration(string $pattern): bool
    {
        $len = strlen($pattern);
        $i = 0;
        $inClass = false;
        $stopOpen = "\\[(";
        $stopClass = "\\]";
        while ($i < $len) {
            $c = $pattern[$i];
            if ($inClass) {
                if ($c !== '\\' && $c !== ']') {
                    $skip = strcspn($pattern, $stopClass, $i);
                    if ($skip > 0) {
                        $i += $skip;
                        continue;
                    }
                }
            } elseif ($c !== '\\' && $c !== '[' && $c !== '(') {
                $skip = strcspn($pattern, $stopOpen, $i);
                if ($skip > 0) {
                    $i += $skip;
                    continue;
                }
            }
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
            if (
                !$inClass
                && $c === '('
                && $i + 3 < $len
                && $pattern[$i + 1] === '?'
                && $pattern[$i + 2] === '<'
            ) {
                $third = $pattern[$i + 3];
                if ($third !== '=' && $third !== '!') {
                    return true;
                }
            }
            $i++;
        }
        return false;
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
        $stopOpen = "\\[(";
        $stopClass = "\\]";
        while ($i < $len) {
            $c = $pattern[$i];
            if ($inClass) {
                if ($c !== '\\' && $c !== ']') {
                    $skip = strcspn($pattern, $stopClass, $i);
                    if ($skip > 0) {
                        $i += $skip;
                        continue;
                    }
                }
            } elseif ($c !== '\\' && $c !== '[' && $c !== '(') {
                $skip = strcspn($pattern, $stopOpen, $i);
                if ($skip > 0) {
                    $i += $skip;
                    continue;
                }
            }
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
    private static function validateCharClassUnicode(string $pattern, int $start, bool $isVFlag = false): int
    {
        $len = strlen($pattern);
        $i = $start + 1;
        $isNegated = false;
        if ($i < $len && $pattern[$i] === '^') {
            $isNegated = true;
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
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: \\ at end of pattern",
                    );
                }
                $next = $pattern[$i + 1];
                if (in_array($next, ['d', 'D', 's', 'S', 'w', 'W', 'p', 'P'], true)) {
                    // `\d-X` is invalid as a class range in u-mode. In v-mode
                    // `\d--X` is the set-difference operator, not a range, so
                    // we must not reject the leading `-` then.
                    if ($i + 2 < $len && $pattern[$i + 2] === '-' && $i + 3 < $len && $pattern[$i + 3] !== ']') {
                        $isVDifference = $isVFlag && $pattern[$i + 3] === '-';
                        if (!$isVDifference) {
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid character class",
                            );
                        }
                    }
                    // Skip past possible \p{Property}.
                    if ($next === 'p' || $next === 'P') {
                        if ($i + 2 < $len && $pattern[$i + 2] === '{') {
                            $j = $i + 3;
                            while ($j < $len && $pattern[$j] !== '}') {
                                $j++;
                            }
                            if ($j >= $len) {
                                throw new \Phasis\Exceptions\SyntaxError(
                                    "Invalid regular expression: /{$pattern}/: Invalid Unicode property",
                                );
                            }
                            $propExpr = substr($pattern, $i + 3, $j - ($i + 3));
                            if ($propExpr === '') {
                                throw new \Phasis\Exceptions\SyntaxError(
                                    "Invalid regular expression: /{$pattern}/: Empty property name",
                                );
                            }
                            if (str_contains($propExpr, '=')) {
                                $parts = explode('=', $propExpr, 2);
                                if ($parts[0] === '' || $parts[1] === '') {
                                    throw new \Phasis\Exceptions\SyntaxError(
                                        "Invalid regular expression: /{$pattern}/: Invalid property name",
                                    );
                                }
                                if (\Phasis\Runtime\Interpreter::isBinaryUnicodePropertyName($parts[0])) {
                                    throw new \Phasis\Exceptions\SyntaxError(
                                        "Invalid regular expression: /{$pattern}/: Binary property used with value",
                                    );
                                }
                            } else {
                                if (\Phasis\Runtime\Interpreter::isLoneUnicodePropertyKnown($propExpr) === false) {
                                    throw new \Phasis\Exceptions\SyntaxError(
                                        "Invalid regular expression: /{$pattern}/: Invalid property name",
                                    );
                                }
                                if (
                                    \Phasis\Runtime\Interpreter::isVStringBinaryPropertyPublic($propExpr)
                                ) {
                                    if (!$isVFlag) {
                                        throw new \Phasis\Exceptions\SyntaxError(
                                            "Invalid regular expression: /{$pattern}/: Property of strings only allowed in /v",
                                        );
                                    }
                                    if ($next === 'P' || $isNegated) {
                                        throw new \Phasis\Exceptions\SyntaxError(
                                            "Invalid regular expression: /{$pattern}/: Negated property of strings",
                                        );
                                    }
                                }
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
                                throw new \Phasis\Exceptions\SyntaxError(
                                    "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                                );
                            }
                            $hex .= $pattern[$j];
                            $j++;
                        }
                        if ($j >= $len || $hex === '' || hexdec($hex) > 0x10FFFF) {
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                            );
                        }
                        $i = $j + 1;
                        $prevWasClassEscape = false;
                        continue;
                    }
                    if ($i + 5 >= $len) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                        );
                    }
                    for ($k = 2; $k < 6; $k++) {
                        if (!ctype_xdigit($pattern[$i + $k])) {
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Invalid Unicode escape",
                            );
                        }
                    }
                    $i += 6;
                    $prevWasClassEscape = false;
                    continue;
                }
                // /v `\q{…}` — string-literal class escape. Skip past
                // the body; the v-flag transform turns it into an
                // alternation later.
                if ($isVFlag && $next === 'q') {
                    if ($i + 2 < $len && $pattern[$i + 2] === '{') {
                        $j = $i + 3;
                        while ($j < $len && $pattern[$j] !== '}') {
                            $j++;
                        }
                        if ($j >= $len) {
                            throw new \Phasis\Exceptions\SyntaxError(
                                "Invalid regular expression: /{$pattern}/: Unterminated \\q{}",
                            );
                        }
                        $i = $j + 1;
                        $prevWasClassEscape = true;
                        continue;
                    }
                }
                // Identity escape: only specific chars allowed in u-mode.
                static $allowedClassIdEscape = ['^', '$', '.', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|', '/', '\\', '-',
                    'b', 'B', 'f', 'n', 'r', 't', 'v', '0', 'c', 'x', 'k'];
                if (!in_array($next, $allowedClassIdEscape, true)) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Invalid escape",
                    );
                }
                // \xNN inside character class also needs 2 hex digits in u-mode.
                if ($next === 'x') {
                    $hex1 = $i + 2 < $len ? $pattern[$i + 2] : '';
                    $hex2 = $i + 3 < $len ? $pattern[$i + 3] : '';
                    if (!ctype_xdigit($hex1) || !ctype_xdigit($hex2)) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid \\x escape",
                        );
                    }
                }
                $i += 2;
                $prevWasClassEscape = false;
                continue;
            }
            if ($c === '-') {
                // Check if this `-` introduces a range whose right side is a
                // class-escape. Look ahead.
                $next = $i + 1 < $len ? $pattern[$i + 1] : '';
                // /v: `--` is the set-difference operator and must skip
                // these range-validity checks.
                $isVDifference = $isVFlag && $next === '-';
                if (!$isVDifference && $prevWasClassEscape && $next !== ']') {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Invalid character class range",
                    );
                }
                if (!$isVDifference && $next === '\\') {
                    $nn = $i + 2 < $len ? $pattern[$i + 2] : '';
                    if (in_array($nn, ['d', 'D', 's', 'S', 'w', 'W', 'p', 'P'], true)) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid character class range",
                        );
                    }
                }
                if ($isVDifference) {
                    // Skip past the `--` operator.
                    $i += 2;
                    $prevWasClassEscape = false;
                    continue;
                }
                // /v: `-` is only legal in range position. A lone `-`
                // between class atoms (e.g. `[-]`, `[a-]`) is rejected.
                if ($isVFlag) {
                    $prevChar = $i > $start + 1 ? $pattern[$i - 1] : '[';
                    $isClassStart = ($prevChar === '[' || $prevChar === '^');
                    $isClassEnd = ($next === ']' || $next === '');
                    if ($isClassStart && $isClassEnd) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Lone hyphen in /v class",
                        );
                    }
                }
                $prevWasClassEscape = false;
                $i++;
                continue;
            }
            // /v ClassSetSyntaxCharacter must be escaped:
            // `( ) { } / |` are reserved as set operators / quantifier
            // anchors and must appear escaped. `[` opens a nested
            // ClassSetExpression (handled by the v-flag transform); `]`
            // is already the class terminator.
            if ($isVFlag && $c === '[') {
                // Nested class: skip past its body. This validator
                // doesn't recurse, so just consume balanced brackets.
                $depth = 1;
                $i++;
                while ($i < $len) {
                    $cc = $pattern[$i];
                    if ($cc === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($cc === '[') {
                        $depth++;
                    } elseif ($cc === ']') {
                        $depth--;
                        if ($depth === 0) {
                            $i++;
                            break;
                        }
                    }
                    $i++;
                }
                $prevWasClassEscape = true;
                continue;
            }
            if ($isVFlag && in_array($c, ['(', ')', '{', '}', '/', '|'], true)) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Invalid regular expression: /{$pattern}/: Unescaped class-set syntax character",
                );
            }
            // /v ClassSetReservedDoublePunctuator: a punctuation char
            // immediately repeated as a bare class atom must be
            // escaped. `&&` is the intersection operator when between
            // subclasses, so we exempt it here; any of the other
            // doubled punctuators in this position are rejected.
            static $vDoublePunct = ['!', '#', '$', '%', '*', '+', ',', '.', ':', ';', '<', '=', '>', '?', '@', '`', '~', '^'];
            if (
                $isVFlag
                && $i + 1 < $len
                && $pattern[$i + 1] === $c
                && in_array($c, $vDoublePunct, true)
            ) {
                throw new \Phasis\Exceptions\SyntaxError(
                    "Invalid regular expression: /{$pattern}/: Reserved class-set double punctuator",
                );
            }
            // /v `&&` is the intersection operator and requires non-empty
            // operands on both sides. `[&&]`, `[&&a]`, `[a&&]` are syntax
            // errors per spec.
            if (
                $isVFlag
                && $c === '&'
                && $i + 1 < $len
                && $pattern[$i + 1] === '&'
            ) {
                $prevChar = $i > $start + 1 ? $pattern[$i - 1] : '[';
                $isClassStart = ($prevChar === '[' || $prevChar === '^');
                $afterIdx = $i + 2;
                $afterChar = $afterIdx < $len ? $pattern[$afterIdx] : ']';
                $isClassEnd = ($afterChar === ']');
                if ($isClassStart || $isClassEnd) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Empty operand for class-set intersection",
                    );
                }
            }
            $prevWasClassEscape = false;
            $i++;
        }
        throw new \Phasis\Exceptions\SyntaxError(
            "Invalid regular expression: /{$pattern}/: Unterminated character class",
        );
    }

    /**
     * Decode \uXXXX and \u{X..} escape sequences in a regex group name
     * to their UTF-8 form so the IdentifierName check sees actual letters.
     * Returns null if the escape sequence is malformed.
     */
    private static function decodeRegExpGroupName(string $name): ?string
    {
        $len = strlen($name);
        $result = '';
        $i = 0;
        while ($i < $len) {
            $c = $name[$i];
            if ($c === '\\' && $i + 1 < $len && $name[$i + 1] === 'u') {
                if ($i + 2 < $len && $name[$i + 2] === '{') {
                    $end = strpos($name, '}', $i + 3);
                    if ($end === false) {
                        return null;
                    }
                    $hex = substr($name, $i + 3, $end - ($i + 3));
                    if ($hex === '' || !ctype_xdigit($hex)) {
                        return null;
                    }
                    $cp = (int) hexdec($hex);
                    if ($cp > 0x10FFFF) {
                        return null;
                    }
                    // Per spec, lone surrogates (U+D800..U+DFFF) are not
                    // valid GroupName code points; refuse the whole name
                    // here so a SyntaxError surfaces at parse time
                    // (test262 language/literals/regexp/named-groups/
                    // invalid-non-id-start-groupspecifier-*). Doing the
                    // range check upfront also avoids depending on
                    // mb_chr's documented `string|false` return when the
                    // PHPStan stub narrows it to plain `string`.
                    if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                        return null;
                    }
                    $result .= mb_chr($cp, 'UTF-8');
                    $i = $end + 1;
                    continue;
                }
                if ($i + 5 < $len) {
                    $hex = substr($name, $i + 2, 4);
                    if (strlen($hex) === 4 && ctype_xdigit($hex)) {
                        $cp = (int) hexdec($hex);
                        // Combine surrogate pairs: a high surrogate
                        // followed by another \uXXXX low surrogate forms a
                        // single supplementary code point.
                        if (
                            $cp >= 0xD800 && $cp <= 0xDBFF
                            && $i + 11 < $len
                            && $name[$i + 6] === '\\' && $name[$i + 7] === 'u'
                        ) {
                            $loHex = substr($name, $i + 8, 4);
                            if (strlen($loHex) === 4 && ctype_xdigit($loHex)) {
                                $lo = (int) hexdec($loHex);
                                if ($lo >= 0xDC00 && $lo <= 0xDFFF) {
                                    $cp = 0x10000 + (($cp - 0xD800) << 10) + ($lo - 0xDC00);
                                    // Combined codepoint is in the
                                    // supplementary plane and round-trips
                                    // through UTF-8 cleanly.
                                    $result .= mb_chr($cp, 'UTF-8');
                                    $i += 12;
                                    continue;
                                }
                            }
                        }
                        // Same surrogate-rejection rule as the \u{...} arm:
                        // lone surrogates are not valid GroupName code
                        // points; refusing here surfaces a SyntaxError.
                        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                            return null;
                        }
                        $result .= mb_chr($cp, 'UTF-8');
                        $i += 6;
                        continue;
                    }
                }
                return null;
            }
            $result .= $c;
            $i++;
        }
        return $result;
    }

    private static function isValidGroupName(string $name): bool
    {
        if ($name === '') {
            return false;
        }
        // GroupName accepts the full IdentifierName grammar — including
        // non-ASCII letters/digits, plus ZWJ/ZWNJ as IdentifierPart.
        if (
            preg_match(
                '/^[\p{L}\p{Nl}_$][\p{L}\p{Nl}\p{Mn}\p{Mc}\p{Nd}\p{Pc}_$\x{200C}\x{200D}]*$/u',
                $name,
            ) !== 1
        ) {
            return false;
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
            $this->stringsWithLegacyOctal->offsetSet($literal, null);
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
    private function parseAwaitAsIdentifier(\Phasis\Lexer\Token $token): Node
    {
        if (
            $this->inAsync
            || $this->moduleMode
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
    private function parseYieldAsIdentifier(\Phasis\Lexer\Token $token): Node
    {
        if ($this->strictMode || $this->inGenerator) {
            throw new ParseError(
                "Unexpected reserved word 'yield'",
                $token,
            );
        }
        // The lexer pessimistically tokenized `/` after `yield` as the
        // start of a RegExp literal because Yield is not in its
        // value-producing list. Outside generator scope `yield` is an
        // identifier, so `/` is division — re-lex the RegExp token in
        // place before it confuses the postfix/operator parser.
        if (($this->tokens[$this->pos + 1] ?? null)?->type === TokenType::RegExp) {
            $this->reinterpretRegExpAsDivision($this->pos + 1);
        }
        return $this->parseIdentifierExpression();
    }

    private function parseIdentifierExpression(): Node
    {
        $token = $this->current();
        $this->advance();

        // async arrow: async (params) => body or async ident => body.
        // Per spec, contextual keyword `async` cannot be lexed via escapes:
        // async is just an Identifier and must not start an
        // AsyncFunction/AsyncArrowFunction production.
        if (
            $token->value === 'async'
            && $token->rawValue !== 'escaped'
            && !$this->current()->lineTerminatorBefore
        ) {
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
        // `let` and `static` are reserved as IdentifierReference in strict
        // mode. Per §13.13, LabelIdentifier is BindingIdentifier so the
        // strict-mode reserved-word check applies to label names too.
        if (
            $this->strictMode
            && ($token->value === 'let' || $token->value === 'static' || $token->value === 'yield')
        ) {
            throw new ParseError(
                "Unexpected reserved word '{$token->value}'",
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
        $this->parenthesized->offsetSet($expr, null);

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
            if (self::containsYieldOrAwaitExpression($p)) {
                throw new ParseError(
                    'YieldExpression or AwaitExpression not permitted in arrow function parameters',
                    new \Phasis\Lexer\Token(TokenType::Identifier, '', $location),
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
                    self::walkForSuperCallOnly($p);
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
                self::walkForSuperCallOnly($p);
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
                        throw new \Phasis\Exceptions\SyntaxError(
                            'Rest element must be last element',
                        );
                    }
                    if ($el->argument instanceof AssignmentPattern) {
                        throw new \Phasis\Exceptions\SyntaxError(
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
                        throw new \Phasis\Exceptions\SyntaxError(
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
            throw new \Phasis\Exceptions\SyntaxError(
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
            $this->arrayExpressionsWithTrailingCommaAfterRest->offsetSet($expr, null);
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
                    throw new \Phasis\Exceptions\SyntaxError(
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
                    if (self::containsYieldOrAwaitExpression($p)) {
                        throw new ParseError(
                            $isGenerator
                                ? 'YieldExpression not permitted in generator parameters'
                                : 'AwaitExpression not permitted in async method parameters',
                            new \Phasis\Lexer\Token(TokenType::Identifier, '', $location),
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
            foreach ($params as $p) {
                if (self::containsYieldOrAwaitExpression($p)) {
                    throw new ParseError(
                        $isGenerator
                            ? 'YieldExpression not permitted in generator parameters'
                            : 'AwaitExpression not permitted in async method parameters',
                        new \Phasis\Lexer\Token(TokenType::Identifier, '', $location),
                    );
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
            $errToken = new \Phasis\Lexer\Token(TokenType::Identifier, '', $location);
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
                if ($params[0] instanceof \Phasis\Ast\Pattern\RestElement) {
                    throw new ParseError(
                        'Setter parameter must not be a rest element',
                        $errToken,
                    );
                }
                if (
                    $params[0] instanceof \Phasis\Ast\Pattern\AssignmentPattern
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
                    new \Phasis\Lexer\Token(TokenType::Identifier, $key->name, $key->location),
                );
            }
            // The shorthand value side is an IdentifierReference, which can
            // never be a reserved word (null/true/false/this/etc.).
            if (self::isReservedWordIdentifierName($key->name)) {
                throw new ParseError(
                    "Unexpected reserved word '{$key->name}' as shorthand property",
                    new \Phasis\Lexer\Token(TokenType::Identifier, $key->name, $key->location),
                );
            }
            // The shorthand value side is an IdentifierReference, which in
            // strict mode rejects FutureReservedWords (package, private,
            // protected, etc.) as well as reserved keywords.
            if ($this->strictMode && self::isStrictReservedWordIdentifier($key->name)) {
                throw new ParseError(
                    "Unexpected reserved word '{$key->name}'",
                    new \Phasis\Lexer\Token(TokenType::Identifier, $key->name, $key->location),
                );
            }
            // 'eval' and 'arguments' as shorthand here are IdentifierReferences
            // — valid in strict mode. They become BindingIdentifiers only if
            // the surrounding ObjectExpression is later refined into an
            // AssignmentPattern, in which case
            // validateAssignmentTargetObjectShorthand throws.
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
                    new \Phasis\Lexer\Token(TokenType::Await, $key->name, $key->location),
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
                    new \Phasis\Lexer\Token(TokenType::Yield, $key->name, $key->location),
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
            // Decode non-decimal bases — (float) and (int) casts on a string
            // like "0xff" return 0, so the property key would be "0" rather
            // than "255". Mirror parseNumericLiteral's base handling.
            if (str_starts_with($raw, '0x') || str_starts_with($raw, '0X')) {
                $value = hexdec($raw);
            } elseif (str_starts_with($raw, '0o') || str_starts_with($raw, '0O')) {
                $value = octdec(substr($raw, 2));
            } elseif (str_starts_with($raw, '0b') || str_starts_with($raw, '0B')) {
                $value = bindec(substr($raw, 2));
            } else {
                $value = (float) $raw;
                if ($value == (int) $value && !str_contains($raw, '.') && !str_contains($raw, 'e') && !str_contains($raw, 'E')) {
                    $value = (int) $raw;
                }
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

        // Per spec FunctionExpression : `function` BindingIdentifier? `(` ... `)` `{` ... `}`
        // The BindingIdentifier slot uses [~Yield, ~Await], so within an
        // enclosing generator/async function the name `yield` / `await` may
        // still appear here as an identifier. Set the parser context BEFORE
        // parsing the name so parseIdentifier's reserved-word check honours
        // the inner function's context.
        $prevGenerator = $this->inGenerator;
        $prevAsync = $this->inAsync;
        $prevTopLevel = $this->topLevel;
        $prevStaticBlock = $this->inStaticBlock;
        $this->inGenerator = $generator;
        $this->inAsync = false;
        $this->topLevel = false;
        $this->inStaticBlock = false;

        // Function expression name is an optional BindingIdentifier. Per spec,
        // it may be any IdentifierReference allowed in this context: regular
        // Identifier, Yield (sloppy non-generator), Await (sloppy non-async),
        // Let, Static_, Of, Async (which are tokenized separately but valid
        // as identifiers in many contexts). Reserved words like `enum` are
        // rejected via parseIdentifier's validation.
        if (
            $this->check(TokenType::Identifier)
            || $this->check(TokenType::Yield)
            || $this->check(TokenType::Await)
            || $this->check(TokenType::Let)
            || $this->check(TokenType::Static_)
            || $this->check(TokenType::Of)
            || $this->check(TokenType::Async)
        ) {
            $name = $this->parseIdentifier()->name;
        }
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
                if (self::containsYieldOrAwaitExpression($p)) {
                    throw new ParseError(
                        'YieldExpression not permitted in generator parameters',
                        new \Phasis\Lexer\Token(TokenType::Identifier, '', $location),
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

        // Per spec, contextual keyword `async` cannot be lexed via Unicode
        // escapes. When the token is escaped (`async`), it is just
        // the IdentifierReference `async` — never the AsyncFunction or
        // AsyncArrowFunction head. Skip all keyword-context branches and
        // fall through to the identifier path.
        if ($token->rawValue === 'escaped') {
            $this->advance(); // consume the Async token as identifier
            return new Identifier($token->location, 'async');
        }

        // async function
        if ($next->type === TokenType::Function_ && !$next->lineTerminatorBefore) {
            $location = $this->advance()->location;
            return $this->parseAsyncFunctionExpression($location);
        }

        // async (params) => body
        // Disambiguate: `async(args)` followed by `=>` is an arrow function,
        // otherwise it's a call to the variable `async`. Look ahead for the
        // matching `)` and check whether `=>` follows; if not, fall through
        // to identifier handling so the standard call-expression machinery
        // runs (sm/async-functions/async-contains-unicode-escape.js with
        // `var async = 0; async(obj);`).
        if ($next->type === TokenType::LeftParen && !$next->lineTerminatorBefore) {
            $matchPos = $this->pos + 1; // index of LeftParen
            $depth = 0;
            $end = null;
            for ($i = $matchPos; $i < count($this->tokens); $i++) {
                $tType = $this->tokens[$i]->type;
                if ($tType === TokenType::LeftParen) {
                    $depth++;
                } elseif ($tType === TokenType::RightParen) {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                } elseif ($tType === TokenType::EOF) {
                    break;
                }
            }
            $followedByArrow = $end !== null
                && isset($this->tokens[$end + 1])
                && $this->tokens[$end + 1]->type === TokenType::Arrow
                && !$this->tokens[$end + 1]->lineTerminatorBefore;
            if ($followedByArrow) {
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
                throw new ParseError('Expected =>', $this->current());
            }
            // No arrow follows: treat `async` as the IdentifierReference and
            // let the call-expression path handle `(args)` as arguments.
            $this->advance();
            return new Identifier($token->location, 'async');
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
            if (self::containsYieldOrAwaitExpression($p)) {
                throw new ParseError(
                    'YieldExpression or AwaitExpression not permitted in async/generator parameters',
                    new \Phasis\Lexer\Token(TokenType::Identifier, '', $location),
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
            } elseif ($this->check(TokenType::OptionalChaining)) {
                // ?. is part of LeftHandSideExpression per ES2020+
                // (e.g. valid as ClassHeritage: `class C extends a?.b {}`).
                if ($expr instanceof Identifier && $expr->name === 'super') {
                    throw new ParseError(
                        'Invalid optional chain from super property',
                        $this->current(),
                    );
                }
                if ($expr instanceof NewExpression && !$expr->hasArguments) {
                    throw new ParseError(
                        'Invalid optional chain from new expression',
                        $this->current(),
                    );
                }
                $this->advance();
                if ($this->check(TokenType::LeftBracket)) {
                    $this->advance();
                    $property = $this->parseExpression();
                    $this->expect(TokenType::RightBracket);
                    $expr = new MemberExpression($expr->location, $expr, $property, true, true);
                } elseif ($this->check(TokenType::LeftParen)) {
                    $this->advance();
                    $args = $this->parseArguments();
                    $expr = new CallExpression($expr->location, $expr, $args, true);
                } else {
                    $property = $this->parseIdentifierOrKeyword();
                    $expr = new MemberExpression($expr->location, $expr, $property, false, true);
                }
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
                $callee instanceof \Phasis\Ast\Expression\ImportExpression
                && !$this->parenthesized->offsetExists($callee)
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
        $hasArgs = false;
        if ($this->eat(TokenType::LeftParen)) {
            $hasArgs = true;
            $args = $this->parseArguments();
        }

        return new NewExpression($location, $callee, $args, $hasArgs);
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
            // ES2024+ proposals: `import.source(x)` and `import.defer(x)`.
            // Track the requested phase on the AST. For SourceTextModule,
            // GetModuleSource always returns an abrupt SyntaxError (per
            // 16.2.1.7.2), so the runtime rejects the returned promise.
            if (
                $prop->type === TokenType::Identifier
                && ($prop->value === 'source' || $prop->value === 'defer')
            ) {
                $phase = $prop->value;
                $this->advance();
                if (!$this->check(TokenType::LeftParen)) {
                    throw new ParseError(
                        'Expected "(" after "import.' . $phase . '"',
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
                return new \Phasis\Ast\Expression\ImportExpression(
                    $location,
                    $source,
                    $options,
                    $phase,
                );
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
}
