<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Ast\Declaration\ClassDeclaration;
use PhpJs\Ast\Declaration\FunctionDeclaration;
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
use PhpJs\Ast\Expression\ConditionalExpression;
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
use PhpJs\Ast\Expression\TaggedTemplate;
use PhpJs\Ast\Expression\TemplateLiteral;
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
use PhpJs\Exceptions\InternalError;
use PhpJs\Exceptions\ReferenceError;
use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Spec\AbstractOperations;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBigInt;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\GeneratorReturnSignal;
use PhpJs\Value\GeneratorThrowSignal;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsGenerator;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsOptionalUndefined;
use PhpJs\Value\JsString;
use PhpJs\Value\JsSymbol;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class Interpreter
{
    private CallStack $callStack;
    private int $maxLoopIterations;
    private bool $strictMode = false;

    /**
     * Map of with-environment identity to their binding objects.
     * Used for spec-correct binding resolution (ResolveBinding before Initializer).
     * Keys are spl_object_id of the Environment, values are the JsObject.
     * @var array<int, JsObject>
     */
    private array $withEnvObjects = [];


    public function __construct(
        private Environment $globalEnv,
        ?CallStack $callStack = null,
        int $maxLoopIterations = 100000,
    ) {
        $this->callStack = $callStack ?? new CallStack();
        $this->maxLoopIterations = $maxLoopIterations;

        // Register interpreter callback so JsFunction::call() works for non-native functions
        JsFunction::setInterpreterCallback(function (JsFunction $fn, JsValue $thisValue, array $args): JsValue {
            return $this->callFunction($fn, $thisValue, $args);
        });
    }

    public function setMaxLoopIterations(int $limit): void
    {
        $this->maxLoopIterations = $limit;
    }

    public function isStrictMode(): bool
    {
        return $this->strictMode;
    }

    /** Check whether a list of statements begins with a "use strict" directive. */
    private function hasUseStrictDirective(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if (!$stmt instanceof ExpressionStatement) {
                break;
            }
            $expr = $stmt->expression;
            if ($expr instanceof Literal && is_string($expr->value) && $expr->value === 'use strict') {
                // Per spec, the directive must be the exact token "use strict" or 'use strict'
                // with no escape sequences or line continuations. The verbatim flag is set by
                // the lexer when the string contained no backslash escapes.
                if ($expr->verbatim) {
                    return true;
                }
            }
            // Only string literal expression statements can be directives.
            if (!$expr instanceof Literal || !is_string($expr->value)) {
                break;
            }
        }
        return false;
    }

    public function execute(Program $program): JsValue
    {
        // Detect strict mode from directive prologue.
        if ($this->hasUseStrictDirective($program->body)) {
            $this->strictMode = true;
        }

        // Validate strict mode restrictions (reserved words, with, etc.)
        // so that indirect eval with 'use strict' code gets checked.
        if ($this->strictMode) {
            $this->validateStrictModeRestrictions($program->body);
        }

        $this->validateGlobalLexDecls($program->body);
        $this->hoistDeclarations($program->body, $this->globalEnv);
        $this->hoistEvalLexicalDeclarations($program->body, $this->globalEnv);
        return $this->executeStatements($program->body, $this->globalEnv);
    }

    /**
     * Per GlobalDeclarationInstantiation step 5c/5d: lexical declarations
     * must not collide with restricted (non-configurable) global properties.
     *
     * @param Node[] $body
     */
    private function validateGlobalLexDecls(array $body): void
    {
        $globalObj = $this->globalEnv->getLinkedObject();
        if ($globalObj === null) {
            return;
        }

        // Collect lexical names (let/const/class declarations).
        $lexNames = [];
        foreach ($body as $stmt) {
            if ($stmt instanceof VariableDeclaration && ($stmt->kind === 'let' || $stmt->kind === 'const')) {
                foreach ($stmt->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $n) {
                        $lexNames[] = $n;
                    }
                }
            } elseif ($stmt instanceof ClassDeclaration && $stmt->id !== null) {
                $lexNames[] = $stmt->id->name;
            }
        }

        // Per spec step 5: for each lexical name, check:
        //   a. HasVarDeclaration or HasLexicalDeclaration => SyntaxError
        //   c/d. HasRestrictedGlobalProperty => SyntaxError
        foreach ($lexNames as $name) {
            // Check for existing lexical binding in the global environment.
            if ($this->globalEnv->hasLexicalBinding($name)) {
                $this->throwJsValue(
                    $this->phpExceptionToJsValue(
                        new \PhpJs\Exceptions\SyntaxError(
                            "Identifier '{$name}' has already been declared",
                        ),
                    ),
                );
            }
            // Check for restricted global property (non-configurable).
            if ($globalObj->hasOwnProperty($name)) {
                $desc = $globalObj->getOwnPropertyDescriptor($name);
                if ($desc !== null && $desc->configurable === false) {
                    $this->throwJsValue(
                        $this->phpExceptionToJsValue(
                            new \PhpJs\Exceptions\SyntaxError(
                                "Identifier '{$name}' has already been declared",
                            ),
                        ),
                    );
                }
            }
        }

        // Collect var names.
        $varNames = [];
        foreach ($body as $stmt) {
            if ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $n) {
                        $varNames[] = $n;
                    }
                }
            } elseif ($stmt instanceof FunctionDeclaration) {
                $varNames[] = $stmt->id->name;
            }
        }

        // Per spec step 6: for each var name, check HasLexicalDeclaration.
        foreach ($varNames as $name) {
            if ($this->globalEnv->hasLexicalBinding($name)) {
                $this->throwJsValue(
                    $this->phpExceptionToJsValue(
                        new \PhpJs\Exceptions\SyntaxError(
                            "Identifier '{$name}' has already been declared",
                        ),
                    ),
                );
            }
        }

        // Per spec step 10: CanDeclareGlobalFunction for each function declaration.
        // Per spec step 12: CanDeclareGlobalVar for each var name.
        $isExtensible = $globalObj->isExtensible();

        // Collect function declaration names (in reverse order, last wins).
        $declaredFuncNames = [];
        for ($i = count($body) - 1; $i >= 0; $i--) {
            $stmt = $body[$i];
            if ($stmt instanceof FunctionDeclaration) {
                $fn = $stmt->id->name;
                if (!isset($declaredFuncNames[$fn])) {
                    $declaredFuncNames[$fn] = true;
                    // CanDeclareGlobalFunction check.
                    $existingProp = $globalObj->getOwnPropertyDescriptor($fn);
                    if ($existingProp === null) {
                        // No existing property: check extensibility.
                        if (!$isExtensible) {
                            $this->throwJsValue(
                                $this->phpExceptionToJsValue(
                                    new TypeError("Cannot define property {$fn}, object is not extensible"),
                                ),
                            );
                        }
                    } elseif (!$existingProp->configurable) {
                        // Non-configurable: must be data, writable, enumerable.
                        $isOk = $existingProp->isDataDescriptor()
                            && $existingProp->writable === true
                            && $existingProp->enumerable === true;
                        if (!$isOk) {
                            $this->throwJsValue(
                                $this->phpExceptionToJsValue(
                                    new TypeError(
                                        "Cannot redefine property: {$fn}",
                                    ),
                                ),
                            );
                        }
                    }
                }
            }
        }

        // CanDeclareGlobalVar for each var name not already a declared function.
        foreach ($body as $stmt) {
            if ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $vn) {
                        if (!isset($declaredFuncNames[$vn])) {
                            if (!$globalObj->hasOwnProperty($vn) && !$isExtensible) {
                                $this->throwJsValue(
                                    $this->phpExceptionToJsValue(
                                        new TypeError(
                                            "Cannot define property {$vn}, object is not extensible",
                                        ),
                                    ),
                                );
                            }
                        }
                    }
                }
            }
        }
    }

    /** @param Node[] $statements */
    private function executeStatements(array $statements, Environment $env): JsValue
    {
        $result = JsUndefined::instance();
        foreach ($statements as $stmt) {
            $completion = $this->executeStatement($stmt, $env);
            if ($completion->isAbrupt()) {
                return $this->handleAbrupt($completion);
            }
            // Empty completions (empty statement, debugger) don't override the result
            if (!$completion->empty) {
                $result = $completion->value;
            }
        }
        return $result;
    }

    /** @param Node[] $statements */
    public function executeBody(array $statements, Environment $env): Completion
    {
        $result = JsUndefined::instance();
        foreach ($statements as $stmt) {
            $completion = $this->executeStatement($stmt, $env);
            if ($completion->isAbrupt()) {
                // UpdateEmpty: if the abrupt completion has an empty value
                // (its value slot was never explicitly set), replace it with
                // the last non-empty statement value V. This implements the
                // spec's UpdateEmpty(completion, V) for break/continue.
                // Completions that already had their value filled (e.g. by
                // a try/finally UpdateEmpty) have empty=false and are kept.
                if ($completion->empty && !$result instanceof JsUndefined) {
                    return new Completion($completion->type, $result, $completion->target);
                }
                return $completion;
            }
            // Empty completions don't override the accumulated value
            if (!$completion->empty) {
                $result = $completion->value;
            }
        }
        return Completion::normal($result);
    }

    private function executeStatement(Node $node, Environment $env): Completion
    {
        return match (true) {
            $node instanceof ExpressionStatement => $this->execExpressionStatement($node, $env),
            $node instanceof VariableDeclaration => $this->execVariableDeclaration($node, $env),
            $node instanceof FunctionDeclaration => $this->execFunctionDeclaration($node, $env),
            $node instanceof ClassDeclaration => $this->execClassDeclaration($node, $env),
            $node instanceof BlockStatement => $this->execBlockStatement($node, $env),
            $node instanceof IfStatement => $this->execIfStatement($node, $env),
            $node instanceof ForStatement => $this->execForStatement($node, $env),
            $node instanceof ForInStatement => $this->execForInStatement($node, $env),
            $node instanceof ForOfStatement => $this->execForOfStatement($node, $env),
            $node instanceof WhileStatement => $this->execWhileStatement($node, $env),
            $node instanceof DoWhileStatement => $this->execDoWhileStatement($node, $env),
            $node instanceof SwitchStatement => $this->execSwitchStatement($node, $env),
            $node instanceof ReturnStatement => $this->execReturnStatement($node, $env),
            $node instanceof ThrowStatement => $this->execThrowStatement($node, $env),
            $node instanceof TryStatement => $this->execTryStatement($node, $env),
            $node instanceof BreakStatement => Completion::break($node->label),
            $node instanceof ContinueStatement => Completion::continue($node->label),
            $node instanceof LabeledStatement => $this->execLabeledStatement($node, $env),
            $node instanceof WithStatement => $this->execWithStatement($node, $env),
            $node instanceof EmptyStatement => new Completion(
                CompletionType::Normal,
                JsUndefined::instance(), empty: true,
            ),
            $node instanceof DebuggerStatement => Completion::normal(JsUndefined::instance()),
            default => throw new InternalError('Unknown statement type: ' . $node->type()),
        };
    }

    // -------------------------------------------------------------------------
    // Expression evaluation
    // -------------------------------------------------------------------------

    public function evaluate(Node $node, Environment $env): JsValue
    {
        $result = match (true) {
            $node instanceof Literal => $this->evalLiteral($node),
            $node instanceof Identifier => $this->evalIdentifier($node, $env),
            $node instanceof BinaryExpression => $this->evalBinaryExpression($node, $env),
            $node instanceof LogicalExpression => $this->evalLogicalExpression($node, $env),
            $node instanceof UnaryExpression => $this->evalUnaryExpression($node, $env),
            $node instanceof UpdateExpression => $this->evalUpdateExpression($node, $env),
            $node instanceof AssignmentExpression => $this->evalAssignment($node, $env),
            $node instanceof ConditionalExpression => $this->evalConditional($node, $env),
            $node instanceof CallExpression => $this->evalCallExpression($node, $env),
            $node instanceof MemberExpression => $this->evalMemberExpression($node, $env),
            $node instanceof NewExpression => $this->evalNewExpression($node, $env),
            $node instanceof ArrayExpression => $this->evalArrayExpression($node, $env),
            $node instanceof ObjectExpression => $this->evalObjectExpression($node, $env),
            $node instanceof ArrowFunction => $this->evalArrowFunction($node, $env),
            $node instanceof FunctionExpression => $this->evalFunctionExpression($node, $env),
            $node instanceof ClassExpression => $this->evalClassExpression($node, $env),
            $node instanceof ThisExpression => $this->evalThisExpression($env),
            $node instanceof SequenceExpression => $this->evalSequence($node, $env),
            $node instanceof TemplateLiteral => $this->evalTemplateLiteral($node, $env),
            $node instanceof TaggedTemplate => $this->evalTaggedTemplate($node, $env),
            $node instanceof SpreadElement => $this->evaluate($node->argument, $env),
            $node instanceof YieldExpression => $this->evalYieldExpression($node, $env),
            $node instanceof AwaitExpression => $this->evalAwaitExpression($node, $env),
            default => throw new InternalError('Unknown expression type: ' . $node->type()),
        };

        // Unwrap optional chain sentinel for non-chain consumers.
        // MemberExpression and CallExpression handle JsOptionalUndefined internally.
        if (
            $result instanceof JsOptionalUndefined
            && !($node instanceof MemberExpression)
            && !($node instanceof CallExpression)
        ) {
            return JsUndefined::instance();
        }

        return $result;
    }

    private function evalLiteral(Literal $node): JsValue
    {
        $value = $node->value;
        if ($value === null) {
            return JsNull::instance();
        }
        if (is_bool($value)) {
            return new JsBoolean($value);
        }
        if (is_int($value) || is_float($value)) {
            return new JsNumber((float) $value);
        }
        if (is_string($value)) {
            // BigInt literal: marked with __BIGINT__ prefix in raw by the parser.
            // Normalize to canonical decimal so strict equality and bcmath work
            // regardless of the source base (0x, 0b, 0o, decimal).
            if (str_starts_with($node->raw, '__BIGINT__')) {
                return new JsBigInt(self::parseBigIntLiteral(rtrim($value, 'n')));
            }
            // RegExp literal: only from actual RegExp tokens (marked with __REGEXP__ prefix in raw)
            if (
                str_starts_with($node->raw, '__REGEXP__')
                && preg_match('#^/(.+)/([dgimsuvy]*)$#s', $value, $m)
            ) {
                return $this->createRegExpObject($m[1], $m[2]);
            }
            return new JsString($value);
        }
        return JsUndefined::instance();
    }

    private function evalIdentifier(Identifier $node, Environment $env): JsValue
    {
        if ($node->name === 'undefined') {
            return JsUndefined::instance();
        }
        return $env->get($node->name);
    }

    private function evalBinaryExpression(BinaryExpression $node, Environment $env): JsValue
    {
        $left = $this->evaluate($node->left, $env);
        $right = $this->evaluate($node->right, $env);

        return match ($node->operator) {
            '+' => $this->addOperator($left, $right),
            '-' => $this->numericBinaryOp($left, $right, '-'),
            '*' => $this->numericBinaryOp($left, $right, '*'),
            '/' => $this->numericBinaryOp($left, $right, '/'),
            '%' => $this->numericBinaryOp($left, $right, '%'),
            '**' => $this->numericBinaryOp($left, $right, '**'),
            '==' => new JsBoolean(AbstractOperations::abstractEquals($left, $right)),
            '!=' => new JsBoolean(!AbstractOperations::abstractEquals($left, $right)),
            '===' => new JsBoolean(AbstractOperations::strictEquals($left, $right)),
            '!==' => new JsBoolean(!AbstractOperations::strictEquals($left, $right)),
            '<' => $this->relational($left, $right, '<'),
            '>' => $this->relational($right, $left, '>'),
            '<=' => $this->relational($right, $left, '<='),
            '>=' => $this->relational($left, $right, '>='),
            '<<' => $this->bitwiseShift($left, $right, '<<'),
            '>>' => $this->bitwiseShift($left, $right, '>>'),
            '>>>' => $this->bitwiseShift($left, $right, '>>>'),
            '&' => $this->bitwiseBinaryOp($left, $right, '&'),
            '|' => $this->bitwiseBinaryOp($left, $right, '|'),
            '^' => $this->bitwiseBinaryOp($left, $right, '^'),
            'in' => $this->evalInOperator($left, $right),
            'instanceof' => new JsBoolean(AbstractOperations::instanceofOperator($left, $right)),
            default => throw new InternalError("Unknown binary operator: {$node->operator}"),
        };
    }

    /**
     * 13.15.3 Addition operator (+).
     *
     * ToPrimitive both sides. If either is a string, concatenate.
     * Otherwise call ToNumeric on both. If types differ, throw TypeError.
     * BigInt + BigInt uses pure-PHP string arithmetic. Number + Number uses float addition.
     */
    private function addOperator(JsValue $left, JsValue $right): JsValue
    {
        $lprim = TypeConversion::toPrimitive($left);
        $rprim = TypeConversion::toPrimitive($right);

        // String concatenation takes priority.
        if ($lprim instanceof JsString || $rprim instanceof JsString) {
            return new JsString(
                TypeConversion::toString($lprim) . TypeConversion::toString($rprim),
            );
        }

        // ToNumeric: if the primitive is already BigInt, keep it; otherwise ToNumber.
        $lnum = TypeConversion::toNumeric($lprim);
        $rnum = TypeConversion::toNumeric($rprim);

        if ($lnum instanceof JsBigInt && $rnum instanceof JsBigInt) {
            return new JsBigInt(self::bigStrBcAdd($lnum->value, $rnum->value));
        }

        if ($lnum instanceof JsBigInt || $rnum instanceof JsBigInt) {
            throw new TypeError('Cannot mix BigInt and other types, use explicit conversions');
        }

        // Both are JsNumber.
        return new JsNumber($lnum->value + $rnum->value);
    }

    /**
     * Apply a numeric binary operator (-, *, /, %, **) with BigInt support.
     *
     * Calls ToNumeric on both operands. If both are BigInt, performs the
     * corresponding arbitrary-precision operation. If both are Number,
     * delegates to the existing float-based helpers. Mixed types throw TypeError.
     */
    private function numericBinaryOp(JsValue $left, JsValue $right, string $op): JsValue
    {
        $lnum = TypeConversion::toNumeric($left);
        $rnum = TypeConversion::toNumeric($right);

        if ($lnum instanceof JsBigInt && $rnum instanceof JsBigInt) {
            return $this->bigintArithmetic($lnum, $rnum, $op);
        }

        if ($lnum instanceof JsBigInt || $rnum instanceof JsBigInt) {
            throw new TypeError('Cannot mix BigInt and other types, use explicit conversions');
        }

        // Both JsNumber: delegate to existing helpers.
        $l = $lnum->value;
        $r = $rnum->value;

        return match ($op) {
            '-' => new JsNumber($l - $r),
            '*' => new JsNumber($l * $r),
            '/' => $this->divide($l, $r),
            '%' => $this->modulo($l, $r),
            '**' => $this->exponentiate($lnum, $rnum),
        };
    }

    /**
     * BigInt arithmetic for -, *, /, %, **.
     *
     * Division truncates toward zero (not floor). Division and remainder
     * by zero throw RangeError per the spec.
     */
    private function bigintArithmetic(JsBigInt $left, JsBigInt $right, string $op): JsBigInt
    {
        return match ($op) {
            '-' => new JsBigInt(self::bigStrBcSub($left->value, $right->value)),
            '*' => new JsBigInt(self::bigStrBcMul($left->value, $right->value)),
            '/' => $this->bigintDivide($left, $right),
            '%' => $this->bigintRemainder($left, $right),
            '**' => $this->bigintExponentiate($left, $right),
        };
    }

    /**
     * BigInt::divide(x, y). Throws RangeError if y is 0n. Truncates toward zero.
     */
    private function bigintDivide(JsBigInt $left, JsBigInt $right): JsBigInt
    {
        if ($right->value === '0' || $right->value === '-0') {
            throw new \PhpJs\Exceptions\RangeError('Division by zero');
        }
        // Divide truncating toward zero, matching the spec.
        return new JsBigInt(self::bigStrBcDiv($left->value, $right->value));
    }

    /**
     * BigInt::remainder(x, y). Throws RangeError if y is 0n.
     */
    private function bigintRemainder(JsBigInt $left, JsBigInt $right): JsBigInt
    {
        if ($right->value === '0' || $right->value === '-0') {
            throw new \PhpJs\Exceptions\RangeError('Division by zero');
        }
        return new JsBigInt(self::bigStrBcMod($left->value, $right->value));
    }

    /**
     * BigInt::exponentiate(x, y). Throws RangeError if y is negative.
     */
    private function bigintExponentiate(JsBigInt $left, JsBigInt $right): JsBigInt
    {
        if (self::bigStrComp($right->value, '0') < 0) {
            throw new \PhpJs\Exceptions\RangeError('Exponent must be positive');
        }
        return new JsBigInt(self::bigStrBcPow($left->value, $right->value));
    }

    /**
     * Bitwise AND, OR, XOR for both Number and BigInt operands.
     *
     * Per spec: ToNumeric both sides. If both BigInt, perform BigInt bitwise op.
     * If types differ, throw TypeError.
     */
    private function bitwiseBinaryOp(JsValue $left, JsValue $right, string $op): JsValue
    {
        $lnum = TypeConversion::toNumeric($left);
        $rnum = TypeConversion::toNumeric($right);

        if ($lnum instanceof JsBigInt && $rnum instanceof JsBigInt) {
            return $this->bigintBitwiseOp($lnum, $rnum, $op);
        }

        if ($lnum instanceof JsBigInt || $rnum instanceof JsBigInt) {
            throw new TypeError('Cannot mix BigInt and other types, use explicit conversions');
        }

        $l = TypeConversion::toInt32($lnum);
        $r = TypeConversion::toInt32($rnum);

        return new JsNumber((float) match ($op) {
            '&' => $l & $r,
            '|' => $l | $r,
            '^' => $l ^ $r,
        });
    }

    /**
     * Shift operators (<<, >>, >>>) for both Number and BigInt operands.
     *
     * Per spec: ToNumeric both sides. If both BigInt, perform BigInt shift.
     * BigInt >>> is always a TypeError. If types differ, throw TypeError.
     */
    private function bitwiseShift(JsValue $left, JsValue $right, string $op): JsValue
    {
        $lnum = TypeConversion::toNumeric($left);
        $rnum = TypeConversion::toNumeric($right);

        if ($lnum instanceof JsBigInt && $rnum instanceof JsBigInt) {
            if ($op === '>>>') {
                throw new TypeError('BigInts have no unsigned right shift, use >> instead');
            }
            return $this->bigintShift($lnum, $rnum, $op);
        }

        if ($lnum instanceof JsBigInt || $rnum instanceof JsBigInt) {
            throw new TypeError('Cannot mix BigInt and other types, use explicit conversions');
        }

        return match ($op) {
            '<<' => new JsNumber(TypeConversion::leftShift($lnum, $rnum)),
            '>>' => new JsNumber(TypeConversion::signedRightShift($lnum, $rnum)),
            '>>>' => new JsNumber(TypeConversion::unsignedRightShift($lnum, $rnum)),
        };
    }

    /**
     * BigInt shift operations.
     *
     * BigInt::leftShift(x, y):
     *   If y < 0: floor(x / 2^(-y)) (rounding toward negative infinity).
     *   Otherwise: x * 2^y.
     *
     * BigInt::signedRightShift(x, y) = BigInt::leftShift(x, -y).
     */
    private function bigintShift(JsBigInt $left, JsBigInt $right, string $op): JsBigInt
    {
        // Right shift is defined as leftShift(x, -y).
        $rightNeg = $right->value[0] === '-';
        $shiftNeg = ($op === '>>' && !$rightNeg) || ($op === '<<' && $rightNeg);

        // Absolute shift amount as a string.
        $absShift = ltrim($right->value, '-');
        if ($absShift === '' || $absShift === '0') {
            return $left;
        }

        // Clamp very large shift amounts to prevent memory exhaustion.
        $shiftInt = $this->bigStrFitsInt($absShift) ? (int) $absShift : 10000;
        $shiftInt = min($shiftInt, 10000);

        if (!$shiftNeg) {
            // Left shift: x * 2^shift.
            // Multiply $left->value by 2 repeatedly (or pow).
            $result = $left->value;
            $multiplier = $this->bigStrPow2($shiftInt);
            $result = $this->bigStrMulSigned($left->value, $multiplier);
            return new JsBigInt($result);
        }

        // Right shift: floor(x / 2^shift) (toward negative infinity).
        if ($this->bigStrFitsInt($left->value)) {
            // Use native PHP int arithmetic for small values.
            $l = (int) $left->value;
            $d = $shiftInt >= 63 ? PHP_INT_MAX : (1 << $shiftInt);
            // PHP >> is arithmetic right shift (sign-extending), which matches floor division.
            return new JsBigInt((string) ($shiftInt >= 63 ? ($l < 0 ? -1 : 0) : ($l >> $shiftInt)));
        }
        // Large: convert to binary, drop last $shiftInt bits, convert back.
        $leftNeg = $left->value[0] === '-';
        $abs = ltrim($left->value, '-');
        $bin = $this->bigintToTwosCompBin($leftNeg ? '-' . $abs : $abs);
        if ($shiftInt >= strlen($bin)) {
            return new JsBigInt($leftNeg ? '-1' : '0');
        }
        $shifted = substr($bin, 0, strlen($bin) - $shiftInt);
        if ($shifted === '') {
            return new JsBigInt($leftNeg ? '-1' : '0');
        }
        $padded = str_pad($shifted, strlen($bin), $leftNeg ? '1' : '0', STR_PAD_LEFT);
        return new JsBigInt($this->twosCompBinToDecimal($padded));
    }

    /** Compute 2^n as a decimal string using pure-PHP string doubling. */
    private function bigStrPow2(int $n): string
    {
        $result = '1';
        for ($i = 0; $i < $n; $i++) {
            $result = self::bigStrMul($result, '2');
        }
        return $result;
    }

    /** Multiply two decimal integer strings (handles sign). */
    private function bigStrMulSigned(string $a, string $b): string
    {
        $negA = $a[0] === '-';
        $negB = $b[0] === '-';
        $absA = ltrim($a, '-');
        $absB = ltrim($b, '-');
        $result = $this->bigStrMulUnsigned($absA, $absB);
        if (($negA xor $negB) && $result !== '0') {
            return '-' . $result;
        }
        return $result;
    }

    /** Multiply two non-negative decimal integer strings using schoolbook algorithm. */
    private function bigStrMulUnsigned(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }
        $m = strlen($a);
        $n = strlen($b);
        $result = array_fill(0, $m + $n, 0);
        for ($i = $m - 1; $i >= 0; $i--) {
            for ($j = $n - 1; $j >= 0; $j--) {
                $mul = (int) $a[$i] * (int) $b[$j];
                $p1 = $i + $j;
                $p2 = $i + $j + 1;
                $sum = $mul + $result[$p2];
                $result[$p2] = $sum % 10;
                $result[$p1] += intdiv($sum, 10);
            }
        }
        return ltrim(implode('', $result), '0') ?: '0';
    }

    private function divide(float $left, float $right): JsNumber
    {
        if ($right === 0.0) {
            if ($left === 0.0 || is_nan($left)) {
                return new JsNumber(NAN);
            }
            $leftNeg = $left < 0 || JsNumber::isNegativeZero($left);
            $rightNeg = JsNumber::isNegativeZero($right);
            return new JsNumber(($leftNeg xor $rightNeg) ? -INF : INF);
        }
        return new JsNumber($left / $right);
    }

    private function modulo(float $left, float $right): JsNumber
    {
        if (is_nan($left) || is_nan($right) || is_infinite($left) || $right === 0.0) {
            return new JsNumber(NAN);
        }
        if (is_infinite($right)) {
            return new JsNumber($left);
        }
        if ($left === 0.0) {
            return new JsNumber($left); // preserves -0
        }
        return new JsNumber(fmod($left, $right));
    }

    /**
     * ES spec ExponentiationExpression evaluation.
     *
     * Calls ToNumeric on both operands. If both are BigInt, performs
     * arbitrary-precision exponentiation via pure-PHP string arithmetic. If both are Number,
     * uses float exponentiation with IEEE 754 special cases. Mixed
     * types throw TypeError per spec.
     */
    private function exponentiate(JsValue $left, JsValue $right): JsValue
    {
        $lnum = TypeConversion::toNumeric($left);
        $rnum = TypeConversion::toNumeric($right);

        // BigInt ** BigInt.
        if ($lnum instanceof JsBigInt && $rnum instanceof JsBigInt) {
            if (self::bigStrComp($rnum->value, '0') < 0) {
                throw new \PhpJs\Exceptions\RangeError('Exponent must be positive');
            }
            return new JsBigInt(self::bigStrBcPow($lnum->value, $rnum->value));
        }

        // Mixed types: one BigInt and one Number.
        if ($lnum instanceof JsBigInt || $rnum instanceof JsBigInt) {
            throw new TypeError('Cannot mix BigInt and other types, use explicit conversions');
        }

        // Both Number: float exponentiation with ES spec special cases.
        $base = $lnum->value;
        $exp = $rnum->value;

        if (abs($base) === 1.0 && is_infinite($exp)) {
            return new JsNumber(NAN);
        }
        if ($base === 0.0 && $exp < 0) {
            if (JsNumber::isNegativeZero($base) && fmod($exp, 2) === -1.0) {
                return new JsNumber(-INF);
            }
            return new JsNumber(INF);
        }
        return new JsNumber(@($base ** $exp));
    }

    private function relational(JsValue $x, JsValue $y, string $op): JsValue
    {
        $result = AbstractOperations::abstractRelational($x, $y, $op === '<' || $op === '>=');
        if ($result === null) {
            // Per spec, undefined (NaN or incomparable) always produces false for all relational operators.
            return new JsBoolean(false);
        }
        if ($op === '<=' || $op === '>=') {
            // a >= b is !(a < b), a <= b is !(b < a). If abstractRelational returned true,
            // the negated operator returns false, and vice versa.
            return new JsBoolean(!$result);
        }
        return new JsBoolean($result);
    }

    private function unsignedRightShift(int $left, int $shift): int
    {
        if ($shift === 0) {
            return $left < 0 ? $left + 4294967296 : $left;
        }
        return ($left >> $shift) & (PHP_INT_MAX >> ($shift - 1));
    }

    /**
     * Normalize a JsBigInt value string (which may use 0x, 0o, 0b prefixes)
     * to a decimal string suitable for bcmath operations.
     */
    private function bigIntToDecimal(string $value): string
    {
        $negative = false;
        $v = $value;
        if ($v !== '' && $v[0] === '-') {
            $negative = true;
            $v = substr($v, 1);
        }

        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $v, $m) === 1) {
            $dec = self::baseStringToDecimal($m[1], 16);
            return $negative ? '-' . $dec : $dec;
        }

        if (preg_match('/^0[oO]([0-7]+)$/', $v, $m) === 1) {
            $dec = self::baseStringToDecimal($m[1], 8);
            return $negative ? '-' . $dec : $dec;
        }

        if (preg_match('/^0[bB]([01]+)$/', $v, $m) === 1) {
            $dec = self::baseStringToDecimal($m[1], 2);
            return $negative ? '-' . $dec : $dec;
        }

        return $value;
    }

    private function evalInOperator(JsValue $left, JsValue $right): JsValue
    {
        if (!$right instanceof JsObject) {
            throw new TypeError(
                'Cannot use "in" operator to search for "'
                . TypeConversion::toString($left) . '" in ' . TypeConversion::toString($right)
            );
        }
        // Per spec, the key can be a Symbol (property key).
        if ($left instanceof JsSymbol) {
            return new JsBoolean($right->hasBySymbol($left));
        }
        $key = TypeConversion::toString($left);
        return new JsBoolean($right->has($key));
    }

    private function evalLogicalExpression(LogicalExpression $node, Environment $env): JsValue
    {
        $left = $this->evaluate($node->left, $env);

        return match ($node->operator) {
            '&&' => TypeConversion::toBoolean($left) ? $this->evaluate($node->right, $env) : $left,
            '||' => TypeConversion::toBoolean($left) ? $left : $this->evaluate($node->right, $env),
            '??' => ($left instanceof JsNull || $left instanceof JsUndefined)
                ? $this->evaluate($node->right, $env)
                : $left,
            default => throw new InternalError("Unknown logical operator: {$node->operator}"),
        };
    }

    private function evalUnaryExpression(UnaryExpression $node, Environment $env): JsValue
    {
        if ($node->operator === 'typeof') {
            return $this->evalTypeof($node->argument, $env);
        }
        if ($node->operator === 'delete') {
            return $this->evalDelete($node->argument, $env);
        }

        $value = $this->evaluate($node->argument, $env);

        if ($node->operator === '-') {
            $numeric = TypeConversion::toNumeric($value);
            if ($numeric instanceof JsBigInt) {
                $v = $numeric->value;
                if ($v === '0' || $v === '-0') {
                    return new JsBigInt('0');
                }
                $negated = str_starts_with($v, '-') ? substr($v, 1) : '-' . $v;
                return new JsBigInt($negated);
            }
            return new JsNumber(-($numeric instanceof JsNumber ? $numeric->value : TypeConversion::toNumber($numeric)));
        }

        return match ($node->operator) {
            '!' => new JsBoolean(!TypeConversion::toBoolean($value)),
            '+' => $value instanceof JsBigInt
                ? throw new TypeError('Cannot convert a BigInt value to a number')
                : new JsNumber(TypeConversion::toNumber($value)),
            '~' => $value instanceof JsBigInt
                ? new JsBigInt((string) (~(int) $value->value))
                : new JsNumber((float) (~TypeConversion::toInt32($value))),
            'void' => JsUndefined::instance(),
            default => throw new InternalError("Unknown unary operator: {$node->operator}"),
        };
    }

    private function evalTypeof(Node $argument, Environment $env): JsValue
    {
        if ($argument instanceof Identifier) {
            if (!$env->has($argument->name)) {
                return new JsString('undefined');
            }
        }
        $value = $this->evaluate($argument, $env);
        return AbstractOperations::typeofOperator($value);
    }

    private function evalDelete(Node $argument, Environment $env): JsValue
    {
        if ($argument instanceof MemberExpression) {
            $obj = $this->evaluate($argument->object, $env);
            if ($obj instanceof JsNull || $obj instanceof JsUndefined) {
                throw new TypeError(
                    'Cannot read properties of ' . ($obj instanceof JsNull ? 'null' : 'undefined') . ' (deleting)',
                );
            }
            if ($obj instanceof JsObject) {
                $rawKey = null;
                if ($argument->computed) {
                    $rawKey = $this->evaluate($argument->property, $env);
                    if ($rawKey instanceof JsSymbol) {
                        // Delete symbol-keyed property.
                        $deleted = $obj->deleteBySymbol($rawKey);
                        if (!$deleted && $this->strictMode) {
                            throw new TypeError(
                                "Cannot delete property '" . $rawKey->toString() . "' of #<Object>"
                            );
                        }
                        return new JsBoolean($deleted);
                    }
                    $key = TypeConversion::toString($rawKey);
                } else {
                    $key = $argument->property instanceof Identifier ? $argument->property->name : '';
                }
                return new JsBoolean($obj->delete($key, $this->strictMode));
            }
            // Deleting a property on a non-object primitive: return true.
            return new JsBoolean(true);
        }

        // Delete on an identifier reference.
        if ($argument instanceof Identifier) {
            $name = $argument->name;
            if ($this->strictMode) {
                // In strict mode, `delete identifier` is a SyntaxError, but since
                // we get here at runtime we throw it as a SyntaxError-like error.
                // The spec says deleting an unresolvable reference in strict mode
                // is a SyntaxError, but deleting a declared binding in strict mode
                // is also a SyntaxError. Some tests expect TypeError for certain
                // global object properties; those go through the MemberExpression
                // branch above. Here we handle the raw identifier case.
                throw new \PhpJs\Exceptions\SyntaxError(
                    'Delete of an unqualified identifier in strict mode.'
                );
            }
            if (!$env->has($name)) {
                // Unresolvable reference: return true.
                return new JsBoolean(true);
            }
            return new JsBoolean($env->deleteBinding($name));
        }

        // For any other expression (call expression, literal, etc.): evaluate the
        // expression for side effects, then return true. Per spec, delete on a
        // non-Reference value returns true.
        $this->evaluate($argument, $env);
        return new JsBoolean(true);
    }

    private function evalUpdateExpression(UpdateExpression $node, Environment $env): JsValue
    {
        // In strict mode, ++/-- on `eval` or `arguments` is a SyntaxError.
        if ($this->strictMode && $node->argument instanceof Identifier) {
            if ($node->argument->name === 'eval' || $node->argument->name === 'arguments') {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Cannot modify '{$node->argument->name}' in strict mode",
                );
            }
        }

        $ref = $this->resolveReference($node->argument, $env);

        // Per spec 6.2.4.4: if the reference base is null or undefined, throw
        // TypeError before GetValue so that deferred property key ToString
        // (which may have side effects) is never triggered.
        if (
            !($ref->base instanceof Environment)
            && ($ref->base instanceof JsNull || $ref->base instanceof JsUndefined)
        ) {
            $typeName = $ref->base instanceof JsNull ? 'null' : 'undefined';
            throw new TypeError(
                "Cannot read properties of {$typeName} (reading '{$ref->name}')"
            );
        }

        // Use ToNumeric (not ToNumber) so BigInt values are preserved.
        $oldNumeric = TypeConversion::toNumeric($ref->getValue());

        if ($oldNumeric instanceof JsBigInt) {
            // BigInt::add(oldValue, BigInt::unit) per spec.
            $decVal = $this->bigIntToDecimal($oldNumeric->value);
            $delta = $node->operator === '++' ? '1' : '-1';
            $raw = self::bigStrBcAdd($decVal, $delta);
            if ($raw === '-0') {
                $raw = '0';
            }
            $newValue = new JsBigInt($raw);
            $ref->setValue($newValue);
            return $node->prefix ? $newValue : $oldNumeric;
        }

        $oldValue = new JsNumber(
            $oldNumeric instanceof JsNumber ? $oldNumeric->value : TypeConversion::toNumber($oldNumeric),
        );
        $delta = $node->operator === '++' ? 1.0 : -1.0;
        $newValue = new JsNumber($oldValue->value + $delta);
        $ref->setValue($newValue);

        return $node->prefix ? $newValue : $oldValue;
    }

    private function evalAssignment(AssignmentExpression $node, Environment $env): JsValue
    {
        // In strict mode, assignment to `eval` or `arguments` is a SyntaxError.
        if ($this->strictMode && $node->left instanceof Identifier) {
            if ($node->left->name === 'eval' || $node->left->name === 'arguments') {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Cannot assign to '{$node->left->name}' in strict mode",
                );
            }
        }

        if ($node->operator === '=' && $this->isDestructuringTarget($node->left)) {
            $value = $this->evaluate($node->right, $env);
            $this->destructureAssign($node->left, $value, $env);
            return $value;
        }

        $ref = $this->resolveReference($node->left, $env);

        if ($node->operator === '=') {
            $right = $this->evaluate($node->right, $env);
            // Function name inference per spec 13.15.2 step 1.e:
            // If IsAnonymousFunctionDefinition is true, then
            //   a. Let hasNameProperty be HasOwnProperty(rval, "name").
            //   b. If hasNameProperty is false, perform SetFunctionName(rval, lref).
            // Note: JsFunction constructor always defines .name, so we check whether
            // it was explicitly overridden (e.g. static name() in a class body).
            // Per spec, IsIdentifierRef returns false for parenthesized expressions
            // like (fn) = function() {}, so name inference must not apply.
            $isIdentRef = $node->left instanceof Identifier && !$node->leftParenthesized;
            if (
                $right instanceof JsFunction
                && $isIdentRef
                && $this->isAnonymousFunctionDefinitionNode($node->right)
                && !$this->hasExplicitNameProperty($right)
            ) {
                $right->setName($node->left->name);
            }
            $ref->setValue($right);
            return $right;
        }

        // Logical assignment operators (&&=, ||=, ??=) per spec 13.15.3:
        // GetValue before RHS, and RHS is evaluated conditionally.
        // Per spec 6.2.5.5 GetValue step 3a: if base is null/undefined,
        // ToObject throws TypeError before resolving the property key.
        if ($node->operator === '&&=' || $node->operator === '||=' || $node->operator === '??=') {
            if ($ref->base instanceof JsNull || $ref->base instanceof JsUndefined) {
                $typeName = $ref->base instanceof JsNull ? 'null' : 'undefined';
                throw new TypeError(
                    "Cannot read properties of {$typeName}",
                );
            }
            $leftVal = $ref->getValue();
            $shouldAssign = match ($node->operator) {
                '&&=' => TypeConversion::toBoolean($leftVal),
                '||=' => !TypeConversion::toBoolean($leftVal),
                '??=' => $leftVal instanceof JsNull || $leftVal instanceof JsUndefined,
            };
            if (!$shouldAssign) {
                return $leftVal;
            }
            $right = $this->evaluate($node->right, $env);
            $ref->setValue($right);
            return $right;
        }

        // Compound assignment operators per spec 13.15.2:
        // 1. GetValue(lref) before evaluating RHS.
        // 2. Evaluate RHS.
        // 3. Apply operation.
        // 4. PutValue(lref, result).
        // Per spec 6.2.5.5 GetValue step 3a: if base is null/undefined,
        // ToObject throws TypeError before resolving the property key.
        if ($ref->base instanceof JsNull || $ref->base instanceof JsUndefined) {
            $typeName = $ref->base instanceof JsNull ? 'null' : 'undefined';
            throw new TypeError(
                "Cannot read properties of {$typeName}",
            );
        }
        $leftVal = $ref->getValue();
        $right = $this->evaluate($node->right, $env);

        $result = match ($node->operator) {
            '+=' => $this->addOperator($leftVal, $right),
            '-=' => $this->numericBinaryOp($leftVal, $right, '-'),
            '*=' => $this->numericBinaryOp($leftVal, $right, '*'),
            '/=' => $this->numericBinaryOp($leftVal, $right, '/'),
            '%=' => $this->numericBinaryOp($leftVal, $right, '%'),
            '**=' => $this->exponentiate($leftVal, $right),
            '<<=' => $this->bitwiseShift($leftVal, $right, '<<'),
            '>>=' => $this->bitwiseShift($leftVal, $right, '>>'),
            '>>>=' => $this->bitwiseShift($leftVal, $right, '>>>'),
            '&=' => $this->bitwiseBinaryOp($leftVal, $right, '&'),
            '|=' => $this->bitwiseBinaryOp($leftVal, $right, '|'),
            '^=' => $this->bitwiseBinaryOp($leftVal, $right, '^'),
            default => throw new InternalError("Unknown assignment operator: {$node->operator}"),
        };

        $ref->setValue($result);
        return $result;
    }

    private function evalConditional(ConditionalExpression $node, Environment $env): JsValue
    {
        $test = $this->evaluate($node->test, $env);
        return TypeConversion::toBoolean($test)
            ? $this->evaluate($node->consequent, $env)
            : $this->evaluate($node->alternate, $env);
    }

    private function evalCallExpression(CallExpression $node, Environment $env): JsValue
    {
        // Super call: super(args) inside a class constructor.
        // Calls the super class constructor with the current this and args.
        if ($node->callee instanceof Identifier && $node->callee->name === 'super') {
            $args = $this->evaluateArguments($node->arguments, $env);
            // Get the active function (the constructor being executed).
            try {
                $activeFunc = $env->get('[[ActiveFunction]]');
            } catch (\Throwable) {
                $activeFunc = null;
            }
            // Super constructor is [[GetPrototypeOf]](activeFunction).
            $superCtor = $activeFunc instanceof JsFunction ? $activeFunc->getPrototype() : null;
            if (!$superCtor instanceof JsFunction) {
                throw new TypeError('Super constructor must be a function');
            }
            // Get current this value.
            try {
                $currentThis = $env->get('this');
            } catch (\Throwable) {
                $currentThis = JsUndefined::instance();
            }
            // Call the super constructor. It may return a new object (factory pattern).
            $result = $this->callFunction($superCtor, $currentThis, $args);
            // If super constructor returned an object, bind that as this.
            if ($result instanceof JsObject) {
                $env->set('this', $result);
                return $result;
            }
            return $currentThis;
        }

        // Super member call: super.method(args) or super[expr](args).
        if (
            $node->callee instanceof MemberExpression
            && $node->callee->object instanceof Identifier
            && $node->callee->object->name === 'super'
        ) {
            try {
                $homeObject = $env->get('[[HomeObject]]');
            } catch (\Throwable) {
                $homeObject = null;
            }
            $superBase = $homeObject instanceof JsObject ? $homeObject->getPrototype() : null;
            if ($superBase === null) {
                throw new TypeError('Cannot read properties of undefined (super)');
            }
            // Resolve the property key.
            if ($node->callee->computed) {
                $rawKey = $this->evaluate($node->callee->property, $env);
                $isSymKey = $rawKey instanceof JsSymbol;
                $key = $isSymKey ? '' : TypeConversion::toString($rawKey);
            } else {
                $rawKey = null;
                $isSymKey = false;
                $key = $node->callee->property instanceof Identifier
                    ? $node->callee->property->name
                    : TypeConversion::toString($this->evaluate($node->callee->property, $env));
            }
            $callee = $isSymKey ? $superBase->getBySymbol($rawKey) : $superBase->get($key);
            if (!$callee instanceof JsFunction) {
                throw new TypeError("{$key} is not a function");
            }
            // Use current this, not the super object.
            try {
                $thisValue = $env->get('this');
            } catch (\Throwable) {
                $thisValue = JsUndefined::instance();
            }
            $args = $this->evaluateArguments($node->arguments, $env);
            return $this->callFunction($callee, $thisValue, $args);
        }

        // Direct eval detection: eval(code) called with the identifier 'eval'.
        // Direct eval executes in the current scope, not a fresh environment.
        if ($node->callee instanceof Identifier && $node->callee->name === 'eval') {
            try {
                $callee = $env->get('eval');
            } catch (ReferenceError) {
                $callee = null;
            }
            if ($callee instanceof JsFunction && $callee->getName() === 'eval' && $callee->isNative()) {
                $arg = isset($node->arguments[0])
                    ? $this->evaluate($node->arguments[0], $env)
                    : JsUndefined::instance();
                return $this->performDirectEval($arg, $env);
            }
        }

        $thisValue = JsUndefined::instance();
        $isMethodCall = false;

        if ($node->callee instanceof MemberExpression) {
            $rawObj = $this->evaluate($node->callee->object, $env);

            // Optional chain short-circuit: the base of the callee was
            // null/undefined via ?., so skip the call entirely.
            if ($rawObj instanceof JsOptionalUndefined) {
                return $rawObj;
            }

            // Optional call: obj?.method() where obj evaluates to null/undefined
            if ($node->callee->optional && ($rawObj instanceof JsNull || $rawObj instanceof JsUndefined)) {
                return JsOptionalUndefined::instance();
            }

            $rawCallKey = null;
            if ($node->callee->computed) {
                $rawCallKey = $this->evaluate($node->callee->property, $env);
            }
            $isSymbolCallKey = $rawCallKey instanceof JsSymbol;
            $key = $isSymbolCallKey ? '' : ($node->callee->computed
                ? TypeConversion::toString($rawCallKey)
                : ($node->callee->property instanceof Identifier
                    ? $node->callee->property->name : ''));

            // String method calls: look up on __StringPrototype__
            if ($rawObj instanceof JsString && !$isSymbolCallKey && $env->has('__StringPrototype__')) {
                $proto = $env->get('__StringPrototype__');
                if ($proto instanceof JsObject) {
                    $method = $proto->get($key);
                    if ($method instanceof JsFunction) {
                        $args = $this->evaluateArguments($node->arguments, $env);
                        return $this->callFunction($method, $rawObj, $args);
                    }
                }
            }

            // Number method calls: look up on Number.prototype
            if ($rawObj instanceof JsNumber && !$isSymbolCallKey) {
                $numCtor = $env->has('Number') ? $env->get('Number') : null;
                if ($numCtor instanceof JsFunction) {
                    $numProto = $numCtor->get('prototype');
                    if ($numProto instanceof JsObject) {
                        $method = $numProto->get($key);
                        if ($method instanceof JsFunction) {
                            $args = $this->evaluateArguments($node->arguments, $env);
                            return $this->callFunction($method, $rawObj, $args);
                        }
                    }
                }
            }

            // Boolean method calls: look up on Boolean wrapper
            if ($rawObj instanceof JsBoolean && !$isSymbolCallKey) {
                $boolCtor = $env->has('Boolean') ? $env->get('Boolean') : null;
                if ($boolCtor instanceof JsFunction) {
                    $boolProto = $boolCtor->get('prototype');
                    if ($boolProto instanceof JsObject) {
                        $method = $boolProto->get($key);
                        if ($method instanceof JsFunction) {
                            $args = $this->evaluateArguments($node->arguments, $env);
                            return $this->callFunction($method, $rawObj, $args);
                        }
                    }
                }
            }

            // BigInt method calls: look up on BigInt.prototype.
            if ($rawObj instanceof JsBigInt && !$isSymbolCallKey) {
                $bigintProto = JsBigInt::getPrototype();
                if ($bigintProto !== null) {
                    $method = $bigintProto->get($key);
                    if ($method instanceof JsFunction) {
                        $args = $this->evaluateArguments($node->arguments, $env);
                        return $this->callFunction($method, $rawObj, $args);
                    }
                }
            }

            // Symbol method calls: look up on Symbol.prototype, passing primitive as this.
            if ($rawObj instanceof JsSymbol) {
                $symProtoForCall = JsSymbol::getSymbolPrototype();
                if ($symProtoForCall !== null) {
                    $method = $isSymbolCallKey
                        ? $symProtoForCall->getBySymbol($rawCallKey)
                        : $symProtoForCall->get($key);
                    if ($method instanceof JsFunction) {
                        $args = $this->evaluateArguments($node->arguments, $env);
                        return $this->callFunction($method, $rawObj, $args);
                    }
                }
            }

            $obj = $rawObj instanceof JsObject ? $rawObj : TypeConversion::toObject($rawObj);
            $callee = $isSymbolCallKey ? $obj->getBySymbol($rawCallKey) : $obj->get($key);
            $thisValue = $obj;
            $isMethodCall = true;
        } else {
            $callee = $this->evaluate($node->callee, $env);
        }

        // Optional chain short-circuit: callee resolved to short-circuit sentinel.
        if ($callee instanceof JsOptionalUndefined) {
            return $callee;
        }

        // Optional call: fn?.() where fn is null/undefined.
        if ($node->optional && ($callee instanceof JsNull || $callee instanceof JsUndefined)) {
            return JsOptionalUndefined::instance();
        }

        // Per spec, arguments are evaluated before the callability check.
        $args = $this->evaluateArguments($node->arguments, $env);

        // Proxy apply trap: if the callee is a Proxy wrapping a function, invoke its apply().
        if ($callee instanceof \PhpJs\Value\JsProxy) {
            return $callee->apply($thisValue, $args);
        }

        if (!$callee instanceof JsFunction) {
            $desc = TypeConversion::toString($callee);
            throw new TypeError("{$desc} is not a function");
        }

        // For simple (non-method) calls, thisValue is always undefined here.
        // executeFunction will convert undefined → globalObject for sloppy-mode functions
        // (OrdinaryCallBindThis step 5: non-strict, thisValue is null/undefined → global).
        // Strict functions (and native built-ins) receive undefined as-is.
        // We do NOT set thisValue = globalObject here so that explicit apply(globalObject)
        // calls pass the global object through without being cleared by executeFunction.

        return $this->callFunction($callee, $thisValue, $args);
    }

    /**
     * Perform a direct eval: parse and execute code in the given environment.
     *
     * Direct eval (called as `eval(code)`) has access to the calling scope's
     * variables and can declare new variables in that scope. If the argument
     * is not a string, it is returned as-is.
     */
    private function performDirectEval(JsValue $arg, Environment $env): JsValue
    {
        if (!$arg instanceof JsString) {
            return $arg;
        }

        // Parse and validate. Any SyntaxError from parsing or validation
        // must be thrown as a JS SyntaxError catchable by JS try/catch.
        $evalStrict = $this->strictMode;
        try {
            if (strlen($arg->value) > 1024 * 1024) {
                throw new \PhpJs\Exceptions\SyntaxError('Source too large for eval');
            }
            $parser = new \PhpJs\Parser\Parser($arg->value);
            $program = $parser->parse();

            // Validate: return, break, and continue are not allowed at the top
            // level of eval code per spec.
            $this->validateEvalBody($program->body);

            // Per 18.2.1.1.1: super is a SyntaxError in eval unless the
            // direct eval is inside a method (environment has [[HomeObject]]).
            // Per 18.2.1.1.2: super() (SuperCall) is additionally restricted
            // to constructor methods only.
            $inMethod = $env->has('[[HomeObject]]');
            $inConstructor = $env->has('[[ActiveFunction]]');
            if (!$inMethod && $this->astContainsSuper($program->body)) {
                throw new \PhpJs\Exceptions\SyntaxError("'super' keyword unexpected here");
            }
            if ($inMethod && !$inConstructor && $this->astContainsSuperCall($program->body)) {
                throw new \PhpJs\Exceptions\SyntaxError("'super' keyword unexpected here");
            }

            // Per spec 15.1.1: new.target in eval is a SyntaxError unless
            // the direct eval is contained in function code that is not an
            // ArrowFunction.
            if ($this->astContainsNewTarget($program->body)) {
                $funcKind = $env->getEnclosingFunctionKind();
                // Allowed only if inside a regular function, generator, or async function.
                // Not allowed in global code or arrow functions.
                if ($funcKind === null || $funcKind === 'arrow') {
                    throw new \PhpJs\Exceptions\SyntaxError("new.target expression is not allowed here");
                }
            }

            // Detect if the eval code itself enables strict mode. This must
            // happen before the var/lex conflict check because strict eval
            // isolates its var declarations in a separate scope.
            $evalStrict = $this->strictMode || $this->hasUseStrictDirective($program->body);

            // Per EvalDeclarationInstantiation: var-declared names in eval
            // must not conflict with existing lexical (let/const/TDZ) bindings
            // in the enclosing scope chain. This catches cases like:
            //   function f(p = eval("var arguments"), arguments) {}
            // where the TDZ binding for "arguments" (the following parameter)
            // would conflict with the var from eval.
            // Skip this check for strict eval: strict mode eval creates its own
            // variable scope, so var declarations do not leak and cannot conflict
            // with outer lexical bindings.
            if (!$evalStrict) {
                $evalVarNames = $this->collectEvalVarNames($program->body);
                foreach ($evalVarNames as $varName) {
                    if ($env->hasLexicalBindingInScope($varName)) {
                        throw new \PhpJs\Exceptions\SyntaxError(
                            "Identifier '{$varName}' has already been declared",
                        );
                    }
                }
            }
        } catch (\PhpJs\Exceptions\SyntaxError $e) {
            $this->throwJsValue($this->phpExceptionToJsValue($e));
        }
        $previousStrictMode = $this->strictMode;

        // In strict mode, additional early errors must be checked after parsing.
        if ($evalStrict) {
            try {
                $this->validateStrictModeRestrictions($program->body);
            } catch (\PhpJs\Exceptions\SyntaxError $e) {
                $this->throwJsValue($this->phpExceptionToJsValue($e));
            }
        }

        if ($evalStrict && !$this->strictMode) {
            $this->strictMode = true;
        }

        try {
            // In strict mode, eval gets its own variable scope so var and
            // function declarations do not leak to the caller.
            $varEnv = $evalStrict ? $env->createChild() : $env;

            // For strict eval, pre-declare all var names as own bindings in the
            // child environment. This prevents hoistDeclarations from skipping them
            // when the parent scope has a same-named binding (since has() walks the
            // chain). Without this, strict eval's var declarations would shadow
            // rather than isolate from the outer scope.
            if ($evalStrict && $varEnv !== $env) {
                $strictVarNames = $this->collectEvalVarNames($program->body);
                foreach ($strictVarNames as $vn) {
                    if (!$varEnv->hasOwnBinding($vn)) {
                        $varEnv->defineVar($vn, JsUndefined::instance());
                    }
                }
            }

            // Hoist var declarations and function declarations.
            // For eval at global scope, function/var bindings must be
            // configurable (per EvalDeclarationInstantiation step 15/18).
            $isGlobalEval = !$evalStrict && $varEnv->getLinkedObject() !== null;
            if ($isGlobalEval) {
                $this->hoistEvalGlobalDeclarations($program->body, $varEnv);
            } elseif (!$evalStrict) {
                // Non-strict, non-global eval: per EvalDeclarationInstantiation,
                // var bindings are created in the caller's variable environment
                // even if a same-named binding exists in an outer scope.
                $this->hoistEvalLocalDeclarations($program->body, $varEnv);
            } else {
                $this->hoistDeclarations($program->body, $varEnv);
            }

            // Create a lexical environment for class/let/const TDZ bindings.
            $lexEnv = $varEnv->createChild();
            $this->hoistEvalLexicalDeclarations($program->body, $lexEnv);

            // Execute the parsed program body in the lexical environment.
            $completion = $this->executeBody($program->body, $lexEnv);

            if ($completion->type === CompletionType::Throw) {
                $this->throwJsValue($completion->value);
            }

            return $completion->value;
        } finally {
            $this->strictMode = $previousStrictMode;
        }
    }

    /**
     * Validate that eval code does not contain top-level return, break, or
     * continue statements, which are SyntaxErrors per the spec.
     *
     * @param Node[] $statements
     */
    private function validateEvalBody(array $statements): void
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof \PhpJs\Ast\Statement\ReturnStatement) {
                throw new \PhpJs\Exceptions\SyntaxError('Illegal return statement');
            }
            if ($stmt instanceof BreakStatement) {
                throw new \PhpJs\Exceptions\SyntaxError('Illegal break statement');
            }
            if ($stmt instanceof ContinueStatement) {
                throw new \PhpJs\Exceptions\SyntaxError('Illegal continue statement');
            }
            $this->validateEvalNoFreeJumps($stmt);
        }
    }

    /**
     * Check for break/continue/return that would escape eval code.
     *
     * Recurses into blocks and conditionals but stops at loops, switch
     * statements, and functions (which provide their own targets).
     */
    private function validateEvalNoFreeJumps(Node $node): void
    {
        if (
            $node instanceof ForStatement
            || $node instanceof ForInStatement
            || $node instanceof ForOfStatement
            || $node instanceof \PhpJs\Ast\Statement\WhileStatement
            || $node instanceof DoWhileStatement
            || $node instanceof \PhpJs\Ast\Statement\SwitchStatement
            || $node instanceof FunctionDeclaration
        ) {
            return;
        }

        if ($node instanceof BlockStatement) {
            foreach ($node->body as $child) {
                if ($child instanceof \PhpJs\Ast\Statement\ReturnStatement) {
                    throw new \PhpJs\Exceptions\SyntaxError('Illegal return statement');
                }
                if ($child instanceof BreakStatement) {
                    throw new \PhpJs\Exceptions\SyntaxError('Illegal break statement');
                }
                if ($child instanceof ContinueStatement) {
                    throw new \PhpJs\Exceptions\SyntaxError('Illegal continue statement');
                }
                $this->validateEvalNoFreeJumps($child);
            }
            return;
        }

        if ($node instanceof IfStatement) {
            $this->validateEvalNoFreeJumps($node->consequent);
            if ($node->alternate !== null) {
                $this->validateEvalNoFreeJumps($node->alternate);
            }
        }

        if ($node instanceof LabeledStatement) {
            $this->validateEvalNoFreeJumps($node->body);
        }
    }

    /**
     * Check whether an AST contains super() call expressions (SuperCall).
     *
     * Does not recurse into nested functions. This is used to enforce
     * the restriction that super() is only allowed in constructors,
     * not in regular methods.
     *
     * @param Node[] $statements
     */
    private function astContainsSuperCall(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->nodeContainsSuperCall($stmt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check a single AST node for super() call expressions.
     */
    private function nodeContainsSuperCall(Node $node): bool
    {
        // Direct super() call.
        if ($node instanceof CallExpression && $node->callee instanceof Identifier && $node->callee->name === 'super') {
            return true;
        }

        // Stop recursion at function boundaries.
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof FunctionExpression
            || $node instanceof ArrowFunction
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }

        // Recurse into child nodes.
        if ($node instanceof ExpressionStatement) {
            return $this->nodeContainsSuperCall($node->expression);
        }
        if ($node instanceof BlockStatement) {
            return $this->astContainsSuperCall($node->body);
        }
        if ($node instanceof IfStatement) {
            if ($this->nodeContainsSuperCall($node->test)) {
                return true;
            }
            if ($this->nodeContainsSuperCall($node->consequent)) {
                return true;
            }

            return $node->alternate !== null && $this->nodeContainsSuperCall($node->alternate);
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null && $this->nodeContainsSuperCall($decl->init)) {
                    return true;
                }
            }
        }
        if ($node instanceof CallExpression) {
            if ($this->nodeContainsSuperCall($node->callee)) {
                return true;
            }
            foreach ($node->arguments as $arg) {
                if ($this->nodeContainsSuperCall($arg)) {
                    return true;
                }
            }
        }
        if ($node instanceof AssignmentExpression) {
            return $this->nodeContainsSuperCall($node->left) || $this->nodeContainsSuperCall($node->right);
        }
        if ($node instanceof BinaryExpression) {
            return $this->nodeContainsSuperCall($node->left) || $this->nodeContainsSuperCall($node->right);
        }

        return false;
    }

    /**
     * Check whether an AST contains super property or super call references.
     *
     * Does not recurse into nested functions (they have their own [[HomeObject]]).
     *
     * @param Node[] $statements
     */
    private function astContainsSuper(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->nodeContainsSuper($stmt)) {
                return true;
            }
        }

        return false;
    }

    private function nodeContainsSuper(Node $node): bool
    {
        // Direct super references.
        if ($node instanceof CallExpression && $node->callee instanceof Identifier && $node->callee->name === 'super') {
            return true;
        }
        if ($node instanceof MemberExpression && $node->object instanceof Identifier && $node->object->name === 'super') {
            return true;
        }
        // Check if the node has a "super" keyword token directly (for super.prop, super[expr]).
        if ($node instanceof Identifier && $node->name === 'super') {
            return true;
        }

        // Stop recursion at function boundaries (they have their own [[HomeObject]]).
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof FunctionExpression
            || $node instanceof ArrowFunction
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }

        // Recurse into child nodes.
        if ($node instanceof ExpressionStatement) {
            return $this->nodeContainsSuper($node->expression);
        }
        if ($node instanceof BlockStatement) {
            return $this->astContainsSuper($node->body);
        }
        if ($node instanceof IfStatement) {
            if ($this->nodeContainsSuper($node->test)) {
                return true;
            }
            if ($this->nodeContainsSuper($node->consequent)) {
                return true;
            }

            return $node->alternate !== null && $this->nodeContainsSuper($node->alternate);
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null && $this->nodeContainsSuper($decl->init)) {
                    return true;
                }
            }
        }
        if ($node instanceof CallExpression) {
            if ($this->nodeContainsSuper($node->callee)) {
                return true;
            }
            foreach ($node->arguments as $arg) {
                if ($this->nodeContainsSuper($arg)) {
                    return true;
                }
            }
        }
        if ($node instanceof MemberExpression) {
            return $this->nodeContainsSuper($node->object);
        }
        if ($node instanceof AssignmentExpression) {
            return $this->nodeContainsSuper($node->left) || $this->nodeContainsSuper($node->right);
        }
        if ($node instanceof BinaryExpression) {
            return $this->nodeContainsSuper($node->left) || $this->nodeContainsSuper($node->right);
        }
        if ($node instanceof TryStatement) {
            if ($this->astContainsSuper($node->block->body)) {
                return true;
            }
            if ($node->handler !== null && $this->astContainsSuper($node->handler->body->body)) {
                return true;
            }

            return $node->finalizer !== null && $this->astContainsSuper($node->finalizer->body);
        }

        return false;
    }

    /**
     * Validate strict-mode early errors in parsed eval code.
     *
     * Per spec 13.15.1, catch parameters named 'eval' or 'arguments'
     * are SyntaxErrors in strict mode.
     *
     * @param Node[] $statements
     */
    private function validateStrictModeRestrictions(array $statements): void
    {
        foreach ($statements as $stmt) {
            $this->validateStrictModeNode($stmt);
        }
    }

    /** Strict-mode future reserved words per ES 12.1.1. */
    private const STRICT_RESERVED_WORDS = [
        'implements', 'interface', 'let', 'package',
        'private', 'protected', 'public', 'static', 'yield',
    ];

    private function isStrictReservedWord(string $name): bool
    {
        return \in_array($name, self::STRICT_RESERVED_WORDS, true);
    }

    private function validateStrictModeNode(Node $node): void
    {
        // 'with' statement is forbidden in strict mode.
        if ($node instanceof WithStatement) {
            throw new \PhpJs\Exceptions\SyntaxError('Strict mode code may not include a with statement');
        }

        // Variable declarations must not use strict-mode reserved words as binding names.
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                $this->checkStrictBindingNames($decl->id);
            }
        }

        // Function declarations must not use strict-mode reserved words as the function name.
        if ($node instanceof FunctionDeclaration && $node->id !== null) {
            if (
                $this->isStrictReservedWord($node->id->name)
                || $node->id->name === 'eval'
                || $node->id->name === 'arguments'
            ) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Unexpected strict mode reserved word '{$node->id->name}'",
                );
            }
        }

        // Expression statements may contain assignments to reserved words.
        if ($node instanceof ExpressionStatement) {
            $this->checkStrictExpressionNode($node->expression);
        }

        if ($node instanceof TryStatement) {
            if ($node->handler !== null && $node->handler->param !== null) {
                $this->checkStrictCatchParam($node->handler->param);
            }
            $this->validateStrictModeNode($node->block);
            if ($node->handler !== null) {
                $this->validateStrictModeNode($node->handler->body);
            }
            if ($node->finalizer !== null) {
                $this->validateStrictModeNode($node->finalizer);
            }
            return;
        }

        // Recurse into child statements (but not into function bodies,
        // which are their own strict-mode contexts).
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $child) {
                $this->validateStrictModeNode($child);
            }
        } elseif ($node instanceof IfStatement) {
            $this->validateStrictModeNode($node->consequent);
            if ($node->alternate !== null) {
                $this->validateStrictModeNode($node->alternate);
            }
        } elseif ($node instanceof ForStatement || $node instanceof ForInStatement || $node instanceof ForOfStatement) {
            $this->validateStrictModeNode($node->body);
        } elseif ($node instanceof WhileStatement || $node instanceof DoWhileStatement) {
            $this->validateStrictModeNode($node->body);
        } elseif ($node instanceof LabeledStatement) {
            $this->validateStrictModeNode($node->body);
        } elseif ($node instanceof SwitchStatement) {
            foreach ($node->cases as $case) {
                if ($case instanceof SwitchCase) {
                    foreach ($case->consequent as $child) {
                        $this->validateStrictModeNode($child);
                    }
                }
            }
        }
    }

    private function checkStrictCatchParam(Node $param): void
    {
        if ($param instanceof Identifier) {
            if ($param->name === 'eval' || $param->name === 'arguments') {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Binding 'eval' or 'arguments' in strict mode catch is not allowed",
                );
            }
            if ($this->isStrictReservedWord($param->name)) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Unexpected strict mode reserved word '{$param->name}'",
                );
            }
        }
    }

    /**
     * Check that binding names in a pattern do not use strict-mode reserved words.
     */
    private function checkStrictBindingNames(Node $node): void
    {
        if ($node instanceof Identifier) {
            if (
                $this->isStrictReservedWord($node->name)
                || $node->name === 'eval'
                || $node->name === 'arguments'
            ) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Unexpected strict mode reserved word '{$node->name}'",
                );
            }
        } elseif ($node instanceof ArrayPattern) {
            foreach ($node->elements as $el) {
                if ($el !== null) {
                    $this->checkStrictBindingNames($el);
                }
            }
        } elseif ($node instanceof ObjectPattern) {
            foreach ($node->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->checkStrictBindingNames($prop->value);
                } elseif ($prop instanceof RestElement) {
                    $this->checkStrictBindingNames($prop->argument);
                }
            }
        } elseif ($node instanceof RestElement) {
            $this->checkStrictBindingNames($node->argument);
        } elseif ($node instanceof AssignmentPattern) {
            $this->checkStrictBindingNames($node->left);
        }
    }

    /**
     * Check that expressions do not assign to strict-mode reserved words.
     */
    private function checkStrictExpressionNode(Node $node): void
    {
        if ($node instanceof AssignmentExpression && $node->left instanceof Identifier) {
            if ($this->isStrictReservedWord($node->left->name)) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    "Unexpected strict mode reserved word '{$node->left->name}'",
                );
            }
        }
    }

    /**
     * Collect all var-declared names from eval code's top-level statements.
     * Does not descend into function/class bodies (they create their own scope).
     *
     * @param Node[] $statements
     * @return list<string>
     */
    private function collectEvalVarNames(array $statements): array
    {
        $names = [];
        foreach ($statements as $stmt) {
            $this->collectVarNamesFromNode($stmt, $names);
        }
        return $names;
    }

    /**
     * Recursively collect var-declared names from a node.
     * Stops at function/class boundaries.
     *
     * @param list<string> $names collected var names (passed by reference)
     */
    private function collectVarNamesFromNode(Node $node, array &$names): void
    {
        if ($node instanceof VariableDeclaration && $node->kind === 'var') {
            foreach ($node->declarations as $decl) {
                foreach ($this->patternBoundNames($decl->id) as $n) {
                    $names[] = $n;
                }
            }
            return;
        }
        if ($node instanceof FunctionDeclaration) {
            if ($node->id !== null) {
                $names[] = $node->id->name;
            }
            return;
        }

        // Stop at function/class expression boundaries.
        if (
            $node instanceof FunctionExpression
            || $node instanceof ArrowFunction
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return;
        }

        // Recurse into compound statements.
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $child) {
                $this->collectVarNamesFromNode($child, $names);
            }
        } elseif ($node instanceof IfStatement) {
            $this->collectVarNamesFromNode($node->consequent, $names);
            if ($node->alternate !== null) {
                $this->collectVarNamesFromNode($node->alternate, $names);
            }
        } elseif ($node instanceof ForStatement) {
            if ($node->init instanceof VariableDeclaration && $node->init->kind === 'var') {
                foreach ($node->init->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $n) {
                        $names[] = $n;
                    }
                }
            }
            $this->collectVarNamesFromNode($node->body, $names);
        } elseif ($node instanceof ForInStatement || $node instanceof ForOfStatement) {
            if ($node->left instanceof VariableDeclaration && $node->left->kind === 'var') {
                foreach ($node->left->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $n) {
                        $names[] = $n;
                    }
                }
            }
            $this->collectVarNamesFromNode($node->body, $names);
        } elseif ($node instanceof WhileStatement || $node instanceof DoWhileStatement) {
            $this->collectVarNamesFromNode($node->body, $names);
        } elseif ($node instanceof SwitchStatement) {
            foreach ($node->cases as $case) {
                if ($case instanceof SwitchCase) {
                    foreach ($case->consequent as $child) {
                        $this->collectVarNamesFromNode($child, $names);
                    }
                }
            }
        } elseif ($node instanceof TryStatement) {
            $this->collectVarNamesFromNode($node->block, $names);
            if ($node->handler !== null) {
                $this->collectVarNamesFromNode($node->handler->body, $names);
            }
            if ($node->finalizer !== null) {
                $this->collectVarNamesFromNode($node->finalizer, $names);
            }
        } elseif ($node instanceof LabeledStatement) {
            $this->collectVarNamesFromNode($node->body, $names);
        } elseif ($node instanceof WithStatement) {
            $this->collectVarNamesFromNode($node->body, $names);
        }
    }

    /**
     * Check if the eval code contains new.target (represented as [[NewTarget]] identifier).
     * Per spec, new.target in eval is SyntaxError unless the eval is contained in
     * function code that is not the function code of an ArrowFunction.
     *
     * @param Node[] $statements
     */
    private function astContainsNewTarget(array $statements): bool
    {
        foreach ($statements as $stmt) {
            if ($this->nodeContainsNewTarget($stmt)) {
                return true;
            }
        }
        return false;
    }

    private function nodeContainsNewTarget(Node $node): bool
    {
        if ($node instanceof Identifier && $node->name === '[[NewTarget]]') {
            return true;
        }

        // Stop at function/class boundaries.
        if (
            $node instanceof FunctionDeclaration
            || $node instanceof FunctionExpression
            || $node instanceof ArrowFunction
            || $node instanceof ClassDeclaration
            || $node instanceof ClassExpression
        ) {
            return false;
        }

        if ($node instanceof ExpressionStatement) {
            return $this->nodeContainsNewTarget($node->expression);
        }
        if ($node instanceof BlockStatement) {
            return $this->astContainsNewTarget($node->body);
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                if ($decl->init !== null && $this->nodeContainsNewTarget($decl->init)) {
                    return true;
                }
            }
        }
        if ($node instanceof CallExpression) {
            if ($this->nodeContainsNewTarget($node->callee)) {
                return true;
            }
            foreach ($node->arguments as $arg) {
                if ($this->nodeContainsNewTarget($arg)) {
                    return true;
                }
            }
        }
        if ($node instanceof MemberExpression) {
            return $this->nodeContainsNewTarget($node->object) || $this->nodeContainsNewTarget($node->property);
        }
        if ($node instanceof AssignmentExpression) {
            return $this->nodeContainsNewTarget($node->left) || $this->nodeContainsNewTarget($node->right);
        }
        if ($node instanceof BinaryExpression) {
            return $this->nodeContainsNewTarget($node->left) || $this->nodeContainsNewTarget($node->right);
        }
        if ($node instanceof IfStatement) {
            if ($this->nodeContainsNewTarget($node->test)) {
                return true;
            }
            if ($this->nodeContainsNewTarget($node->consequent)) {
                return true;
            }
            return $node->alternate !== null && $this->nodeContainsNewTarget($node->alternate);
        }
        if ($node instanceof TryStatement) {
            if ($this->astContainsNewTarget($node->block->body)) {
                return true;
            }
            if ($node->handler !== null && $this->astContainsNewTarget($node->handler->body->body)) {
                return true;
            }
            return $node->finalizer !== null && $this->astContainsNewTarget($node->finalizer->body);
        }

        return false;
    }

    /**
     * Hoist class and let/const declarations in eval code as TDZ bindings.
     *
     * Per spec, class declarations in eval create bindings that throw
     * ReferenceError when accessed before initialization (TDZ).
     *
     * @param Node[] $statements
     */
    private function hoistEvalLexicalDeclarations(array $statements, Environment $env): void
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof ClassDeclaration && $stmt->id !== null) {
                $env->declareLet($stmt->id->name);
            }
            if ($stmt instanceof VariableDeclaration && ($stmt->kind === 'let' || $stmt->kind === 'const')) {
                foreach ($stmt->declarations as $decl) {
                    $this->declarePatternTdz($decl->id, $env, $stmt->kind === 'const');
                }
            }
        }
    }

    /**
     * Declare a binding pattern as TDZ bindings.
     */
    private function declarePatternTdz(Node $pattern, Environment $env, bool $isConst): void
    {
        if ($pattern instanceof Identifier) {
            if ($isConst) {
                $env->declareConst($pattern->name);
            } else {
                $env->declareLet($pattern->name);
            }
        } elseif ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $this->declarePatternTdz($elem, $env, $isConst);
                }
            }
        } elseif ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->declarePatternTdz($prop->value, $env, $isConst);
                } elseif ($prop instanceof RestElement) {
                    $this->declarePatternTdz($prop->argument, $env, $isConst);
                }
            }
        } elseif ($pattern instanceof AssignmentPattern) {
            $this->declarePatternTdz($pattern->left, $env, $isConst);
        } elseif ($pattern instanceof RestElement) {
            $this->declarePatternTdz($pattern->argument, $env, $isConst);
        }
    }

    private function evalNewExpression(NewExpression $node, Environment $env): JsValue
    {
        $callee = $this->evaluate($node->callee, $env);

        // Per spec EvaluateNew: arguments are evaluated BEFORE the IsConstructor check (step 6 before step 7).
        $args = $this->evaluateArguments($node->arguments, $env);

        // Proxy construct trap: if the callee is a Proxy, invoke its construct().
        if ($callee instanceof \PhpJs\Value\JsProxy) {
            return $callee->construct($args, $callee);
        }

        if (!$callee instanceof JsFunction || !$callee->isConstructable()) {
            throw new TypeError(TypeConversion::toString($callee) . ' is not a constructor');
        }

        // Create a new object with the constructor's prototype
        $proto = $callee->get('prototype');
        $newObj = new JsObject($proto instanceof JsObject ? $proto : null);
        // Mark as new.target so constructors can detect new vs call.
        // Use a non-enumerable, non-configurable property so it does not leak into iteration.
        $newObj->defineOwnProperty(
            '[[NewTarget]]',
            \PhpJs\Object\PropertyDescriptor::data($callee, false, false, false),
        );

        $result = $this->callFunction($callee, $newObj, $args);

        // Per spec §10.2.2 [[Construct]]:
        // - If the constructor returned an Object, use that.
        // - If derived class constructor returned a non-Object non-undefined value, throw TypeError.
        // - Otherwise return newObj.
        if ($result instanceof JsObject) {
            return $result;
        }
        if ($callee->isDerivedConstructor() && !$result instanceof JsUndefined) {
            throw new TypeError('Derived constructors may only return object or undefined');
        }
        return $newObj;
    }

    /**
     * @param Node[] $argNodes
     * @return JsValue[]
     */
    private function evaluateArguments(array $argNodes, Environment $env): array
    {
        $args = [];
        foreach ($argNodes as $argNode) {
            if ($argNode instanceof SpreadElement) {
                $iterable = $this->evaluate($argNode->argument, $env);
                $this->spreadInto($iterable, $args);
            } else {
                $args[] = $this->evaluate($argNode, $env);
            }
        }
        return $args;
    }

    /**
     * Spread an iterable value into the target array using the iterator protocol.
     *
     * @param list<JsValue> $target
     */
    private function spreadInto(JsValue $iterable, array &$target): void
    {
        $iterator = $this->getIterator($iterable);
        if ($iterator !== null) {
            $nextMethod = $iterator->get('next');
            if ($nextMethod instanceof JsFunction) {
                while (true) {
                    $result = $this->callFunction($nextMethod, $iterator, []);
                    if (!$result instanceof JsObject) {
                        break;
                    }
                    if (TypeConversion::toBoolean($result->get('done'))) {
                        break;
                    }
                    $target[] = $result->get('value');
                }
                return;
            }
        }

        // Fallback for plain JsArray without Symbol.iterator.
        if ($iterable instanceof JsArray) {
            $len = $iterable->getLength();
            for ($i = 0; $i < $len; $i++) {
                $target[] = $iterable->get((string) $i);
            }
            return;
        }

        // Per spec, if the value is not iterable, throw TypeError.
        throw new TypeError(
            TypeConversion::toString($iterable) . ' is not iterable',
        );
    }

    /** @param JsValue[] $args */
    public function callFunction(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
    ): JsValue {
        // Per spec: class constructors cannot be called without `new`.
        if ($fn->isClassConstructor()) {
            $calledAsNew = $thisValue instanceof JsObject
                && !($thisValue->get('[[NewTarget]]') instanceof JsUndefined);
            if (!$calledAsNew) {
                throw new TypeError("Class constructor {$fn->getName()} cannot be invoked without 'new'");
            }
        }

        // Native (PHP callable) function
        $nativeFn = $fn->getNativeCallable();
        if ($nativeFn !== null) {
            return $nativeFn($thisValue, $args, $this);
        }

        // Generator function: return a JsGenerator instead of executing.
        if ($fn->isGenerator()) {
            return $this->createGenerator($fn, $thisValue, $args);
        }

        // Async function: execute the body and wrap the result in a Promise.
        if ($fn->isAsync()) {
            return $this->executeAsyncFunction($fn, $thisValue, $args);
        }

        // Interpreted function
        return $this->executeFunction($fn, $thisValue, $args);
    }

    /**
     * Install the .prototype property on a newly created function.
     *
     * For normal functions: creates {constructor: fn} with Object.prototype as prototype.
     * For generator functions: creates a plain object with %GeneratorPrototype% as prototype,
     * NO constructor property (per spec §27.3.4).
     */
    private function installFunctionPrototype(JsFunction $fn, bool $isGenerator): void
    {
        if ($isGenerator) {
            // Per spec §27.3.4.3: generator function's .prototype is a plain object
            // whose [[Prototype]] is %GeneratorPrototype%. No own properties.
            $generatorProto = null;
            if ($this->globalEnv->has('__GeneratorPrototype__')) {
                $gp = $this->globalEnv->get('__GeneratorPrototype__');
                if ($gp instanceof JsObject) {
                    $generatorProto = $gp;
                }
            }
            $proto = new JsObject($generatorProto);
            // Non-constructable: generators can't be called with new.
            $fn->setNonConstructable();
        } else {
            $proto = new JsObject();
            // Per spec, constructor is writable, non-enumerable, configurable.
            $proto->defineOwnProperty('constructor', PropertyDescriptor::data($fn, true, false, true));
        }
        // Per spec §27.3.4 / §10.2.4: .prototype is writable, non-enumerable, non-configurable for generators;
        // writable, non-enumerable, non-configurable for regular functions too.
        $fn->defineOwnProperty('prototype', PropertyDescriptor::data($proto, true, false, false));
    }

    /**
     * Execute an interpreted (non-generator) function body.
     *
     * @param list<JsValue> $args
     */
    private function executeFunction(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
    ): JsValue {
        $this->callStack->push($fn->getName(), 0);

        // Save and potentially update strict mode for this function body.
        $previousStrictMode = $this->strictMode;

        try {
            $fnEnv = $fn->getClosure()->createChild();

            // Tag the environment with the function kind so
            // EvalDeclarationInstantiation can enforce restrictions.
            if ($fn->isArrow()) {
                $fnEnv->setFunctionKind('arrow');
            } else {
                $fnEnv->setFunctionKind('function');
            }

            // Per spec 10.2.1.2: A function's strict mode is determined by its own
            // [[Strict]] flag (set at definition time from the enclosing scope) OR by
            // a "use strict" directive in its body. The CALLER's strict mode is irrelevant.
            $body = $fn->getBody();
            $fnStrict = $fn->isStrict()
                || ($body instanceof BlockStatement && $this->hasUseStrictDirective($body->body));
            $this->strictMode = $fnStrict;

            // Per spec 9.2.1.2 OrdinaryCallBindThis:
            // In strict mode, this is passed as-is (no wrapping).
            // In sloppy mode:
            //   - null/undefined this -> globalThis
            //   - primitive this -> ToObject(this)
            if (!$fn->isArrow()) {
                if ($this->strictMode) {
                    // Strict mode: thisValue is passed as-is (no boxing, no substitution).
                    // The caller is responsible for passing the correct value.
                } else {
                    // Sloppy mode: wrap null/undefined to global, primitives to Object.
                    if ($thisValue instanceof JsUndefined || $thisValue instanceof \PhpJs\Value\JsNull) {
                        $thisValue = $this->getGlobalObject();
                    } elseif (
                        !$thisValue instanceof JsObject
                        && ($thisValue instanceof JsNumber
                            || $thisValue instanceof JsString
                            || $thisValue instanceof JsBoolean
                            || $thisValue instanceof JsSymbol)
                    ) {
                        $thisValue = TypeConversion::toObject($thisValue);
                    }
                }
            }

            // Bind this
            if ($fn->isArrow()) {
                // Arrow functions inherit this and [[NewTarget]] from closure.
            } else {
                $fnEnv->defineVar('this', $thisValue);
                // new.target: set [[NewTarget]] to the constructor when called via new,
                // or undefined otherwise. Arrow functions inherit it from the outer scope.
                if ($thisValue instanceof JsObject && $thisValue->getOwnPropertyDescriptor('[[NewTarget]]') !== null) {
                    $fnEnv->defineVar('[[NewTarget]]', $fn);
                } else {
                    $fnEnv->defineVar('[[NewTarget]]', JsUndefined::instance());
                }
            }

            // Bind [[HomeObject]] so super property references work inside this function.
            $homeObject = $fn->getHomeObject();
            if ($homeObject !== null) {
                $fnEnv->defineVar('[[HomeObject]]', $homeObject);
            }

            // For class constructors in derived classes, bind [[ActiveFunction]] so
            // super() can resolve the super constructor via the function's [[Prototype]].
            if ($fn->isClassConstructor()) {
                $fnEnv->defineVar('[[ActiveFunction]]', $fn);
            }

            // Create arguments object before binding parameters, so default
            // parameter expressions can reference `arguments`.
            $params = $fn->getParams();
            $hasDefaultParams = $this->hasParameterExpressions($params);

            if (!$fn->isArrow()) {
                $argsObj = $this->makeArgumentsObject($args, $fn, $this->strictMode);
                $fnEnv->defineVar('arguments', $argsObj);
            }

            // Bind parameters
            $this->bindParameters($params, $args, $fnEnv);

            // When the function has parameter expressions (defaults, destructuring
            // with defaults), the body gets a separate environment so closures in
            // the parameter list do not see body-scoped var declarations.
            if ($hasDefaultParams && $body instanceof BlockStatement) {
                $bodyEnv = $fnEnv->createChild();
                // Copy parameter bindings to the body environment as vars.
                foreach ($params as $p) {
                    $this->copyParamBindings($p, $fnEnv, $bodyEnv);
                }
                // arguments in body env should still be available.
                if (!$fn->isArrow()) {
                    $bodyEnv->defineVar('arguments', $fnEnv->get('arguments'));
                }
                // Force-hoist var names into bodyEnv so they shadow parent bindings.
                // This is necessary because the body environment is separate from
                // the parameter environment, and var declarations must create
                // bindings in the body scope, not update parent scope bindings.
                $this->forceHoistVarNames($body->body, $bodyEnv);
                $this->hoistDeclarations($body->body, $bodyEnv);
                $this->hoistEvalLexicalDeclarations($body->body, $bodyEnv);
                $completion = $this->executeBody($body->body, $bodyEnv);
                if ($completion->type === CompletionType::Return) {
                    return $completion->value;
                }
                if ($completion->type === CompletionType::Throw) {
                    $this->throwJsValue($completion->value);
                }
                return JsUndefined::instance();
            }

            // Execute body
            if ($body instanceof BlockStatement) {
                // Force-hoist var names into the function scope first so that
                // a var declaration in a nested function always creates a binding
                // in the function's own scope, even when a parent scope (e.g. global)
                // has a const/let binding with the same name.
                $this->forceHoistVarNames($body->body, $fnEnv);
                $this->hoistDeclarations($body->body, $fnEnv);
                $this->hoistEvalLexicalDeclarations($body->body, $fnEnv);
                $completion = $this->executeBody($body->body, $fnEnv);
                if ($completion->type === CompletionType::Return) {
                    return $completion->value;
                }
                if ($completion->type === CompletionType::Throw) {
                    $this->throwJsValue($completion->value);
                }
                return JsUndefined::instance();
            }

            // Arrow with expression body
            return $this->evaluate($body, $fnEnv);
        } finally {
            $this->strictMode = $previousStrictMode;
            $this->callStack->pop();
        }
    }

    /**
     * Execute an async function and wrap the result in a Promise.
     *
     * In our synchronous model, the async function body runs to completion
     * immediately. If it returns normally, we create a fulfilled promise.
     * If it throws, we create a rejected promise. Await expressions inside
     * the body extract values from promises synchronously.
     *
     * @param list<JsValue> $args
     */
    private function executeAsyncFunction(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
    ): \PhpJs\Value\JsPromise {
        try {
            $result = $this->executeFunction($fn, $thisValue, $args);
            // If the result is already a promise, return it directly.
            if ($result instanceof \PhpJs\Value\JsPromise) {
                return $result;
            }
            return \PhpJs\Value\JsPromise::resolved($result);
        } catch (\PhpJs\Exceptions\JsThrowable $e) {
            return \PhpJs\Value\JsPromise::rejected($e->jsValue);
        } catch (\PhpJs\Exceptions\RuntimeError $e) {
            return \PhpJs\Value\JsPromise::rejected($this->phpExceptionToJsValue($e));
        }
    }

    /**
     * Create a JsGenerator for a generator function call.
     *
     * Instead of executing the body immediately, we wrap it in a Fiber
     * that the JsGenerator controls. The Fiber runs the function body
     * and suspends each time a yield expression is encountered.
     *
     * @param list<JsValue> $args
     */
    private function createGenerator(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
    ): JsGenerator {
        // Per spec, parameter binding (FunctionDeclarationInstantiation) happens
        // synchronously when the generator function is called, before the generator
        // object is returned. Only the body execution is deferred to the Fiber.
        $fnEnv = $fn->getClosure()->createChild();
        $fnEnv->setFunctionKind($fn->isAsync() ? 'async-generator' : 'generator');
        $fnEnv->defineVar('this', $thisValue);
        $argsObj = $this->makeArgumentsObject($args, $fn, $this->strictMode);
        $fnEnv->defineVar('arguments', $argsObj);
        $this->bindParameters($fn->getParams(), $args, $fnEnv);

        $interpreter = $this;

        $executor = function (
            JsFunction $fn,
            JsValue $thisValue,
            array $args,
        ) use (
            $interpreter,
            $fnEnv
): JsValue {
            return $interpreter->executeGeneratorBody($fn, $thisValue, $args, $fnEnv);
        };

        return new JsGenerator($fn, $thisValue, $args, $executor);
    }

    /**
     * Execute a generator function body inside a Fiber.
     *
     * Called from inside the Fiber created by JsGenerator. This sets up the
     * environment and runs the body. When a yield expression is hit, the
     * interpreter calls Fiber::suspend(), which pauses execution until the
     * generator's next() method resumes the Fiber.
     *
     * @param list<JsValue> $args
     */
    public function executeGeneratorBody(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
        ?Environment $prebuiltEnv = null,
    ): JsValue {
        $this->callStack->push($fn->getName(), 0);

        try {
            // Use pre-built environment if provided (parameters already bound).
            if ($prebuiltEnv !== null) {
                $fnEnv = $prebuiltEnv;
            } else {
                $fnEnv = $fn->getClosure()->createChild();
                $fnEnv->defineVar('this', $thisValue);
                $argsObj = $this->makeArgumentsObject($args, $fn, $this->strictMode);
                $fnEnv->defineVar('arguments', $argsObj);
                $this->bindParameters($fn->getParams(), $args, $fnEnv);
            }

            // Execute body
            $body = $fn->getBody();
            if ($body instanceof BlockStatement) {
                $this->forceHoistVarNames($body->body, $fnEnv);
                $this->hoistDeclarations($body->body, $fnEnv);
                $this->hoistEvalLexicalDeclarations($body->body, $fnEnv);
                $completion = $this->executeBody($body->body, $fnEnv);
                if ($completion->type === CompletionType::Return) {
                    return $completion->value;
                }
                if ($completion->type === CompletionType::Throw) {
                    $this->throwJsValue($completion->value);
                }
                return JsUndefined::instance();
            }

            return $this->evaluate($body, $fnEnv);
        } finally {
            $this->callStack->pop();
        }
    }

    /**
     * @param Node[] $params
     * @param JsValue[] $args
     */
    /**
     * Create an arguments exotic object per ES spec 10.4.4.
     *
     * Non-strict: includes callee pointing to the function.
     * Strict or arrow: callee/caller are poison-pill accessors that throw TypeError.
     * Symbol.iterator is linked to Array.prototype[Symbol.iterator] if available.
     *
     * @param list<JsValue> $args
     */
    private function makeArgumentsObject(array $args, ?JsFunction $fn, bool $strictMode): JsObject
    {
        $argsObj = new JsObject();
        $argsObj->defineOwnProperty('[[IsArguments]]', PropertyDescriptor::data(
            new JsBoolean(true),
            writable: false,
            enumerable: false,
            configurable: false,
        ));
        $argsObj->defineOwnProperty('length', PropertyDescriptor::data(
            new JsNumber((float) count($args)),
            writable: true,
            enumerable: false,
            configurable: true,
        ));
        foreach ($args as $i => $arg) {
            $argsObj->defineOwnProperty((string) $i, PropertyDescriptor::data(
                $arg,
                writable: true,
                enumerable: true,
                configurable: true,
            ));
        }
        if (!$strictMode && $fn !== null) {
            $argsObj->defineOwnProperty('callee', PropertyDescriptor::data(
                $fn,
                writable: true,
                enumerable: false,
                configurable: true,
            ));
        } else {
            // Reuse the shared %ThrowTypeError% intrinsic if available,
            // so that the function identity matches across the engine per spec.
            $poisonPill = null;
            if ($this->globalEnv->has('__ThrowTypeError__')) {
                $tte = $this->globalEnv->get('__ThrowTypeError__');
                if ($tte instanceof JsFunction) {
                    $poisonPill = $tte;
                }
            }
            if ($poisonPill === null) {
                $poisonPill = JsFunction::fromCallable(
                    'ThrowTypeError',
                    function (): JsValue {
                        throw new TypeError(
                            "'caller', 'callee', and 'arguments' properties may not be accessed "
                            . 'on strict mode functions or the arguments objects for calls to them',
                        );
                    },
                );
            }
            $argsObj->defineOwnProperty('callee', PropertyDescriptor::accessor(
                get: $poisonPill,
                set: $poisonPill,
                enumerable: false,
                configurable: false,
            ));
            $argsObj->defineOwnProperty('caller', PropertyDescriptor::accessor(
                get: $poisonPill,
                set: $poisonPill,
                enumerable: false,
                configurable: false,
            ));
        }
        // Link Symbol.iterator to Array.prototype[Symbol.iterator] if available.
        $iterSym = \PhpJs\BuiltIn\SymbolConstructor::iterator();
        $arrayIterFn = null;
        $arrayProto = JsArray::getGlobalPrototype();
        if ($arrayProto !== null && $arrayProto->hasBySymbol($iterSym)) {
            $iterVal = $arrayProto->getBySymbol($iterSym);
            if ($iterVal instanceof JsFunction) {
                $arrayIterFn = $iterVal;
            }
        }
        if ($arrayIterFn === null) {
            $arrayIterFn = JsFunction::fromCallable('values', function (JsValue $self_) use ($iterSym): JsValue {
                $obj = $self_ instanceof JsObject ? $self_ : new JsObject();
                $index = 0;
                $len = ($obj instanceof JsArray)
                    ? $obj->getLength()
                    : (int) \PhpJs\Spec\TypeConversion::toNumber($obj->get('length'));
                $iterator = new JsObject();
                $nextFn = function () use ($obj, &$index, $len): JsValue {
                    $result = new JsObject();
                    if ($index < $len) {
                        $result->set('value', $obj->get((string) $index));
                        $result->set('done', new JsBoolean(false));
                        $index++;
                    } else {
                        $result->set('value', JsUndefined::instance());
                        $result->set('done', new JsBoolean(true));
                    }
                    return $result;
                };
                $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
                $selfFn = JsFunction::fromCallable(
                    '[Symbol.iterator]',
                    function (JsValue $s): JsValue {
                        return $s;
                    },
                );
                $iterator->setBySymbol($iterSym, $selfFn);
                return $iterator;
            });
        }
        $argsObj->definePropertyBySymbol($iterSym, PropertyDescriptor::data(
            $arrayIterFn,
            writable: true,
            enumerable: false,
            configurable: true,
        ));
        return $argsObj;
    }

    private function bindParameters(array $params, array $args, Environment $env): void
    {
        // Per spec §10.2.1: when there are parameter default expressions,
        // all parameter names are initially in TDZ. Each is initialized in order,
        // so a default like `x = x` sees `x` as TDZ and throws ReferenceError.
        if ($this->hasParameterExpressions($params)) {
            foreach ($params as $param) {
                $target = $param instanceof RestElement
                    ? $param->argument
                    : ($param instanceof AssignmentPattern ? $param->left : $param);
                foreach ($this->patternBoundNames($target) as $name) {
                    $env->declareLet($name);
                }
            }
        }

        for ($i = 0; $i < count($params); $i++) {
            $param = $params[$i];
            $value = $args[$i] ?? JsUndefined::instance();

            if ($param instanceof RestElement) {
                $restArray = JsArray::fromArray(array_slice($args, $i));
                $this->bindPattern($param->argument, $restArray, $env);
                break;
            }

            if ($param instanceof AssignmentPattern) {
                if ($value instanceof JsUndefined) {
                    $value = $this->evaluate($param->right, $env);
                }
                $this->bindPattern($param->left, $value, $env);
                continue;
            }

            $this->bindPattern($param, $value, $env);
        }
    }

    /**
     * Check whether any parameter uses a default value or destructuring with
     * a default, which requires a separate body environment per spec.
     *
     * @param Node[] $params
     */
    private function hasParameterExpressions(array $params): bool
    {
        foreach ($params as $param) {
            if ($param instanceof AssignmentPattern) {
                return true;
            }
            if ($param instanceof ArrayPattern || $param instanceof ObjectPattern) {
                if ($this->patternHasDefaults($param)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function patternHasDefaults(Node $pattern): bool
    {
        if ($pattern instanceof AssignmentPattern) {
            return true;
        }
        if ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null && $this->patternHasDefaults($elem)) {
                    return true;
                }
            }
        }
        if ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty && $this->patternHasDefaults($prop->value)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Copy parameter name bindings from param environment to body environment.
     */
    private function copyParamBindings(Node $param, Environment $source, Environment $target): void
    {
        if ($param instanceof Identifier) {
            $target->defineVar($param->name, $source->get($param->name));
        } elseif ($param instanceof AssignmentPattern) {
            $this->copyParamBindings($param->left, $source, $target);
        } elseif ($param instanceof ArrayPattern) {
            foreach ($param->elements as $elem) {
                if ($elem !== null) {
                    $this->copyParamBindings($elem, $source, $target);
                }
            }
        } elseif ($param instanceof ObjectPattern) {
            foreach ($param->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->copyParamBindings($prop->value, $source, $target);
                } elseif ($prop instanceof RestElement) {
                    $this->copyParamBindings($prop->argument, $source, $target);
                }
            }
        } elseif ($param instanceof RestElement) {
            $this->copyParamBindings($param->argument, $source, $target);
        }
    }

    private function bindPattern(Node $pattern, JsValue $value, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            // Use initializeTdz so that if the name was pre-declared in TDZ
            // (for default-param TDZ semantics), we properly clear it.
            // If not in TDZ, this falls back to defineVar.
            $env->initializeTdz($pattern->name, $value);
            return;
        }

        if ($pattern instanceof ArrayPattern) {
            $this->bindArrayPattern($pattern, $value, $env);
            return;
        }

        if ($pattern instanceof ObjectPattern) {
            $this->bindObjectPattern($pattern, $value, $env);
            return;
        }

        if ($pattern instanceof AssignmentPattern) {
            if ($value instanceof JsUndefined) {
                $value = $this->evaluate($pattern->right, $env);
                // Function name inference: only when the default is an anonymous function
                // definition (not a sequence expression, etc.) and the binding is a simple identifier.
                // Per spec 13.3.3.7: check HasOwnProperty('name') — only infer if name is absent
                // or is the default empty string (anonymous), not if overridden (e.g. static name() {}).
                if (
                    $value instanceof JsFunction
                    && $pattern->left instanceof Identifier
                    && $this->isAnonymousFunctionDefinitionNode($pattern->right)
                ) {
                    $nameDesc = $value->getOwnPropertyDescriptor('name');
                    $isEmptyName = $nameDesc === null
                        || ($nameDesc->value instanceof JsString && $nameDesc->value->value === '');
                    if ($isEmptyName) {
                        $value->setName($pattern->left->name);
                    }
                }
            }
            $this->bindPattern($pattern->left, $value, $env);
            return;
        }

        throw new InternalError('Unknown parameter pattern: ' . $pattern->type());
    }

    private function bindArrayPattern(ArrayPattern $pattern, JsValue $value, Environment $env): void
    {
        if ($value instanceof JsNull || $value instanceof JsUndefined) {
            throw new \PhpJs\Exceptions\TypeError(
                TypeConversion::toString($value) . ' is not iterable',
            );
        }
        [$iterator, $nextMethod] = $this->getIteratorOrThrow($value);
        $done = false;
        foreach ($pattern->elements as $element) {
            if ($element instanceof RestElement) {
                $this->bindPattern($element->argument, $this->iteratorRest($iterator, $nextMethod, $done), $env);
                $done = true; // rest consumes remaining elements
                break;
            }
            $elemValue = $this->iteratorNext($iterator, $nextMethod, $done);
            if ($element === null) {
                // Elision: advance iterator but discard value.
                continue;
            }
            $this->bindPattern($element, $elemValue, $env);
        }
        // Per spec 13.3.3.5: if iterator is not exhausted, close it via return().
        if (!$done && $iterator instanceof JsObject) {
            $returnMethod = $iterator->get('return');
            if ($returnMethod instanceof JsFunction) {
                $this->callFunction($returnMethod, $iterator, []);
            }
        }
    }

    private function bindObjectPattern(ObjectPattern $pattern, JsValue $value, Environment $env): void
    {
        if ($value instanceof JsNull || $value instanceof JsUndefined) {
            throw new \PhpJs\Exceptions\TypeError(
                "Cannot destructure property of " . TypeConversion::toString($value),
            );
        }
        $usedKeys = [];
        foreach ($pattern->properties as $prop) {
            if ($prop instanceof RestElement) {
                $restObj = new JsObject();
                if ($value instanceof JsObject) {
                    // Per spec: object rest only includes own enumerable properties.
                    foreach ($value->getOwnEnumerableKeys() as $key) {
                        if (!in_array($key, $usedKeys, true)) {
                            $restObj->set($key, $value->get($key));
                        }
                    }
                }
                $this->bindPattern($prop->argument, $restObj, $env);
                continue;
            }

            if ($prop instanceof AssignmentProperty) {
                $key = $prop->computed
                    ? TypeConversion::toString($this->evaluate($prop->key, $env))
                    : ($prop->key instanceof Identifier
                        ? $prop->key->name
                        : TypeConversion::toString($this->evaluate($prop->key, $env)));
                $usedKeys[] = $key;
                $propValue = ($value instanceof JsObject)
                    ? $value->get($key)
                    : JsUndefined::instance();
                $this->bindPattern($prop->value, $propValue, $env);
            }
        }
    }

    private function evalMemberExpression(MemberExpression $node, Environment $env): JsValue
    {
        // super.prop or super[expr]: look up on [[HomeObject]].[[Prototype]].
        if ($node->object instanceof Identifier && $node->object->name === 'super') {
            try {
                $homeObject = $env->get('[[HomeObject]]');
            } catch (\Throwable) {
                $homeObject = null;
            }
            $superBase = $homeObject instanceof JsObject ? $homeObject->getPrototype() : null;
            // RequireObjectCoercible: if superBase is null, throw TypeError (spec §12.3.5.3 step 5).
            if ($superBase === null) {
                throw new TypeError(
                    "Cannot read properties of undefined (super)",
                );
            }
            // Use the actual `this` as the receiver so getters are called with the right context.
            try {
                $superThisRead = $env->get('this');
            } catch (\Throwable) {
                $superThisRead = null;
            }
            $superRecvRead = $superThisRead instanceof JsObject ? $superThisRead : $superBase;
            if ($node->computed) {
                $rawKey = $this->evaluate($node->property, $env);
                if ($rawKey instanceof JsSymbol) {
                    return $superBase->getBySymbolWithReceiver($rawKey, $superRecvRead);
                }
                return $superBase->internalGet(TypeConversion::toString($rawKey), $superRecvRead);
            }
            $key = $node->property instanceof Identifier
                ? $node->property->name
                : TypeConversion::toString($this->evaluate($node->property, $env));
            return $superBase->internalGet($key, $superRecvRead);
        }

        $obj = $this->evaluate($node->object, $env);

        // Propagate optional chain short-circuit through the chain.
        if ($obj instanceof JsOptionalUndefined) {
            return $obj;
        }

        if ($node->optional && ($obj instanceof JsNull || $obj instanceof JsUndefined)) {
            return JsOptionalUndefined::instance();
        }

        // Evaluate the property key expression. For computed access, the key
        // expression is evaluated first, but ToPropertyKey (which calls toString)
        // is deferred until after ToObject(base), per spec 13.3.3.
        $rawKey = null;
        if ($node->computed) {
            $rawKey = $this->evaluate($node->property, $env);
        }

        // Per spec: ToObject(base) precedes ToPropertyKey(key). Accessing a
        // property on null or undefined must throw TypeError before converting
        // the key, so toString() on the key object is never called.
        if ($obj instanceof JsNull || $obj instanceof JsUndefined) {
            $baseDesc = $obj instanceof JsNull ? 'null' : 'undefined';
            // Use display() for the error message to avoid triggering toString on the key.
            $keyDesc = $node->computed
                ? ($rawKey !== null ? $rawKey->display() : '')
                : ($node->property instanceof Identifier ? $node->property->name : '');
            throw new TypeError("Cannot read properties of {$baseDesc} (reading '{$keyDesc}')");
        }

        $isSymbolKey = $rawKey instanceof JsSymbol;
        $key = $isSymbolKey ? '' : ($node->computed
            ? TypeConversion::toString($rawKey)
            : ($node->property instanceof Identifier ? $node->property->name : ''));

        // Symbol-keyed access on strings: only Symbol.iterator is meaningful.
        if ($obj instanceof JsString && $isSymbolKey) {
            $iterSym = \PhpJs\BuiltIn\SymbolConstructor::iterator();
            if ($rawKey === $iterSym) {
                return $this->createStringIteratorFactory($obj);
            }
            return JsUndefined::instance();
        }

        // String property access (length, indices, prototype methods)
        if ($obj instanceof JsString) {
            if ($key === 'length') {
                return new JsNumber((float) $obj->length());
            }
            if (ctype_digit($key)) {
                $idx = (int) $key;
                // JavaScript string indexing uses UTF-16 code units.
                $u16 = JsString::utf8ToUtf16LE($obj->value);
                $u16Len = (int) (strlen($u16) / 2);
                if ($idx >= 0 && $idx < $u16Len) {
                    $codeUnit = ord($u16[$idx * 2]) | (ord($u16[$idx * 2 + 1]) << 8);
                    return new JsString(JsString::utf16CodeUnitToUtf8($codeUnit));
                }
                return JsUndefined::instance();
            }
            // Check for String.prototype properties via global String.prototype.
            // Return the raw value (no wrapping). Method-call this-binding is
            // handled by the CallExpression evaluator, not here.
            if ($env->has('__StringPrototype__')) {
                $proto = $env->get('__StringPrototype__');
                if ($proto instanceof JsObject) {
                    $val = $proto->get($key);
                    if (!$val instanceof JsUndefined) {
                        return $val;
                    }
                }
            }
            return JsUndefined::instance();
        }

        // Symbol property access (description, toString, valueOf via Symbol.prototype)
        if ($obj instanceof JsSymbol) {
            if ($key === 'description') {
                $desc = $obj->getDescription();
                return $desc !== null ? new JsString($desc) : JsUndefined::instance();
            }
            if ($key === 'toString') {
                $sym = $obj;
                return JsFunction::fromCallable('toString', function () use ($sym): JsValue {
                    return new JsString($sym->toString());
                }, 0);
            }
            if ($key === 'valueOf') {
                $sym = $obj;
                return JsFunction::fromCallable('valueOf', function () use ($sym): JsValue {
                    return $sym;
                }, 0);
            }
            // Check Symbol.prototype from global env
            if ($env->has('__SymbolPrototype__')) {
                $proto = $env->get('__SymbolPrototype__');
                if ($proto instanceof JsObject) {
                    $val = $isSymbolKey ? $proto->getBySymbol($rawKey) : $proto->get($key);
                    if (!$val instanceof JsUndefined) {
                        return $val;
                    }
                }
            }
            return JsUndefined::instance();
        }

        // BigInt primitive property access: look up on BigInt.prototype.
        if ($obj instanceof JsBigInt) {
            $bigintProto = JsBigInt::getPrototype();
            if ($bigintProto !== null && !$isSymbolKey) {
                $val = $bigintProto->get($key);
                if (!$val instanceof JsUndefined) {
                    return $val;
                }
            }
            return JsUndefined::instance();
        }

        if ($obj instanceof JsObject) {
            // Symbol wrapper objects created via Object(sym): look up on Symbol.prototype chain
            // so that description, toString, etc. are accessible with correct this-binding.
            $ownDesc = $obj->getOwnPropertyDescriptor('[[PrimitiveValue]]');
            if ($ownDesc !== null && $ownDesc->value instanceof JsSymbol) {
                $val = $this->lookupSymbolPrototypeProperty(
                    $ownDesc->value,
                    $key,
                    $isSymbolKey,
                    $rawKey,
                );
                if ($val !== null) {
                    return $val;
                }
            }
            if ($isSymbolKey) {
                return $obj->getBySymbol($rawKey);
            }
            return $obj->get($key);
        }

        // Symbol primitive property access: look up on Symbol.prototype directly,
        // correctly calling accessor getters with the primitive as this.
        if ($obj instanceof JsSymbol) {
            $val = $this->lookupSymbolPrototypeProperty($obj, $key, $isSymbolKey, $rawKey);
            return $val ?? JsUndefined::instance();
        }

        // Auto-boxing for primitives (number, boolean)
        $boxed = TypeConversion::toObject($obj);
        if ($isSymbolKey) {
            return $boxed->getBySymbol($rawKey);
        }
        return $boxed->get($key);
    }

    /**
     * Look up a property on Symbol.prototype chain, correctly invoking accessor getters
     * with $sym as this. Returns null if not found (not JsUndefined).
     */
    private function lookupSymbolPrototypeProperty(
        JsSymbol $sym,
        string $key,
        bool $isSymbolKey,
        ?JsValue $rawKey,
    ): ?JsValue {
        $symProto = JsSymbol::getSymbolPrototype();
        if ($symProto === null) {
            return null;
        }
        // Walk the prototype chain of Symbol.prototype.
        $proto = $symProto;
        while ($proto !== null) {
            if ($isSymbolKey && $rawKey instanceof JsSymbol) {
                $desc = $proto->getSymbolPropertyDescriptor($rawKey);
            } else {
                $desc = $proto->getOwnPropertyDescriptor($key);
            }
            if ($desc !== null) {
                if ($desc->get !== null) {
                    // Accessor: call getter with the symbol primitive as this.
                    return $desc->get->call($sym, []);
                }
                return $desc->value ?? JsUndefined::instance();
            }
            $proto = $proto->getPrototype();
        }
        return null;
    }

    /** Create a factory function that returns a string iterator (for Symbol.iterator). */
    private function createStringIteratorFactory(JsString $str): JsFunction
    {
        $iteratorFactory = function () use ($str): JsValue {
            return \PhpJs\BuiltIn\StringPrototype::createStringIterator($str);
        };

        return JsFunction::fromCallable('[Symbol.iterator]', $iteratorFactory);
    }

    private function evalArrayExpression(ArrayExpression $node, Environment $env): JsValue
    {
        $arr = new JsArray();
        $index = 0;
        foreach ($node->elements as $elem) {
            if ($elem === null) {
                // Hole (elision): skip this index, leaving it as a true hole.
                $index++;
                continue;
            }
            if ($elem instanceof SpreadElement) {
                $iterable = $this->evaluate($elem->argument, $env);
                $spreadItems = [];
                $this->spreadInto($iterable, $spreadItems);
                foreach ($spreadItems as $item) {
                    $arr->set((string) $index, $item);
                    $index++;
                }
                continue;
            }
            $arr->set((string) $index, $this->evaluate($elem, $env));
            $index++;
        }
        $arr->setLength($index);
        return $arr;
    }

    private function evalObjectExpression(ObjectExpression $node, Environment $env): JsValue
    {
        $objProto = $env->has('__ObjectPrototype__')
            ? $env->get('__ObjectPrototype__')
            : null;
        $obj = new JsObject($objProto instanceof JsObject ? $objProto : null);

        foreach ($node->properties as $prop) {
            if ($prop instanceof SpreadElement) {
                $source = $this->evaluate($prop->argument, $env);
                if ($source instanceof JsObject) {
                    // Copy own enumerable string-keyed properties (CopyDataProperties).
                    foreach ($source->getOwnEnumerableKeys() as $key) {
                        $obj->set($key, $source->get($key));
                    }
                    // Copy own enumerable symbol-keyed properties (per spec CopyDataProperties).
                    foreach ($source->getOwnSymbolsWithDescriptors() as [$sym, $desc]) {
                        if ($desc->enumerable !== false) {
                            $obj->setBySymbol($sym, $desc->value ?? JsUndefined::instance());
                        }
                    }
                }
                continue;
            }

            if (!$prop instanceof Property) {
                continue;
            }

            // Evaluate computed key; may be a Symbol.
            $rawKey = null;
            $isSymbolKey = false;
            if ($prop->computed) {
                $rawKey = $this->evaluate($prop->key, $env);
                $isSymbolKey = $rawKey instanceof JsSymbol;
            }

            $key = '';
            if (!$isSymbolKey) {
                $key = $prop->computed
                    ? TypeConversion::toString($rawKey)
                    : ($prop->key instanceof Identifier
                        ? $prop->key->name
                        : ($prop->key instanceof Literal
                            ? TypeConversion::toString($this->evalLiteral($prop->key))
                            : ''));
            }

            if ($prop->kind === 'get' || $prop->kind === 'set') {
                $fn = $this->evaluate($prop->value, $env);
                if ($fn instanceof JsFunction) {
                    // Per spec, getter/setter functions are not constructable and
                    // do not have a .prototype property.
                    $fn->setNonConstructable();
                    $fn->forceDelete('prototype');
                    // Accessors in object literals have [[HomeObject]] = the object.
                    $fn->setHomeObject($obj);
                    if ($isSymbolKey) {
                        $existing = $obj->getSymbolPropertyDescriptor($rawKey);
                        if ($prop->kind === 'get') {
                            $obj->definePropertyBySymbol($rawKey, PropertyDescriptor::accessor($fn, $existing?->set));
                        } else {
                            $obj->definePropertyBySymbol($rawKey, PropertyDescriptor::accessor($existing?->get, $fn));
                        }
                    } else {
                        $existing = $obj->getOwnPropertyDescriptor($key);
                        // Use defineProperty (direct set) instead of defineOwnProperty to
                        // avoid the hasGet/hasSet merge logic re-merging the descriptor we
                        // already assembled from the existing one.
                        if ($prop->kind === 'get') {
                            $obj->defineProperty($key, PropertyDescriptor::accessor($fn, $existing?->set));
                        } else {
                            $obj->defineProperty($key, PropertyDescriptor::accessor($existing?->get, $fn));
                        }
                    }
                }
                continue;
            }

            $value = $this->evaluate($prop->value, $env);
            if ($isSymbolKey) {
                // Name inference: use Symbol description in brackets.
                if ($value instanceof JsFunction && $value->getName() === '(anonymous)') {
                    $desc = $rawKey->description;
                    $value->setName($desc !== null ? "[{$desc}]" : '');
                }
                // Method shorthand: set [[HomeObject]] for super references.
                if ($prop->method && $value instanceof JsFunction) {
                    $value->setHomeObject($obj);
                }
                $obj->setBySymbol($rawKey, $value);
            } else {
                // __proto__ assignment in object literal sets the prototype.
                if (!$prop->computed && $key === '__proto__') {
                    if ($value instanceof JsObject) {
                        $obj->setPrototype($value);
                    } elseif ($value instanceof JsNull) {
                        $obj->setPrototype(null);
                    }
                    continue;
                }
                // Name inference for property functions
                if ($value instanceof JsFunction && $value->getName() === '(anonymous)') {
                    $value->setName($key);
                }
                // Method shorthand: set [[HomeObject]] for super references.
                // Per spec, method definitions are not constructable and
                // do not have a .prototype property.
                if ($prop->method && $value instanceof JsFunction) {
                    $value->setHomeObject($obj);
                    $value->setNonConstructable();
                    $value->forceDelete('prototype');
                }
                $obj->set($key, $value);
            }
        }

        return $obj;
    }

    private function evalArrowFunction(ArrowFunction $node, Environment $env): JsValue
    {
        $fn = new JsFunction(
            '(anonymous)',
            $node->params,
            $node->body,
            $env,
            isArrow: true,
            isAsync: $node->async,
            strict: $this->strictMode,
        );
        if ($node->sourceText !== null) {
            $fn->setSourceText($node->sourceText);
        }
        return $fn;
    }

    private function evalFunctionExpression(FunctionExpression $node, Environment $env): JsValue
    {
        $fnEnv = $node->name !== null ? $env->createChild() : $env;
        $fn = new JsFunction(
            $node->name ?? '(anonymous)',
            $node->params,
            $node->body,
            $fnEnv,
            isGenerator: $node->generator,
            isAsync: $node->async,
            strict: $this->strictMode,
        );
        if ($node->sourceText !== null) {
            $fn->setSourceText($node->sourceText);
        }
        // Named function expressions can reference themselves
        if ($node->name !== null) {
            $fnEnv->defineVar($node->name, $fn);
        }
        $this->installFunctionPrototype($fn, $node->generator);
        return $fn;
    }

    /**
     * Evaluate a yield expression inside a generator function.
     *
     * This works by calling Fiber::suspend() with the yielded value.
     * The Fiber is managed by JsGenerator, which resumes us when next()
     * is called. The value passed to next() is returned by Fiber::suspend()
     * and becomes the result of the yield expression.
     *
     * For yield* (delegate), we iterate the sub-generator/iterable and
     * yield each value from it.
     */
    private function evalYieldExpression(YieldExpression $node, Environment $env): JsValue
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber === null) {
            throw new InternalError('yield expression used outside of a generator');
        }

        if ($node->delegate) {
            return $this->evalYieldDelegate($node, $env);
        }

        // Evaluate the argument (or undefined if absent).
        $value = $node->argument !== null
            ? $this->evaluate($node->argument, $env)
            : JsUndefined::instance();

        // Suspend the Fiber, yielding the value to JsGenerator::next().
        // The return value of suspend() is the value passed to the
        // subsequent next(value) call.
        $received = \Fiber::suspend($value);

        // If a GeneratorThrowSignal was thrown into the Fiber (via
        // generator.throw()), it will be thrown at this point by PHP,
        // not returned. So if we get here, we received a normal value.
        if ($received instanceof JsValue) {
            return $received;
        }

        return JsUndefined::instance();
    }

    /**
     * Handle yield* (delegate yield) per ES spec 27.5.3.7.
     *
     * Uses GetIterator to obtain the sub-iterator, then runs the full
     * delegation protocol: forwarding next(), throw(), and return() calls
     * from the outer generator to the inner iterator.
     */
    private function evalYieldDelegate(
        YieldExpression $node,
        Environment $env,
    ): JsValue {
        $iterable = $node->argument !== null
            ? $this->evaluate($node->argument, $env)
            : JsUndefined::instance();

        // Step 3: Let iterator be ? GetIterator(value).
        $iterator = $this->getIterator($iterable);
        if ($iterator === null) {
            throw new TypeError(
                TypeConversion::toString($iterable) . ' is not iterable'
            );
        }

        $nextMethod = $iterator->get('next');
        if (!$nextMethod instanceof JsFunction) {
            throw new TypeError('Iterator result next is not a function');
        }

        // Step 4: received = NormalCompletion(undefined).
        // Step 5: Repeat.
        $receivedValue = JsUndefined::instance();
        $receivedType = 'normal'; // 'normal', 'throw', or 'return'

        while (true) {
            $innerResult = null;

            // Step 5a: received is normal.
            if ($receivedType === 'normal') {
                $innerResult = $this->callFunction($nextMethod, $iterator, [$receivedValue]);
                if (!$innerResult instanceof JsObject) {
                    throw new TypeError('Iterator result is not an object');
                }
            } elseif ($receivedType === 'throw') {
                // Step 5b: received is throw.
                $throwMethod = $iterator->get('throw');
                if ($throwMethod instanceof JsUndefined || $throwMethod instanceof JsNull) {
                    // Per spec: if throw is undefined, throw a TypeError and also
                    // IteratorClose the iterator (close via return method if present).
                    try {
                        $returnMethod = $iterator->get('return');
                        if ($returnMethod instanceof JsFunction) {
                            $this->callFunction($returnMethod, $iterator, []);
                        }
                    } catch (\Throwable) {
                        // Ignore close errors per spec.
                    }
                    throw new TypeError('The iterator does not provide a throw method');
                }
                if (!$throwMethod instanceof JsFunction) {
                    throw new TypeError('The iterator does not provide a throw method');
                }
                $innerResult = $this->callFunction($throwMethod, $iterator, [$receivedValue]);
                if (!$innerResult instanceof JsObject) {
                    throw new TypeError('Iterator result is not an object');
                }
            } else {
                // Step 5c: received is return.
                $returnMethod = $iterator->get('return');
                if ($returnMethod instanceof JsUndefined || $returnMethod instanceof JsNull) {
                    // Per spec: if return is undefined, return Completion(received).
                    throw new GeneratorReturnSignal($receivedValue);
                }
                if (!$returnMethod instanceof JsFunction) {
                    throw new GeneratorReturnSignal($receivedValue);
                }
                $innerResult = $this->callFunction($returnMethod, $iterator, [$receivedValue]);
                if (!$innerResult instanceof JsObject) {
                    throw new TypeError('Iterator result is not an object');
                }
            }

            // Check done.
            $done = $innerResult->get('done');
            if (TypeConversion::toBoolean($done)) {
                // Inner iterator is done. Return its value.
                $returnVal = $innerResult->get('value');
                if ($receivedType === 'return') {
                    // For return delegation: propagate the return completion.
                    throw new GeneratorReturnSignal($returnVal);
                }
                return $returnVal;
            }

            // Not done: yield the inner result to the outer caller.
            // Per spec GeneratorYield(innerResult): the entire result object is
            // yielded without accessing its "value" property (the caller sees it).
            // We use YieldDelegateResult so JsGenerator::next() returns the inner
            // result directly instead of wrapping it in a new {value, done} object.
            try {
                $received = \Fiber::suspend(new \PhpJs\Value\YieldDelegateResult($innerResult));
                $receivedValue = $received instanceof JsValue ? $received : JsUndefined::instance();
                $receivedType = 'normal';
            } catch (GeneratorThrowSignal $e) {
                $receivedValue = $e->jsValue;
                $receivedType = 'throw';
            } catch (GeneratorReturnSignal $e) {
                $receivedValue = $e->value;
                $receivedType = 'return';
            }
        }
    }

    /**
     * Evaluate an await expression inside an async function.
     *
     * If the awaited value is a JsPromise, extract its resolved value
     * (or re-throw if rejected). If it is a thenable, resolve it.
     * Otherwise, return the value as-is (like Promise.resolve(value)).
     */
    private function evalAwaitExpression(AwaitExpression $node, Environment $env): JsValue
    {
        $value = $this->evaluate($node->argument, $env);

        // If awaiting a JsPromise, extract its state.
        if ($value instanceof \PhpJs\Value\JsPromise) {
            if ($value->getState() === \PhpJs\Value\JsPromise::STATE_REJECTED) {
                $this->throwJsValue($value->getResolvedValue());
            }
            return $value->getResolvedValue();
        }

        // If awaiting a thenable (object with .then method), resolve it.
        if ($value instanceof JsObject) {
            $thenMethod = $value->get('then');
            if ($thenMethod instanceof JsFunction) {
                $resolved = JsUndefined::instance();
                $rejected = null;
                $resolveHandler = function (JsValue $this_, array $args) use (&$resolved): JsValue {
                    $resolved = $args[0] ?? JsUndefined::instance();
                    return JsUndefined::instance();
                };
                $rejectHandler = function (JsValue $this_, array $args) use (&$rejected): JsValue {
                    $rejected = $args[0] ?? JsUndefined::instance();
                    return JsUndefined::instance();
                };
                $resolveFn = JsFunction::fromCallable('resolve', $resolveHandler, 1);
                $rejectFn = JsFunction::fromCallable('reject', $rejectHandler, 1);
                try {
                    $thenMethod->call($value, [$resolveFn, $rejectFn]);
                } catch (\Throwable $e) {
                    if ($e instanceof \PhpJs\Exceptions\JsThrowable) {
                        $this->throwJsValue($e->jsValue);
                    }
                    throw $e;
                }
                if ($rejected !== null) {
                    $this->throwJsValue($rejected);
                }
                return $resolved;
            }
        }

        return $value;
    }

    private function evalClassExpression(ClassExpression $node, Environment $env): JsValue
    {
        $cls = $this->buildClass($node->id?->name, $node->superClass, $node->body, $env);
        // Per spec, Function.prototype.toString on a class returns the full class source text.
        if ($node->sourceText !== null) {
            $cls->setSourceText($node->sourceText);
        }
        return $cls;
    }

    private function evalThisExpression(Environment $env): JsValue
    {
        if ($env->has('this')) {
            return $env->get('this');
        }
        return JsUndefined::instance();
    }

    private function evalSequence(SequenceExpression $node, Environment $env): JsValue
    {
        $result = JsUndefined::instance();
        foreach ($node->expressions as $expr) {
            $result = $this->evaluate($expr, $env);
        }
        return $result;
    }

    private function evalTemplateLiteral(TemplateLiteral $node, Environment $env): JsValue
    {
        $parts = [];
        for ($i = 0; $i < count($node->quasis); $i++) {
            $parts[] = $node->quasis[$i]->cookedValue;
            if ($i < count($node->expressions)) {
                $parts[] = TypeConversion::toString($this->evaluate($node->expressions[$i], $env));
            }
        }
        return new JsString(implode('', $parts));
    }

    private function evalTaggedTemplate(TaggedTemplate $node, Environment $env): JsValue
    {
        $tag = $this->evaluate($node->tag, $env);
        if (!$tag instanceof JsFunction) {
            throw new TypeError('Tag is not a function');
        }

        $strings = new JsArray();
        $raw = new JsArray();
        foreach ($node->quasi->quasis as $i => $quasi) {
            $strings->set((string) $i, new JsString($quasi->cookedValue));
            $raw->set((string) $i, new JsString($quasi->rawValue));
        }
        $strings->set('length', new JsNumber((float) count($node->quasi->quasis)));
        $raw->set('length', new JsNumber((float) count($node->quasi->quasis)));
        $strings->set('raw', $raw);

        $args = [$strings];
        foreach ($node->quasi->expressions as $expr) {
            $args[] = $this->evaluate($expr, $env);
        }

        return $this->callFunction($tag, JsUndefined::instance(), $args);
    }

    // -------------------------------------------------------------------------
    // Statement execution
    // -------------------------------------------------------------------------

    private function execExpressionStatement(ExpressionStatement $node, Environment $env): Completion
    {
        $value = $this->evaluate($node->expression, $env);
        return Completion::normal($value);
    }

    private function execVariableDeclaration(VariableDeclaration $node, Environment $env): Completion
    {
        foreach ($node->declarations as $declarator) {
            $hasInit = $declarator->init !== null;

            // Per spec 14.3.2.4: for var BindingIdentifier Initializer,
            // ResolveBinding is done BEFORE evaluating the Initializer.
            // In a with-environment, this means we capture which with-object
            // owns the binding before the initializer can delete it.
            $resolvedWithObj = null;
            if (
                $node->kind === 'var'
                && $hasInit
                && $declarator->id instanceof Identifier
                && !empty($this->withEnvObjects)
                && !$env->hasOwnBinding($declarator->id->name)
            ) {
                $name = $declarator->id->name;
                // Walk the env chain to find a with-environment that has this binding.
                // Only consider with-environments in the current scope chain (not from
                // unrelated with-blocks like those surrounding a function call).
                // If the current env has its own binding (hoisted var in function scope),
                // the binding resolves there, not to a with-object.
                $walkEnv = $env;
                while ($walkEnv !== null) {
                    $envId = spl_object_id($walkEnv);
                    if (isset($this->withEnvObjects[$envId])) {
                        $withObj = $this->withEnvObjects[$envId];
                        if ($withObj->has($name)) {
                            $resolvedWithObj = $withObj;
                            break;
                        }
                    }
                    $walkEnv = $walkEnv->getParent();
                }
            }

            $init = $hasInit
                ? $this->evaluate($declarator->init, $env)
                : JsUndefined::instance();

            // Name inference per spec 14.3.2.1: only when IsAnonymousFunctionDefinition is true
            // and HasOwnProperty(value, "name") is false (i.e. not explicitly overridden).
            if (
                $init instanceof JsFunction
                && $declarator->id instanceof Identifier
                && $hasInit
                && $this->isAnonymousFunctionDefinitionNode($declarator->init)
                && !$this->hasExplicitNameProperty($init)
            ) {
                $init->setName($declarator->id->name);
            }

            // For var declarations, use set() to walk up the scope chain and update the
            // hoisted binding. Without this, a var inside a for-loop or block scope would
            // shadow the hoisted binding in the enclosing function/global scope.
            // For var without initializer, skip if already defined (re-declaration is a no-op).
            if ($node->kind === 'var') {
                if ($hasInit) {
                    // If the binding was pre-resolved to a with-object, set directly
                    // on that object (spec: PutValue on the pre-resolved reference).
                    if ($resolvedWithObj !== null && $declarator->id instanceof Identifier) {
                        $resolvedWithObj->set($declarator->id->name, $init);
                    } else {
                        $this->assignVarBinding($declarator->id, $init, $env);
                    }
                } elseif ($declarator->id instanceof Identifier && !$env->has($declarator->id->name)) {
                    $env->defineVar($declarator->id->name, JsUndefined::instance());
                }
                // else: var without init and binding exists — no-op (hoisting already declared it)
            } else {
                $this->declareBinding($node->kind, $declarator->id, $init, $env);
            }
        }
        // Per spec §14.3.2.1: VariableStatement → NormalCompletion(empty).
        return new Completion(CompletionType::Normal, JsUndefined::instance(), empty: true);
    }

    /**
     * Assign a var binding value by walking the scope chain (for var with initializer).
     * This ensures that a var inside a for-loop or block scope updates the hoisted binding
     * in the enclosing function/global scope rather than creating a shadowing binding.
     */
    private function assignVarBinding(Node $pattern, JsValue $value, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            $env->set($pattern->name, $value, false);
            return;
        }
        if ($pattern instanceof ArrayPattern) {
            [$iterator, $nextMethod] = $this->getIteratorOrThrow($value);
            $done = false;
            foreach ($pattern->elements as $element) {
                if ($element instanceof RestElement) {
                    $rest = $this->iteratorRest($iterator, $nextMethod, $done);
                    $this->assignVarBinding($element->argument, $rest, $env);
                    break;
                }
                $elemValue = $this->iteratorNext($iterator, $nextMethod, $done);
                if ($element === null) {
                    continue;
                }
                $this->assignVarBinding($element, $elemValue, $env);
            }
            return;
        }
        if ($pattern instanceof ObjectPattern) {
            if ($value instanceof JsNull || $value instanceof JsUndefined) {
                throw new \PhpJs\Exceptions\TypeError(
                    "Cannot destructure property of " . TypeConversion::toString($value),
                );
            }
            $usedKeysAvb = [];
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof RestElement) {
                    $restObjAvb = new JsObject();
                    if ($value instanceof JsObject) {
                        // Per spec: object rest only includes own enumerable properties.
                        foreach ($value->getOwnEnumerableKeys() as $rk) {
                            if (!in_array($rk, $usedKeysAvb, true)) {
                                $restObjAvb->set($rk, $value->get($rk));
                            }
                        }
                    }
                    $this->assignVarBinding($prop->argument, $restObjAvb, $env);
                    break;
                }
                if ($prop instanceof AssignmentProperty) {
                    $key = $prop->computed
                        ? TypeConversion::toString($this->evaluate($prop->key, $env))
                        : ($prop->key instanceof Identifier
                            ? $prop->key->name
                            : TypeConversion::toString($this->evaluate($prop->key, $env)));
                    $usedKeysAvb[] = $key;
                    $propValue = ($value instanceof JsObject) ? $value->get($key) : JsUndefined::instance();
                    $this->assignVarBinding($prop->value, $propValue, $env);
                }
            }
            return;
        }
        if ($pattern instanceof AssignmentPattern) {
            if ($value instanceof JsUndefined) {
                $value = $this->evaluate($pattern->right, $env);
                // Function name inference: check AST node type.
                if (
                    $value instanceof JsFunction
                    && $pattern->left instanceof Identifier
                    && $this->isAnonymousFunctionDefinitionNode($pattern->right)
                ) {
                    $value->setName($pattern->left->name);
                }
            }
            $this->assignVarBinding($pattern->left, $value, $env);
        }
    }

    private function declareBinding(string $kind, Node $pattern, JsValue $value, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            match ($kind) {
                'var' => $env->defineVar($pattern->name, $value),
                'let' => $env->defineLet($pattern->name, $value),
                'const' => $env->defineConst($pattern->name, $value),
                default => $env->defineVar($pattern->name, $value),
            };
            return;
        }

        if ($pattern instanceof ArrayPattern) {
            $this->bindArrayPattern($pattern, $value, $env);
            return;
        }

        if ($pattern instanceof ObjectPattern) {
            $this->bindObjectPattern($pattern, $value, $env);
            return;
        }

        if ($pattern instanceof AssignmentPattern) {
            if ($value instanceof JsUndefined) {
                $value = $this->evaluate($pattern->right, $env);
            }
            $this->declareBinding($kind, $pattern->left, $value, $env);
        }
    }

    private function execClassDeclaration(ClassDeclaration $node, Environment $env): Completion
    {
        /** @var list<ClassMethod> $body */
        $body = $node->body;
        $cls = $this->buildClass($node->id?->name, $node->superClass, $body, $env);
        // Per spec, Function.prototype.toString on a class returns the full class source text.
        if ($node->sourceText !== null) {
            $cls->setSourceText($node->sourceText);
        }
        if ($node->id !== null) {
            // Class declarations are lexical bindings (like let), not var bindings.
            // They must NOT be visible as properties on the global object.
            $env->defineLet($node->id->name, $cls);
        }
        return Completion::normal(JsUndefined::instance());
    }

    /** @param list<ClassMethod> $methods */
    private function buildClass(
        ?string $name,
        ?Node $superClassNode,
        array $methods,
        Environment $env,
    ): JsFunction {
        $superClass = $superClassNode !== null
            ? $this->evaluate($superClassNode, $env)
            : null;

        // Per spec §15.7.14: if ClassHeritage is present and not null, it must be a constructor.
        if ($superClass !== null && !($superClass instanceof \PhpJs\Value\JsNull)) {
            if (!($superClass instanceof JsFunction) || !$superClass->isConstructable()) {
                throw new TypeError(
                    'Class extends value ' . TypeConversion::toString($superClass) . ' is not a constructor or null',
                );
            }
        }

        $constructor = null;
        $staticMethods = [];
        $instanceMethods = [];

        // Class bodies are always strict mode per spec.
        $previousStrictMode = $this->strictMode;
        $this->strictMode = true;

        foreach ($methods as $method) {
            $symbolKey = null;
            if ($method->computed) {
                $keyVal = $this->evaluate($method->key, $env);
                if ($keyVal instanceof \PhpJs\Value\JsSymbol) {
                    $symbolKey = $keyVal;
                    $key = '';
                } else {
                    $key = TypeConversion::toString($keyVal);
                }
            } else {
                $key = $method->key instanceof Identifier
                    ? $method->key->name
                    : TypeConversion::toString($this->evaluate($method->key, $env));
            }

            $fn = $this->evaluate($method->value, $env);

            // Per spec, class methods (non-constructor) are not constructable
            // and do not have a .prototype property.
            if ($method->kind !== 'constructor' && $fn instanceof JsFunction) {
                $fn->setNonConstructable();
                $fn->forceDelete('prototype');
            }

            if ($method->kind === 'constructor') {
                $constructor = $fn;
            } elseif ($method->static) {
                $staticMethods[] = [$key, $fn, $method->kind, $symbolKey];
            } else {
                $instanceMethods[] = [$key, $fn, $method->kind, $symbolKey];
            }
        }

        $isDerived = $superClass instanceof JsFunction;

        if ($constructor === null) {
            // Default constructor
            if ($isDerived) {
                $constructor = JsFunction::fromCallable(
                    $name ?? '(anonymous)',
                    function (JsValue $thisVal, array $args, Interpreter $interp) use ($superClass) {
                        return $interp->callFunction($superClass, $thisVal, $args);
                    },
                )->setConstructable();
            } else {
                $constructor = JsFunction::fromCallable(
                    $name ?? '(anonymous)',
                    fn() => JsUndefined::instance(),
                )->setConstructable();
            }
        }

        if (!$constructor instanceof JsFunction) {
            $constructor = JsFunction::fromCallable($name ?? '', fn() => JsUndefined::instance())->setConstructable();
        }

        // Mark as class constructor so calling without new throws TypeError.
        $constructor->setClassConstructor($isDerived);

        // Set up prototype chain.
        // `class C extends null` must produce C.prototype with null [[Prototype]].
        // `new JsObject(null)` would fall back to globalPrototype due to `??`, so we
        // use setPrototype() explicitly for the null-heritage case.
        if ($superClass instanceof JsFunction) {
            $superProto = $superClass->get('prototype');
            $proto = new JsObject($superProto instanceof JsObject ? $superProto : null);
        } elseif ($superClassNode !== null && $superClass instanceof \PhpJs\Value\JsNull) {
            // extends null: prototype has no [[Prototype]]
            $proto = new JsObject();
            $proto->setPrototype(null);
        } else {
            // No extends clause: prototype inherits from Object.prototype (global default)
            $proto = new JsObject();
        }

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        // Per spec, 'constructor' is the first property on the prototype object.
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));

        foreach ($instanceMethods as [$key, $fn, $kind, $symbolKey]) {
            // Set [[HomeObject]] so super references inside this method resolve correctly.
            if ($fn instanceof JsFunction) {
                $fn->setHomeObject($proto);
            }
            if ($symbolKey !== null) {
                // Symbol-keyed method (e.g. [Symbol.replace], [Symbol.iterator])
                $proto->definePropertyBySymbol($symbolKey, PropertyDescriptor::data(
                    $fn instanceof JsValue ? $fn : JsUndefined::instance(),
                    true,
                    false,
                    true,
                ));
            } elseif ($kind === 'get' || $kind === 'set') {
                // Class method accessors are non-enumerable per spec section 15.7.1.
                // Use defineProperty (direct set) to avoid hasGet/hasSet merge logic.
                $existing = $proto->getOwnPropertyDescriptor($key);
                if ($kind === 'get') {
                    $proto->defineProperty(
                        $key,
                        PropertyDescriptor::accessor(
                            $fn instanceof JsFunction ? $fn : null,
                            $existing?->set,
                            enumerable: false,
                            configurable: true,
                        ),
                    );
                } else {
                    $proto->defineProperty(
                        $key,
                        PropertyDescriptor::accessor(
                            $existing?->get,
                            $fn instanceof JsFunction ? $fn : null,
                            enumerable: false,
                            configurable: true,
                        ),
                    );
                }
            } else {
                $proto->defineOwnProperty($key, PropertyDescriptor::data(
                    $fn instanceof JsValue ? $fn : JsUndefined::instance(),
                    true,
                    false,
                    true,
                ));
            }
        }

        // Static methods (non-enumerable)
        foreach ($staticMethods as [$key, $fn, $kind, $symbolKey]) {
            // Static methods have [[HomeObject]] = the constructor itself.
            if ($fn instanceof JsFunction) {
                $fn->setHomeObject($constructor);
            }
            if ($symbolKey !== null) {
                $constructor->definePropertyBySymbol($symbolKey, PropertyDescriptor::data(
                    $fn instanceof JsValue ? $fn : JsUndefined::instance(),
                    true,
                    false,
                    true,
                ));
                continue;
            }
            // Per spec §15.7.1: it is a SyntaxError if a static method is named "prototype".
            if ($key === 'prototype') {
                throw new \PhpJs\Exceptions\TypeError(
                    "Classes may not have a static property named 'prototype'",
                );
            }
            // Static getters and setters are accessor properties, like non-static ones.
            // Use defineProperty (direct set) to avoid hasGet/hasSet merge logic.
            if ($kind === 'get' || $kind === 'set') {
                $existingSt = $constructor->getOwnPropertyDescriptor($key);
                if ($kind === 'get') {
                    $constructor->defineProperty($key, PropertyDescriptor::accessor(
                        $fn instanceof JsFunction ? $fn : null,
                        $existingSt?->set,
                        enumerable: false,
                        configurable: true,
                    ));
                } else {
                    $constructor->defineProperty($key, PropertyDescriptor::accessor(
                        $existingSt?->get,
                        $fn instanceof JsFunction ? $fn : null,
                        enumerable: false,
                        configurable: true,
                    ));
                }
            } else {
                $constructor->defineOwnProperty($key, PropertyDescriptor::data(
                    $fn instanceof JsValue ? $fn : JsUndefined::instance(),
                    true,
                    false,
                    true,
                ));
            }
        }

        // Inheritance: set [[Prototype]] of constructor to super class.
        // Per spec, DogCtor.__proto__ === AnimalCtor so that super() can resolve
        // the super constructor via [[GetPrototypeOf]](activeFunction).
        // Must use setCustomPrototype so JsFunction::getPrototype() returns this
        // value instead of Function.prototype.
        if ($superClass instanceof JsFunction) {
            $constructor->setCustomPrototype($superClass);
            // Also set [[HomeObject]] on the constructor itself so super() inside
            // it can find the super constructor via getPrototype().
            $constructor->setHomeObject($proto);
        }

        $this->strictMode = $previousStrictMode;

        return $constructor;
    }

    /**
     * Annex B.3.3 function declaration evaluation.
     *
     * In strict mode, FunctionDeclaration evaluation is NormalCompletion(empty)
     * because the function was already hoisted. In sloppy mode, block-scoped
     * function declarations propagate their value to the enclosing variable
     * environment (the function or global scope) per B.3.3.1 step 3.
     */
    private function execFunctionDeclaration(FunctionDeclaration $node, Environment $env): Completion
    {
        if (!$this->strictMode) {
            $name = $node->id->name;
            // Per B.3.3.1 step 3: instantiate the function in the current
            // lexical environment and propagate to the variable environment.
            $fobj = new JsFunction(
                $name,
                $node->params,
                $node->body,
                $env,
                isGenerator: $node->generator,
                isAsync: $node->async,
                strict: false,
            );
            if ($node->sourceText !== null) {
                $fobj->setSourceText($node->sourceText);
            }
            $this->installFunctionPrototype($fobj, $node->generator);
            // Propagate to the variable environment (enclosing function/global
            // scope) where the var binding was hoisted. Only propagate if the
            // binding was created by Annex B hoisting (not a parameter or
            // let/const that would suppress the extension per B.3.3.1 step ii).
            // Walk up the scope chain starting from env itself to find the
            // Annex B hoisted binding.
            $varScope = $env;
            while ($varScope !== null && !$varScope->isAnnexBHoisted($name)) {
                $varScope = $varScope->getParent();
            }
            if ($varScope !== null) {
                $varScope->set($name, $fobj, false);
            }
        }

        return new Completion(CompletionType::Normal, JsUndefined::instance(), empty: true);
    }

    private function execBlockStatement(BlockStatement $node, Environment $env): Completion
    {
        $blockEnv = $env->createChild();
        $this->hoistDeclarations($node->body, $blockEnv);
        $this->hoistEvalLexicalDeclarations($node->body, $blockEnv);
        return $this->executeBody($node->body, $blockEnv);
    }

    private function execIfStatement(IfStatement $node, Environment $env): Completion
    {
        $test = $this->evaluate($node->test, $env);
        if (TypeConversion::toBoolean($test)) {
            $stmtCompletion = $this->executeStatement($node->consequent, $env);
            // Per spec: Return Completion(UpdateEmpty(stmtCompletion, undefined)).
            if ($stmtCompletion->empty) {
                return new Completion(
                    $stmtCompletion->type,
                    JsUndefined::instance(),
                    $stmtCompletion->target,
                );
            }
            return $stmtCompletion;
        }
        if ($node->alternate !== null) {
            $stmtCompletion = $this->executeStatement($node->alternate, $env);
            if ($stmtCompletion->empty) {
                return new Completion(
                    $stmtCompletion->type,
                    JsUndefined::instance(),
                    $stmtCompletion->target,
                );
            }
            return $stmtCompletion;
        }
        return Completion::normal(JsUndefined::instance());
    }

    private function execForStatement(ForStatement $node, Environment $env, ?string $label = null): Completion
    {
        $loopEnv = $env->createChild();
        $isLetConst = $node->init instanceof VariableDeclaration
            && ($node->init->kind === 'let' || $node->init->kind === 'const');

        if ($node->init !== null) {
            if ($node->init instanceof VariableDeclaration) {
                $this->execVariableDeclaration($node->init, $loopEnv);
            } else {
                $this->evaluate($node->init, $loopEnv);
            }
        }

        // Collect the let/const binding names for per-iteration copying.
        $perIterationBindings = [];
        if ($isLetConst) {
            /** @var VariableDeclaration $varDecl */
            $varDecl = $node->init;
            foreach ($varDecl->declarations as $decl) {
                $this->collectBindingNames($decl->id, $perIterationBindings);
            }
        }

        // Per spec 13.7.4.8 ForBodyEvaluation: CreatePerIterationEnvironment
        // is called before the first test evaluation. When there are let/const
        // bindings, test/body/update all run in the per-iteration env.
        $iterEnv = $loopEnv;
        if ($perIterationBindings !== []) {
            $iterEnv = $env->createChild();
            foreach ($perIterationBindings as $name) {
                $iterEnv->defineVar($name, $loopEnv->get($name));
            }
        }

        $v = JsUndefined::instance();
        $iterations = 0;
        while (true) {
            if (++$iterations > $this->maxLoopIterations) {
                throw new InternalError('Maximum loop iterations exceeded');
            }

            if ($node->test !== null) {
                $test = $this->evaluate($node->test, $iterEnv);
                if (!TypeConversion::toBoolean($test)) {
                    break;
                }
            }

            // For non-let/const loops, create a child for the body so block
            // scoped variables inside the body do not leak.
            $bodyEnv = $perIterationBindings !== [] ? $iterEnv : $iterEnv->createChild();
            $completion = $this->executeStatement($node->body, $bodyEnv);

            if (!$completion->value instanceof JsUndefined || ($completion->isAbrupt() && !$completion->empty)) {
                $v = $completion->value;
            }

            if (
                $completion->type === CompletionType::Break
                && ($completion->target === null || ($label !== null && $completion->target === $label))
            ) {
                // Per spec: return Completion(UpdateEmpty(result, V)).
                // Then BreakableStatement converts break to normal.
                $breakVal = $completion->empty ? $v : $completion->value;
                return Completion::normal($breakVal);
            }
            if (
                $completion->type === CompletionType::Continue
                && ($completion->target === null || ($label !== null && $completion->target === $label))
            ) {
                // fall through to update
            } elseif ($completion->isAbrupt()) {
                return $completion;
            }

            // Per spec 13.7.4.8 step e: CreatePerIterationEnvironment runs
            // BEFORE the increment (step f). This ensures the increment
            // modifies the next iteration's bindings, not the current one.
            if ($perIterationBindings !== []) {
                $nextIterEnv = $env->createChild();
                foreach ($perIterationBindings as $name) {
                    $nextIterEnv->defineVar($name, $iterEnv->get($name));
                }
                $iterEnv = $nextIterEnv;
            }

            if ($node->update !== null) {
                $this->evaluate($node->update, $iterEnv);
            }
        }

        return Completion::normal($v);
    }

    private function execForInStatement(ForInStatement $node, Environment $env, ?string $label = null): Completion
    {
        // Per spec ForIn/OfHeadEvaluation: if lhs is a lexical binding (let/const),
        // evaluate the expression in a TDZ environment with bound names.
        // e.g. `for (const x in { x })` sees x as uninitialized in the RHS.
        $exprEnv = $env;
        if ($node->left instanceof VariableDeclaration && $node->left->kind !== 'var') {
            $tdzEnv = $env->createChild();
            foreach ($node->left->declarations as $decl) {
                foreach ($this->patternBoundNames($decl->id) as $name) {
                    $tdzEnv->declareLet($name);
                }
            }
            $exprEnv = $tdzEnv;
        }

        $obj = $this->evaluate($node->right, $exprEnv);
        if ($obj instanceof JsNull || $obj instanceof JsUndefined) {
            return Completion::normal(JsUndefined::instance());
        }
        if (!$obj instanceof JsObject) {
            $obj = TypeConversion::toObject($obj);
        }

        $keys = $obj->getEnumerableKeys();
        $v = JsUndefined::instance();
        $iterations = 0;

        foreach ($keys as $key) {
            if (++$iterations > $this->maxLoopIterations) {
                throw new InternalError('Maximum loop iterations exceeded');
            }

            // Per spec EnumerateObjectProperties: skip keys deleted during enumeration.
            if (!$obj->has((string) $key)) {
                continue;
            }

            $iterEnv = $env->createChild();
            $this->assignForBinding($node->left, new JsString((string) $key), $iterEnv);
            $completion = $this->executeStatement($node->body, $iterEnv);

            if (!$completion->value instanceof JsUndefined || ($completion->isAbrupt() && !$completion->empty)) {
                $v = $completion->value;
            }

            if (
                $completion->type === CompletionType::Break
                && ($completion->target === null || ($label !== null && $completion->target === $label))
            ) {
                $breakVal = $completion->empty ? $v : $completion->value;
                return Completion::normal($breakVal);
            }
            if (
                $completion->type === CompletionType::Continue
                && ($completion->target === null || ($label !== null && $completion->target === $label))
            ) {
                continue;
            }
            if ($completion->isAbrupt()) {
                return $completion;
            }
        }

        return Completion::normal($v);
    }

    private function execForOfStatement(ForOfStatement $node, Environment $env, ?string $label = null): Completion
    {
        // Per spec ForIn/OfHeadEvaluation: if lhs is a lexical binding (let/const),
        // evaluate the iterable expression in a TDZ environment with bound names.
        // e.g. `for (let x of [x])` → [x] sees x as uninitialized → ReferenceError.
        $exprEnv = $env;
        if ($node->left instanceof VariableDeclaration && $node->left->kind !== 'var') {
            $tdzEnv = $env->createChild();
            foreach ($node->left->declarations as $decl) {
                foreach ($this->patternBoundNames($decl->id) as $name) {
                    $tdzEnv->declareLet($name);
                }
            }
            $exprEnv = $tdzEnv;
        }

        $iterable = $this->evaluate($node->right, $exprEnv);
        $iterations = 0;
        $v = JsUndefined::instance();

        // Try the iterator protocol first.
        $iterator = $this->getIterator($iterable);

        if ($iterator !== null) {
            $nextMethod = $iterator->get('next');
            if (!$nextMethod instanceof JsFunction) {
                throw new TypeError('Iterator result next is not a function');
            }

            // Helper: call iterator.return() if it exists (iterator close protocol).
            // Per spec IteratorClose (7.4.7): if original completion is throw, it takes precedence.
            $closeIterator = function (?Completion $abruptCompletion) use ($iterator): ?Completion {
                $isOriginalThrow = $abruptCompletion !== null && $abruptCompletion->type === CompletionType::Throw;

                // Step 3: innerResult = GetMethod(iterator, "return").
                // If the getter itself throws, that's an abrupt innerResult.
                try {
                    $returnMethod = $iterator->get('return');
                } catch (\PhpJs\Exceptions\JsThrowable $e) {
                    // GetMethod threw. Per step 5: if original was throw, suppress; else propagate.
                    if ($isOriginalThrow) {
                        return null;
                    }
                    return Completion::throw($e->jsValue);
                } catch (\PhpJs\Exceptions\RuntimeError $e) {
                    if ($isOriginalThrow) {
                        return null;
                    }
                    return Completion::throw($this->phpExceptionToJsValue($e));
                }

                // Per spec GetMethod: undefined/null → no return method (step 3b).
                if ($returnMethod instanceof JsUndefined || $returnMethod instanceof JsNull) {
                    return null;
                }

                // Per spec GetMethod: non-callable → TypeError.
                if (!$returnMethod instanceof JsFunction) {
                    if ($isOriginalThrow) {
                        return null;
                    }
                    return Completion::throw($this->phpExceptionToJsValue(
                        new TypeError('Iterator return is not callable')
                    ));
                }

                // Step 3c: innerResult = Call(return, iterator).
                try {
                    $innerResult = $this->callFunction($returnMethod, $iterator, []);
                    // Step 5: if original was throw, return it (ignore return() result).
                    if ($isOriginalThrow) {
                        return null;
                    }
                    // Step 6-7: if return() returned non-object, throw TypeError.
                    if (!$innerResult instanceof JsObject) {
                        return Completion::throw($this->phpExceptionToJsValue(
                            new TypeError('Iterator return result is not an object')
                        ));
                    }
                } catch (\PhpJs\Exceptions\JsThrowable $e) {
                    // Step 6: return() threw. If original was throw, suppress; else propagate.
                    if ($isOriginalThrow) {
                        return null;
                    }
                    return Completion::throw($e->jsValue);
                } catch (\PhpJs\Exceptions\RuntimeError $e) {
                    if ($isOriginalThrow) {
                        return null;
                    }
                    return Completion::throw($this->phpExceptionToJsValue($e));
                }
                return null;
            };

            while (true) {
                if (++$iterations > $this->maxLoopIterations) {
                    throw new InternalError('Maximum loop iterations exceeded');
                }

                $result = $this->callFunction($nextMethod, $iterator, []);
                if (!$result instanceof JsObject) {
                    throw new TypeError('Iterator result is not an object');
                }

                $done = $result->get('done');
                if (TypeConversion::toBoolean($done)) {
                    break;
                }

                $value = $result->get('value');
                $iterEnv = $env->createChild();
                // Per spec ForIn/OfBodyEvaluation: if LHS assignment/destructuring is abrupt,
                // close the iterator before propagating the error.
                try {
                    $this->assignForBinding($node->left, $value, $iterEnv);
                } catch (\PhpJs\Exceptions\JsThrowable $assignErr) {
                    $closeIterator(Completion::throw($assignErr->jsValue));
                    throw $assignErr;
                } catch (\PhpJs\Exceptions\RuntimeError $assignErr) {
                    $closeIterator(Completion::throw($this->phpExceptionToJsValue($assignErr)));
                    throw $assignErr;
                }
                $completion = $this->executeStatement($node->body, $iterEnv);

                if (!$completion->value instanceof JsUndefined || ($completion->isAbrupt() && !$completion->empty)) {
                    $v = $completion->value;
                }

                $isTargetedBreak = $completion->target === null
                    || ($label !== null && $completion->target === $label);
                if ($completion->type === CompletionType::Break && $isTargetedBreak) {
                    $closeCompletion = $closeIterator(null);
                    if ($closeCompletion !== null && $closeCompletion->isAbrupt()) {
                        return $closeCompletion;
                    }
                    $breakVal = $completion->empty ? $v : $completion->value;
                    return Completion::normal($breakVal);
                }
                if (
                    $completion->type === CompletionType::Continue
                    && ($completion->target === null || ($label !== null && $completion->target === $label))
                ) {
                    continue;
                }
                if ($completion->isAbrupt()) {
                    $closeCompletion = $closeIterator($completion);
                    return $closeCompletion ?? $completion;
                }
            }

            return Completion::normal($v);
        }

        throw new TypeError(TypeConversion::toString($iterable) . ' is not iterable');
    }

    /**
     * Get an iterator from a value using the Symbol.iterator protocol.
     *
     * Returns the iterator object, or null if the value does not implement
     * the iterator protocol.
     */
    private function getIterator(JsValue $iterable): ?JsObject
    {
        // String iteration: produce a character iterator.
        if ($iterable instanceof JsString) {
            $chars = [];
            $len = mb_strlen($iterable->value, 'UTF-8');
            for ($i = 0; $i < $len; $i++) {
                $chars[] = mb_substr($iterable->value, $i, 1, 'UTF-8');
            }
            $index = 0;
            $total = count($chars);

            $iterator = new JsObject();
            $nextFn = function () use (&$index, $total, &$chars): JsValue {
                $result = new JsObject();
                if ($index < $total) {
                    $result->set('value', new JsString($chars[$index]));
                    $result->set('done', new JsBoolean(false));
                    $index++;
                } else {
                    $result->set('value', JsUndefined::instance());
                    $result->set('done', new JsBoolean(true));
                }
                return $result;
            };
            $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
            return $iterator;
        }

        if (!$iterable instanceof JsObject) {
            return null;
        }

        // Check for Symbol.iterator method.
        $iterSym = \PhpJs\BuiltIn\SymbolConstructor::iterator();
        $iteratorMethod = $iterable->getBySymbol($iterSym);

        if (!$iteratorMethod instanceof JsFunction) {
            return null;
        }

        $iterator = $this->callFunction($iteratorMethod, $iterable, []);
        if (!$iterator instanceof JsObject) {
            throw new TypeError('Result of the Symbol.iterator method is not an object');
        }

        return $iterator;
    }

    private function assignForBinding(Node $left, JsValue $value, Environment $env): void
    {
        if ($left instanceof VariableDeclaration) {
            // let/const: declare in the iteration block scope.
            // var: already hoisted to function scope; use set() to update it there.
            if ($left->kind === 'var') {
                $id = $left->declarations[0]->id;
                if ($id instanceof Identifier) {
                    // Walk the scope chain to find and update the hoisted var binding.
                    $env->set($id->name, $value, false);
                } else {
                    // Destructuring var: bind in the hoisted scope using set semantics.
                    $this->assignPatternToEnv($id, $value, $env);
                }
            } else {
                $this->declareBinding($left->kind, $left->declarations[0]->id, $value, $env);
            }
        } elseif ($left instanceof Identifier) {
            // Plain assignment (no declaration keyword): update existing binding or create global.
            $env->set($left->name, $value, false);
        } elseif ($this->isDestructuringTarget($left)) {
            // Plain destructuring assignment (no declaration keyword): update existing bindings.
            $this->destructureAssign($left, $value, $env);
        } else {
            // Member expression or other reference LHS: e.g. for (x.attr of iterable).
            $ref = $this->resolveReference($left, $env);
            $ref->setValue($value);
        }
    }

    /**
     * Assign a destructured value to existing bindings by walking the scope chain.
     * Used for for-of/for-in without a declaration keyword.
     */
    private function assignPatternToEnv(Node $pattern, JsValue $value, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            $env->set($pattern->name, $value, false);
            return;
        }

        if ($pattern instanceof ArrayPattern) {
            [$iterator, $nextMethod] = $this->getIteratorOrThrow($value);
            $done = false;
            foreach ($pattern->elements as $element) {
                if ($element instanceof RestElement) {
                    $rest = $this->iteratorRest($iterator, $nextMethod, $done);
                    $this->assignPatternToEnv($element->argument, $rest, $env);
                    break;
                }
                $elemValue = $this->iteratorNext($iterator, $nextMethod, $done);
                if ($element === null) {
                    continue;
                }
                $this->assignPatternToEnv($element, $elemValue, $env);
            }
            return;
        }

        if ($pattern instanceof ObjectPattern) {
            if ($value instanceof JsNull || $value instanceof JsUndefined) {
                throw new \PhpJs\Exceptions\TypeError(
                    "Cannot destructure property of " . TypeConversion::toString($value),
                );
            }
            $usedKeysApe = [];
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof RestElement) {
                    $restObjApe = new JsObject();
                    if ($value instanceof JsObject) {
                        // Per spec: object rest only includes own enumerable properties.
                        foreach ($value->getOwnEnumerableKeys() as $rk) {
                            if (!in_array($rk, $usedKeysApe, true)) {
                                $restObjApe->set($rk, $value->get($rk));
                            }
                        }
                    }
                    $restArgApe = $prop->argument;
                    if ($this->isDestructuringTarget($restArgApe)) {
                        $this->destructureAssign($restArgApe, $restObjApe, $env);
                    } else {
                        $ref = $this->resolveReference($restArgApe, $env);
                        $ref->setValue($restObjApe);
                    }
                    break;
                }
                if ($prop instanceof AssignmentProperty) {
                    $key = $prop->computed
                        ? TypeConversion::toString($this->evaluate($prop->key, $env))
                        : ($prop->key instanceof Identifier
                            ? $prop->key->name
                            : TypeConversion::toString($this->evaluate($prop->key, $env)));
                    $usedKeysApe[] = $key;
                    $propValue = ($value instanceof JsObject) ? $value->get($key) : JsUndefined::instance();
                    $this->assignPatternToEnv($prop->value, $propValue, $env);
                }
            }
            return;
        }

        if ($pattern instanceof AssignmentPattern) {
            if ($value instanceof JsUndefined) {
                $value = $this->evaluate($pattern->right, $env);
                // Function name inference: check AST node type, not just runtime value.
                if (
                    $value instanceof JsFunction
                    && $pattern->left instanceof Identifier
                    && $this->isAnonymousFunctionDefinitionNode($pattern->right)
                ) {
                    $value->setName($pattern->left->name);
                }
            }
            $this->assignPatternToEnv($pattern->left, $value, $env);
            return;
        }
    }

    private function execWhileStatement(WhileStatement $node, Environment $env, ?string $label = null): Completion
    {
        $v = JsUndefined::instance();
        $iterations = 0;
        while (true) {
            if (++$iterations > $this->maxLoopIterations) {
                throw new InternalError('Maximum loop iterations exceeded');
            }

            $test = $this->evaluate($node->test, $env);
            if (!TypeConversion::toBoolean($test)) {
                break;
            }

            $completion = $this->executeStatement($node->body, $env);

            if (!$completion->value instanceof JsUndefined || ($completion->isAbrupt() && !$completion->empty)) {
                $v = $completion->value;
            }

            if (
                $completion->type === CompletionType::Break
                && ($completion->target === null || ($label !== null && $completion->target === $label))
            ) {
                $breakVal = $completion->empty ? $v : $completion->value;
                return Completion::normal($breakVal);
            }
            if (
                $completion->type === CompletionType::Continue
                && ($completion->target === null || ($label !== null && $completion->target === $label))
            ) {
                continue;
            }
            if ($completion->isAbrupt()) {
                return $completion;
            }
        }

        return Completion::normal($v);
    }

    private function execDoWhileStatement(DoWhileStatement $node, Environment $env, ?string $label = null): Completion
    {
        $v = JsUndefined::instance();
        $iterations = 0;
        do {
            if (++$iterations > $this->maxLoopIterations) {
                throw new InternalError('Maximum loop iterations exceeded');
            }

            $completion = $this->executeStatement($node->body, $env);

            if (!$completion->value instanceof JsUndefined || ($completion->isAbrupt() && !$completion->empty)) {
                $v = $completion->value;
            }

            if (
                $completion->type === CompletionType::Break
                && ($completion->target === null || ($label !== null && $completion->target === $label))
            ) {
                $breakVal = $completion->empty ? $v : $completion->value;
                return Completion::normal($breakVal);
            }
            if (
                $completion->type === CompletionType::Continue
                && ($completion->target === null || ($label !== null && $completion->target === $label))
            ) {
                // fall through to test
            } elseif ($completion->isAbrupt()) {
                return $completion;
            }

            $test = $this->evaluate($node->test, $env);
        } while (TypeConversion::toBoolean($test));

        return Completion::normal($v);
    }

    private function execSwitchStatement(SwitchStatement $node, Environment $env): Completion
    {
        $discriminant = $this->evaluate($node->discriminant, $env);
        $switchEnv = $env->createChild();

        // Hoist let/const TDZ for all case bodies (shared switch scope).
        $allCaseStmts = [];
        foreach ($node->cases as $case) {
            foreach ($case->consequent as $stmt) {
                $allCaseStmts[] = $stmt;
            }
        }
        $this->hoistDeclarations($allCaseStmts, $switchEnv);
        $this->hoistEvalLexicalDeclarations($allCaseStmts, $switchEnv);

        $matched = false;
        $defaultCase = null;
        $v = JsUndefined::instance();

        foreach ($node->cases as $case) {
            if ($case->test === null) {
                $defaultCase = $case;
                if (!$matched) {
                    continue;
                }
            }

            if (!$matched && $case->test !== null) {
                $test = $this->evaluate($case->test, $switchEnv);
                $matched = AbstractOperations::strictEquals($discriminant, $test);
            }

            if ($matched) {
                $result = $this->executeCaseBody($case, $switchEnv, $v);
                $v = $result->value;
                if ($result->isAbrupt()) {
                    if ($result->type === CompletionType::Break && $result->target === null) {
                        return Completion::normal($v);
                    }
                    return $result;
                }
            }
        }

        // If no case matched, try default
        if (!$matched && $defaultCase !== null) {
            $matched = true;
            // Execute from default through remaining cases
            $foundDefault = false;
            foreach ($node->cases as $case) {
                if ($case === $defaultCase) {
                    $foundDefault = true;
                }
                if ($foundDefault) {
                    $result = $this->executeCaseBody($case, $switchEnv, $v);
                    $v = $result->value;
                    if ($result->isAbrupt()) {
                        if ($result->type === CompletionType::Break && $result->target === null) {
                            return Completion::normal($v);
                        }
                        return $result;
                    }
                }
            }
        }

        return Completion::normal($v);
    }

    /**
     * Execute case body statements, tracking completion value V per spec.
     *
     * Returns a Completion whose value is the updated V (last non-empty
     * statement value), regardless of whether the completion is normal or
     * abrupt. This implements the UpdateEmpty semantics from 13.12.9.
     */
    private function executeCaseBody(SwitchCase $case, Environment $env, JsValue $v): Completion
    {
        foreach ($case->consequent as $stmt) {
            $completion = $this->executeStatement($stmt, $env);
            // Per spec: if R.[[value]] is not empty, let V = R.[[value]].
            if (!$completion->value instanceof JsUndefined || ($completion->isAbrupt() && !$completion->empty)) {
                $v = $completion->value;
            }
            if ($completion->isAbrupt()) {
                // UpdateEmpty: if the abrupt completion's value is empty,
                // fill it with the accumulated V.
                if ($completion->empty) {
                    return new Completion($completion->type, $v, $completion->target);
                }
                return $completion;
            }
        }
        return Completion::normal($v);
    }

    private function execReturnStatement(ReturnStatement $node, Environment $env): Completion
    {
        $value = $node->argument !== null
            ? $this->evaluate($node->argument, $env)
            : JsUndefined::instance();
        return Completion::return($value);
    }

    private function execThrowStatement(ThrowStatement $node, Environment $env): Completion
    {
        $value = $this->evaluate($node->argument, $env);
        return Completion::throw($value);
    }

    private function execTryStatement(TryStatement $node, Environment $env): Completion
    {
        $generatorReturnSignal = null;
        try {
            $completion = $this->execBlockStatement($node->block, $env);
        } catch (GeneratorReturnSignal $returnSignal) {
            // generator.return() signal must propagate through finally blocks.
            // Stash it and let the finally block below run (if any), then re-throw.
            $generatorReturnSignal = $returnSignal;
            // Treat as a Return completion so the finally executes; the catch
            // handler must NOT run for a return signal.
            $completion = Completion::return($returnSignal->value);
        } catch (GeneratorThrowSignal $e) {
            // A generator.throw() signal propagated into a try block.
            // Convert it to a Throw completion so the catch handler can run.
            $completion = Completion::throw($e->jsValue);
        } catch (\PhpJs\Exceptions\JsThrowable $e) {
            // A PHP exception carrying a JS value (e.g., from generator.throw()).
            // Extract the original JS value for the catch handler.
            $completion = Completion::throw($e->jsValue);
        } catch (\PhpJs\Exceptions\SyntaxError $e) {
            // A PHP SyntaxError (e.g. from eval parsing). Convert to a JS
            // SyntaxError so the catch handler can process it.
            $completion = Completion::throw($this->phpExceptionToJsValue($e));
        } catch (\PhpJs\Exceptions\RuntimeError $e) {
            // A PHP exception representing a JS runtime error. Convert to
            // a Throw completion so the JS catch handler can process it.
            $completion = Completion::throw($this->phpExceptionToJsValue($e));
        }

        if ($completion->type === CompletionType::Throw && $node->handler !== null) {
            // Per spec 14.15.2 (CatchClauseEvaluation):
            // 1. Create catchEnv for the catch parameter binding.
            $catchEnv = $env->createChild();
            if ($node->handler->param !== null) {
                $this->bindPattern($node->handler->param, $completion->value, $catchEnv);
            }
            // 2. Create a child block environment for the catch body.
            //    let/const declarations in the body live here, separate from
            //    the parameter binding environment. This matters when closures
            //    in default values of destructuring patterns must not see
            //    body-scoped lexical declarations.
            $bodyEnv = $catchEnv->createChild();
            $this->hoistDeclarations($node->handler->body->body, $bodyEnv);
            $completion = $this->executeBody($node->handler->body->body, $bodyEnv);
        }

        if ($node->finalizer !== null) {
            $finallyCompletion = $this->execBlockStatement($node->finalizer, $env);
            if ($finallyCompletion->isAbrupt()) {
                // Per spec: Return Completion(UpdateEmpty(F, undefined)).
                // If the finally's abrupt completion has an empty value slot,
                // fill it with undefined and mark non-empty so outer blocks
                // do not replace it with their own accumulated value.
                if ($finallyCompletion->empty) {
                    return new Completion(
                        $finallyCompletion->type,
                        JsUndefined::instance(),
                        $finallyCompletion->target,
                    );
                }
                // Finally's abrupt completion takes precedence over GeneratorReturnSignal.
                $generatorReturnSignal = null;
                return $finallyCompletion;
            }
            // F.type is normal: set F to C (use the try/catch completion).
        }

        // If a generator.return() signal was stashed and the finally completed
        // normally, re-throw the signal to continue propagating through the
        // generator's call stack.
        if ($generatorReturnSignal !== null) {
            throw $generatorReturnSignal;
        }

        return $completion;
    }

    private function execLabeledStatement(LabeledStatement $node, Environment $env): Completion
    {
        $body = $node->body;
        $label = $node->label;

        // Per spec, iteration statements receive the label set so they can
        // handle labeled break/continue targeting their own label.
        $completion = match (true) {
            $body instanceof ForStatement => $this->execForStatement($body, $env, $label),
            $body instanceof ForInStatement => $this->execForInStatement($body, $env, $label),
            $body instanceof ForOfStatement => $this->execForOfStatement($body, $env, $label),
            $body instanceof WhileStatement => $this->execWhileStatement($body, $env, $label),
            $body instanceof DoWhileStatement => $this->execDoWhileStatement($body, $env, $label),
            default => $this->executeStatement($body, $env),
        };

        // Labeled break targeting this label consumes the break.
        if ($completion->type === CompletionType::Break && $completion->target === $label) {
            return Completion::normal($completion->value);
        }

        // Labeled continue targeting this label should have been consumed by
        // the iteration statement above. If it reaches here (non-iteration
        // body), just consume it.
        if ($completion->type === CompletionType::Continue && $completion->target === $label) {
            return Completion::normal(JsUndefined::instance());
        }

        return $completion;
    }

    private function execWithStatement(WithStatement $node, Environment $env): Completion
    {
        $obj = $this->evaluate($node->object, $env);
        if (!$obj instanceof JsObject) {
            $obj = TypeConversion::toObject($obj);
        }
        $withEnv = $env->createWithEnvironment($obj);
        $this->withEnvObjects[spl_object_id($withEnv)] = $obj;
        try {
            $completion = $this->executeStatement($node->body, $withEnv);
        } finally {
            unset($this->withEnvObjects[spl_object_id($withEnv)]);
        }
        // Per spec 14.11.2 step 9: Return Completion(UpdateEmpty(C, undefined)).
        if ($completion->empty) {
            return new Completion(
                $completion->type,
                JsUndefined::instance(),
                $completion->target,
            );
        }
        return $completion;
    }

    // -------------------------------------------------------------------------
    // Hoisting
    // -------------------------------------------------------------------------

    /** @param Node[] $statements */
    private function hoistDeclarations(array $statements, Environment $env): void
    {
        // Collect top-level lexical names (let/const) so hoistBlockFunctionDeclarations
        // can skip names that would conflict per B.3.3.1 step ii.
        $lexicalNames = [];
        if (!$this->strictMode) {
            foreach ($statements as $s) {
                if ($s instanceof VariableDeclaration && ($s->kind === 'let' || $s->kind === 'const')) {
                    foreach ($s->declarations as $d) {
                        foreach ($this->patternBoundNames($d->id) as $n) {
                            $lexicalNames[$n] = true;
                        }
                    }
                }
            }
        }

        foreach ($statements as $stmt) {
            if ($stmt instanceof FunctionDeclaration) {
                $fn = new JsFunction(
                    $stmt->id->name,
                    $stmt->params,
                    $stmt->body,
                    $env,
                    isGenerator: $stmt->generator,
                    isAsync: $stmt->async,
                    strict: $this->strictMode,
                );
                if ($stmt->sourceText !== null) {
                    $fn->setSourceText($stmt->sourceText);
                }
                $this->installFunctionPrototype($fn, $stmt->generator);
                // At global scope, function declarations are enumerable, non-configurable properties.
                // In nested scopes (env has no linked object), use defineVar as usual.
                if ($env->getLinkedObject() !== null) {
                    $env->defineGlobalVar($stmt->id->name, $fn);
                } else {
                    $env->defineVar($stmt->id->name, $fn);
                }
            } elseif ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    $this->hoistVarNames($decl->id, $env);
                }
            } elseif ($stmt instanceof ForOfStatement || $stmt instanceof ForInStatement) {
                // Hoist var declarations from for-of/for-in headers.
                if ($stmt->left instanceof VariableDeclaration && $stmt->left->kind === 'var') {
                    foreach ($stmt->left->declarations as $decl) {
                        $this->hoistVarNames($decl->id, $env);
                    }
                }
                // Recurse into for-of/for-in body for nested var hoisting only.
                if ($stmt->body instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                }
            } elseif ($stmt instanceof ForStatement) {
                // Hoist var declarations from for-statement init.
                if ($stmt->init instanceof VariableDeclaration && $stmt->init->kind === 'var') {
                    foreach ($stmt->init->declarations as $decl) {
                        $this->hoistVarNames($decl->id, $env);
                    }
                }
                if ($stmt->body instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                }
            } elseif (
                $stmt instanceof \PhpJs\Ast\Statement\WhileStatement
                || $stmt instanceof \PhpJs\Ast\Statement\DoWhileStatement
            ) {
                if ($stmt->body instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                }
            } elseif ($stmt instanceof \PhpJs\Ast\Statement\IfStatement) {
                // Only hoist var declarations from if/else block bodies.
                // Function declarations in if/else are block-scoped per ES2015+.
                if ($stmt->consequent instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->consequent->body, $env);
                }
                if ($stmt->alternate instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->alternate->body, $env);
                } elseif ($stmt->alternate instanceof \PhpJs\Ast\Statement\IfStatement) {
                    $this->hoistDeclarations([$stmt->alternate], $env);
                }
            } elseif ($stmt instanceof \PhpJs\Ast\Statement\BlockStatement) {
                $this->hoistVarDeclarationsOnly($stmt->body, $env);
            } elseif ($stmt instanceof TryStatement) {
                // Hoist var declarations from try, catch, and finally blocks.
                $this->hoistVarDeclarationsOnly($stmt->block->body, $env);
                if ($stmt->handler !== null) {
                    $this->hoistVarDeclarationsOnly($stmt->handler->body->body, $env);
                }
                if ($stmt->finalizer !== null) {
                    $this->hoistVarDeclarationsOnly($stmt->finalizer->body, $env);
                }
            } elseif ($stmt instanceof SwitchStatement) {
                // Hoist var declarations from switch case bodies. Function
                // declarations in switch are block-scoped; only the var binding
                // name is hoisted via hoistBlockFunctionDeclarations.
                foreach ($stmt->cases as $case) {
                    foreach ($case->consequent as $inner) {
                        if ($inner instanceof VariableDeclaration && $inner->kind === 'var') {
                            $this->hoistDeclarations([$inner], $env);
                        } elseif (!($inner instanceof FunctionDeclaration)) {
                            $this->hoistVarDeclarationsOnly(
                                $inner instanceof \PhpJs\Ast\Statement\BlockStatement ? $inner->body : [$inner],
                                $env,
                            );
                        }
                    }
                }
            } elseif ($stmt instanceof LabeledStatement) {
                // Recurse into labeled statement body for var hoisting.
                $this->hoistDeclarations([$stmt->body], $env);
            } elseif ($stmt instanceof WithStatement) {
                // Var declarations inside with statements hoist to the enclosing scope.
                if ($stmt->body instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                } else {
                    $this->hoistDeclarations([$stmt->body], $env);
                }
            }

            // Annex B: in sloppy mode, hoist function declaration names from
            // nested blocks (if, for, while, etc.) to the enclosing scope.
            if (!$this->strictMode) {
                $this->hoistBlockFunctionDeclarations($stmt, $env, $lexicalNames);
            }
        }
    }

    /**
     * Hoist declarations in eval code at global scope.
     * Per EvalDeclarationInstantiation, function and var bindings created by eval
     * at the global level use configurable: true (unlike script-level which uses
     * configurable: false). This mirrors CreateGlobalFunctionBinding(fn, fo, true)
     * and CreateGlobalVarBinding(vn, true) from the spec.
     *
     * @param Node[] $statements
     */
    private function hoistEvalGlobalDeclarations(array $statements, Environment $env): void
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof FunctionDeclaration) {
                $fn = new JsFunction(
                    $stmt->id->name,
                    $stmt->params,
                    $stmt->body,
                    $env,
                    isGenerator: $stmt->generator,
                    isAsync: $stmt->async,
                    strict: $this->strictMode,
                );
                if ($stmt->sourceText !== null) {
                    $fn->setSourceText($stmt->sourceText);
                }
                $this->installFunctionPrototype($fn, $stmt->generator);
                // Eval-created global function bindings are configurable.
                $env->defineGlobalVar($stmt->id->name, $fn, true);
            } elseif ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    $this->hoistEvalGlobalVarNames($decl->id, $env);
                }
            } else {
                // Recurse into compound statements for nested var declarations.
                $this->hoistEvalGlobalVarCompound($stmt, $env);
            }
        }
    }

    /**
     * Hoist a var name into global scope from eval, using configurable: true.
     */
    private function hoistEvalGlobalVarNames(Node $pattern, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            if (!$env->has($pattern->name)) {
                $env->defineGlobalVar($pattern->name, JsUndefined::instance(), true);
            }
        } elseif ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $this->hoistEvalGlobalVarNames($elem, $env);
                }
            }
        } elseif ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->hoistEvalGlobalVarNames($prop->value, $env);
                } elseif ($prop instanceof RestElement) {
                    $this->hoistEvalGlobalVarNames($prop->argument, $env);
                }
            }
        } elseif ($pattern instanceof AssignmentPattern) {
            $this->hoistEvalGlobalVarNames($pattern->left, $env);
        } elseif ($pattern instanceof RestElement) {
            $this->hoistEvalGlobalVarNames($pattern->argument, $env);
        }
    }

    /**
     * Recurse into compound statements for eval global var hoisting.
     */
    private function hoistEvalGlobalVarCompound(Node $stmt, Environment $env): void
    {
        if ($stmt instanceof ForOfStatement || $stmt instanceof ForInStatement) {
            if ($stmt->left instanceof VariableDeclaration && $stmt->left->kind === 'var') {
                foreach ($stmt->left->declarations as $decl) {
                    $this->hoistEvalGlobalVarNames($decl->id, $env);
                }
            }
            if ($stmt->body instanceof BlockStatement) {
                foreach ($stmt->body->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            }
        } elseif ($stmt instanceof ForStatement) {
            if ($stmt->init instanceof VariableDeclaration && $stmt->init->kind === 'var') {
                foreach ($stmt->init->declarations as $decl) {
                    $this->hoistEvalGlobalVarNames($decl->id, $env);
                }
            }
            if ($stmt->body instanceof BlockStatement) {
                foreach ($stmt->body->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            }
        } elseif ($stmt instanceof WhileStatement || $stmt instanceof DoWhileStatement) {
            if ($stmt->body instanceof BlockStatement) {
                foreach ($stmt->body->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            }
        } elseif ($stmt instanceof IfStatement) {
            if ($stmt->consequent instanceof BlockStatement) {
                foreach ($stmt->consequent->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            }
            if ($stmt->alternate instanceof BlockStatement) {
                foreach ($stmt->alternate->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            } elseif ($stmt->alternate instanceof IfStatement) {
                $this->hoistEvalGlobalVarCompound($stmt->alternate, $env);
            }
        } elseif ($stmt instanceof BlockStatement) {
            foreach ($stmt->body as $s) {
                $this->hoistEvalGlobalVarCompound($s, $env);
            }
        } elseif ($stmt instanceof TryStatement) {
            foreach ($stmt->block->body as $s) {
                $this->hoistEvalGlobalVarCompound($s, $env);
            }
            if ($stmt->handler !== null) {
                foreach ($stmt->handler->body->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            }
            if ($stmt->finalizer !== null) {
                foreach ($stmt->finalizer->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            }
        } elseif ($stmt instanceof SwitchStatement) {
            foreach ($stmt->cases as $case) {
                foreach ($case->consequent as $inner) {
                    if ($inner instanceof VariableDeclaration && $inner->kind === 'var') {
                        foreach ($inner->declarations as $decl) {
                            $this->hoistEvalGlobalVarNames($decl->id, $env);
                        }
                    } else {
                        $this->hoistEvalGlobalVarCompound($inner, $env);
                    }
                }
            }
        } elseif ($stmt instanceof LabeledStatement) {
            $this->hoistEvalGlobalVarCompound($stmt->body, $env);
        } elseif ($stmt instanceof WithStatement) {
            if ($stmt->body instanceof BlockStatement) {
                foreach ($stmt->body->body as $s) {
                    $this->hoistEvalGlobalVarCompound($s, $env);
                }
            } else {
                $this->hoistEvalGlobalVarCompound($stmt->body, $env);
            }
        } elseif ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
            foreach ($stmt->declarations as $decl) {
                $this->hoistEvalGlobalVarNames($decl->id, $env);
            }
        }
    }

    /**
     * Hoist only var declarations (not function declarations) from nested blocks.
     *
     * Used when recursing into block-like structures during hoisting. Function
     * declarations inside blocks are block-scoped per ES2015+; only their var
     * binding name is hoisted via hoistBlockFunctionDeclarations (Annex B).
     *
     * @param Node[] $statements
     */
    private function hoistVarDeclarationsOnly(array $statements, Environment $env): void
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    $this->hoistVarNames($decl->id, $env);
                }
            } elseif ($stmt instanceof ForOfStatement || $stmt instanceof ForInStatement) {
                if ($stmt->left instanceof VariableDeclaration && $stmt->left->kind === 'var') {
                    foreach ($stmt->left->declarations as $decl) {
                        $this->hoistVarNames($decl->id, $env);
                    }
                }
                if ($stmt->body instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                }
            } elseif ($stmt instanceof ForStatement) {
                if ($stmt->init instanceof VariableDeclaration && $stmt->init->kind === 'var') {
                    foreach ($stmt->init->declarations as $decl) {
                        $this->hoistVarNames($decl->id, $env);
                    }
                }
                if ($stmt->body instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                }
            } elseif (
                $stmt instanceof \PhpJs\Ast\Statement\WhileStatement
                || $stmt instanceof \PhpJs\Ast\Statement\DoWhileStatement
            ) {
                if ($stmt->body instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                }
            } elseif ($stmt instanceof \PhpJs\Ast\Statement\IfStatement) {
                if ($stmt->consequent instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->consequent->body, $env);
                }
                if ($stmt->alternate instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->alternate->body, $env);
                } elseif ($stmt->alternate instanceof \PhpJs\Ast\Statement\IfStatement) {
                    $this->hoistVarDeclarationsOnly([$stmt->alternate], $env);
                }
            } elseif ($stmt instanceof \PhpJs\Ast\Statement\BlockStatement) {
                $this->hoistVarDeclarationsOnly($stmt->body, $env);
            } elseif ($stmt instanceof TryStatement) {
                $this->hoistVarDeclarationsOnly($stmt->block->body, $env);
                if ($stmt->handler !== null) {
                    $this->hoistVarDeclarationsOnly($stmt->handler->body->body, $env);
                }
                if ($stmt->finalizer !== null) {
                    $this->hoistVarDeclarationsOnly($stmt->finalizer->body, $env);
                }
            } elseif ($stmt instanceof SwitchStatement) {
                foreach ($stmt->cases as $case) {
                    $this->hoistVarDeclarationsOnly($case->consequent, $env);
                }
            } elseif ($stmt instanceof LabeledStatement) {
                $this->hoistVarDeclarationsOnly([$stmt->body], $env);
            } elseif ($stmt instanceof WithStatement) {
                if ($stmt->body instanceof \PhpJs\Ast\Statement\BlockStatement) {
                    $this->hoistVarDeclarationsOnly($stmt->body->body, $env);
                } else {
                    $this->hoistVarDeclarationsOnly([$stmt->body], $env);
                }
            }
        }
    }

    /**
     * Annex B block-scoped function hoisting for sloppy mode.
     *
     * Recurse into block-like structures and hoist any function declaration
     * names found inside to the given environment as undefined. The actual
     * value is assigned when the block executes.
     */
    /**
     * @param array<string, bool> $lexicalNames Top-level let/const names to skip.
     */
    private function hoistBlockFunctionDeclarations(
        Node $stmt,
        Environment $env,
        array $lexicalNames = [],
    ): void {
        $children = match (true) {
            $stmt instanceof BlockStatement => $stmt->body,
            $stmt instanceof IfStatement => array_filter([
                $stmt->consequent,
                $stmt->alternate,
            ]),
            $stmt instanceof LabeledStatement => [$stmt->body],
            default => [],
        };

        // Per B.3.3.1 step ii: skip if the name already exists in the scope
        // (parameter) or would conflict with a lexical declaration.
        $canHoist = function (string $name) use ($env, $lexicalNames): bool {
            return !$env->has($name) && !isset($lexicalNames[$name]);
        };

        // Switch statements: collect function declarations from case bodies.
        if ($stmt instanceof SwitchStatement) {
            foreach ($stmt->cases as $case) {
                foreach ($case->consequent as $inner) {
                    if ($inner instanceof FunctionDeclaration && !$inner->async && !$inner->generator) {
                        if ($canHoist($inner->id->name)) {
                            $env->defineAnnexBVar($inner->id->name, JsUndefined::instance());
                        }
                    }
                }
            }
        }

        foreach ($children as $child) {
            if ($child instanceof FunctionDeclaration) {
                if ($canHoist($child->id->name)) {
                    $env->defineAnnexBVar($child->id->name, JsUndefined::instance());
                }
            } elseif ($child instanceof BlockStatement) {
                foreach ($child->body as $inner) {
                    if ($inner instanceof FunctionDeclaration) {
                        if ($canHoist($inner->id->name)) {
                            $env->defineAnnexBVar($inner->id->name, JsUndefined::instance());
                        }
                    }
                }
            }
        }
    }

    private function hoistVarNames(Node $pattern, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            if (!$env->has($pattern->name)) {
                // At global scope use the correct user-var descriptor; in nested
                // scopes use defineVar (no linked object).
                if ($env->getLinkedObject() !== null) {
                    $env->defineGlobalVar($pattern->name, JsUndefined::instance());
                } else {
                    $env->defineVar($pattern->name, JsUndefined::instance());
                }
            }
        } elseif ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $this->hoistVarNames($elem, $env);
                }
            }
        } elseif ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->hoistVarNames($prop->value, $env);
                } elseif ($prop instanceof RestElement) {
                    $this->hoistVarNames($prop->argument, $env);
                }
            }
        } elseif ($pattern instanceof AssignmentPattern) {
            $this->hoistVarNames($pattern->left, $env);
        } elseif ($pattern instanceof RestElement) {
            $this->hoistVarNames($pattern->argument, $env);
        }
    }

    /**
     * Collect all identifier names bound by a binding pattern (Identifier,
     * ArrayPattern, ObjectPattern, RestElement, AssignmentPattern).
     * Used to populate TDZ environments for for-of/for-in head evaluation.
     *
     * @return list<string>
     */
    private function patternBoundNames(Node $pattern): array
    {
        if ($pattern instanceof Identifier) {
            return [$pattern->name];
        }
        if ($pattern instanceof ArrayPattern) {
            $names = [];
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $names = array_merge($names, $this->patternBoundNames($elem));
                }
            }
            return $names;
        }
        if ($pattern instanceof ObjectPattern) {
            $names = [];
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $names = array_merge($names, $this->patternBoundNames($prop->value));
                } elseif ($prop instanceof RestElement) {
                    $names = array_merge($names, $this->patternBoundNames($prop->argument));
                }
            }
            return $names;
        }
        if ($pattern instanceof AssignmentPattern) {
            return $this->patternBoundNames($pattern->left);
        }
        if ($pattern instanceof RestElement) {
            return $this->patternBoundNames($pattern->argument);
        }
        return [];
    }

    /**
     * Force-hoist var names into the target environment, even when a binding
     * of the same name exists in a parent scope. Used for the separate body
     * environment in functions with parameter expressions, where body vars
     * must shadow parameter/parent bindings.
     *
     * @param Node[] $statements
     */
    private function forceHoistVarNames(array $statements, Environment $env): void
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    $this->forceDefineVarName($decl->id, $env);
                }
            } elseif ($stmt instanceof ForOfStatement || $stmt instanceof ForInStatement) {
                if ($stmt->left instanceof VariableDeclaration && $stmt->left->kind === 'var') {
                    foreach ($stmt->left->declarations as $decl) {
                        $this->forceDefineVarName($decl->id, $env);
                    }
                }
                if ($stmt->body instanceof BlockStatement) {
                    $this->forceHoistVarNames($stmt->body->body, $env);
                }
            } elseif ($stmt instanceof ForStatement) {
                if ($stmt->init instanceof VariableDeclaration && $stmt->init->kind === 'var') {
                    foreach ($stmt->init->declarations as $decl) {
                        $this->forceDefineVarName($decl->id, $env);
                    }
                }
                if ($stmt->body instanceof BlockStatement) {
                    $this->forceHoistVarNames($stmt->body->body, $env);
                }
            } elseif ($stmt instanceof \PhpJs\Ast\Statement\WhileStatement || $stmt instanceof DoWhileStatement) {
                if ($stmt->body instanceof BlockStatement) {
                    $this->forceHoistVarNames($stmt->body->body, $env);
                }
            } elseif ($stmt instanceof IfStatement) {
                if ($stmt->consequent instanceof BlockStatement) {
                    $this->forceHoistVarNames($stmt->consequent->body, $env);
                }
                if ($stmt->alternate instanceof BlockStatement) {
                    $this->forceHoistVarNames($stmt->alternate->body, $env);
                }
            } elseif ($stmt instanceof BlockStatement) {
                $this->forceHoistVarNames($stmt->body, $env);
            } elseif ($stmt instanceof WithStatement) {
                if ($stmt->body instanceof BlockStatement) {
                    $this->forceHoistVarNames($stmt->body->body, $env);
                } else {
                    $this->forceHoistVarNames([$stmt->body], $env);
                }
            }
        }
    }

    private function forceDefineVarName(Node $pattern, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            $env->defineVar($pattern->name, JsUndefined::instance());
        } elseif ($pattern instanceof ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    $this->forceDefineVarName($elem, $env);
                }
            }
        } elseif ($pattern instanceof ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof AssignmentProperty) {
                    $this->forceDefineVarName($prop->value, $env);
                } elseif ($prop instanceof RestElement) {
                    $this->forceDefineVarName($prop->argument, $env);
                }
            }
        } elseif ($pattern instanceof AssignmentPattern) {
            $this->forceDefineVarName($pattern->left, $env);
        } elseif ($pattern instanceof RestElement) {
            $this->forceDefineVarName($pattern->argument, $env);
        }
    }

    private function copyBindingToChild(Node $pattern, Environment $source, Environment $target): void
    {
        if ($pattern instanceof Identifier) {
            $target->defineVar($pattern->name, $source->get($pattern->name));
        }
    }

    /**
     * Collect all binding names from a pattern (Identifier or destructuring).
     *
     * @param string[] $names Collected names (by reference).
     */
    private function collectBindingNames(Node $pattern, array &$names): void
    {
        if ($pattern instanceof Identifier) {
            $names[] = $pattern->name;
        } elseif ($pattern instanceof \PhpJs\Ast\Pattern\ArrayPattern) {
            foreach ($pattern->elements as $elem) {
                if ($elem !== null) {
                    if ($elem instanceof \PhpJs\Ast\Pattern\RestElement) {
                        $this->collectBindingNames($elem->argument, $names);
                    } elseif ($elem instanceof \PhpJs\Ast\Pattern\AssignmentPattern) {
                        $this->collectBindingNames($elem->left, $names);
                    } else {
                        $this->collectBindingNames($elem, $names);
                    }
                }
            }
        } elseif ($pattern instanceof \PhpJs\Ast\Pattern\ObjectPattern) {
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof \PhpJs\Ast\Pattern\RestElement) {
                    $this->collectBindingNames($prop->argument, $names);
                } else {
                    $this->collectBindingNames($prop->value, $names);
                }
            }
        } elseif ($pattern instanceof \PhpJs\Ast\Pattern\AssignmentPattern) {
            $this->collectBindingNames($pattern->left, $names);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveReference(Node $node, Environment $env): Reference
    {
        if ($node instanceof Identifier) {
            // Per spec 13.15.2 step 1: evaluate the LHS to get a Reference.
            // For identifier references, the spec resolves which environment
            // record owns the binding at reference-creation time. This matters
            // when a "with" object environment record is involved: PutValue
            // must use the originally-resolved binding object even if the
            // binding is deleted or a new binding is created before PutValue
            // runs (see S11.13.1_A5_T2, S11.13.1_A5_T3, S11.13.1_A6_T3).
            return $this->resolveIdentifierReference($env, $node->name);
        }

        if ($node instanceof MemberExpression) {
            // super.prop = value: the reference base is the super prototype,
            // but setValue must use the current this (spec §6.2.4.5 step 6b).
            if ($node->object instanceof Identifier && $node->object->name === 'super') {
                try {
                    $homeObject = $env->get('[[HomeObject]]');
                } catch (\Throwable) {
                    $homeObject = null;
                }
                $superBase = $homeObject instanceof JsObject ? $homeObject->getPrototype() : null;
                // Per spec §12.3.5.3 step 5, RequireObjectCoercible(baseValue) throws TypeError
                // if baseValue is null/undefined. Use JsNull as a sentinel so that the TypeError
                // is thrown at PutValue/GetValue time (after the RHS is evaluated), not here.
                $refBase = $superBase ?? \PhpJs\Value\JsNull::instance();
                // The actual `this` is the receiver for [[Set]] and getter invocations.
                try {
                    $superThisVal = $env->get('this');
                } catch (\Throwable) {
                    $superThisVal = null;
                }
                $superThisObj = $superThisVal instanceof JsObject ? $superThisVal : null;
                if ($node->computed) {
                    $rawRefKey = $this->evaluate($node->property, $env);
                    if ($rawRefKey instanceof JsSymbol) {
                        return new Reference(
                            $refBase,
                            '',
                            $this->strictMode,
                            $rawRefKey,
                            thisValue: $superThisObj,
                        );
                    }
                    // Defer ToPropertyKey for object keys so that RHS is evaluated
                    // before the key's toString() is invoked (spec evaluation order).
                    if ($rawRefKey instanceof JsObject) {
                        return new Reference(
                            $refBase,
                            '',
                            $this->strictMode,
                            rawKey: $rawRefKey,
                            thisValue: $superThisObj,
                        );
                    }
                    return new Reference(
                        $refBase,
                        TypeConversion::toString($rawRefKey),
                        $this->strictMode,
                        thisValue: $superThisObj,
                    );
                }
                $key = $node->property instanceof Identifier ? $node->property->name : '';
                return new Reference($refBase, $key, $this->strictMode, thisValue: $superThisObj);
            }

            $obj = $this->evaluate($node->object, $env);
            // Per spec 6.2.4.5 PutValue, the reference records the raw base value.
            // ToObject() is deferred until PutValue (setValue). For primitives,
            // keeping the raw value here lets setValue correctly throw TypeError
            // in strict mode (PutValue step 4c: if [[Set]] returns false and strict is true).
            $base = $obj;
            // Evaluate the computed property expression (left-to-right), but
            // defer ToPropertyKey (toString) until getValue/setValue so the
            // RHS of assignments runs before the key conversion.
            if ($node->computed) {
                $rawRefKey = $this->evaluate($node->property, $env);
                if ($rawRefKey instanceof JsSymbol) {
                    return new Reference($base, '', $this->strictMode, $rawRefKey);
                }
                // For primitive keys (strings, numbers, booleans), convert now
                // since they have no side-effecting toString. For objects,
                // defer by storing the rawKey on the Reference.
                if ($rawRefKey instanceof JsObject) {
                    return new Reference($base, '', $this->strictMode, rawKey: $rawRefKey);
                }
                $key = TypeConversion::toString($rawRefKey);
                return new Reference($base, $key, $this->strictMode);
            }
            $key = $node->property instanceof Identifier ? $node->property->name : '';
            return new Reference($base, $key, $this->strictMode);
        }

        throw new ReferenceError('Invalid assignment target');
    }

    /**
     * Eagerly resolve an identifier reference by walking the environment chain.
     *
     * Per spec 9.1.2.1 GetIdentifierReference: this finds the environment
     * record that owns the binding and returns a Reference whose base is
     * either that environment (for declarative records) or the binding
     * object (for object environment records, i.e. "with" scopes). This
     * ensures PutValue operates on the originally-resolved target even if
     * the scope chain changes between LHS evaluation and PutValue.
     */
    private function resolveIdentifierReference(Environment $env, string $name): Reference
    {
        // Walk the environment chain to find the owning record.
        $cur = $env;
        while ($cur !== null) {
            // Check for "with" (object environment record) first: if the
            // binding object has the property, create a property reference
            // so PutValue writes directly to the object.
            $withObj = $this->getWithObject($cur);
            if ($withObj !== null) {
                if ($withObj->has($name)) {
                    return new Reference($withObj, $name, $this->strictMode);
                }
                // The with-object does not have the binding; skip to parent.
                $cur = $cur->getParent();
                continue;
            }
            // Declarative environment record: check own bindings.
            if ($cur->hasOwnBinding($name)) {
                return new Reference($cur, $name, $this->strictMode);
            }
            // Also check the linked global object for properties set directly.
            $linked = $cur->getLinkedObject();
            if ($linked !== null && $cur->getParent() === null && $linked->hasOwnProperty($name)) {
                return new Reference($cur, $name, $this->strictMode);
            }
            $cur = $cur->getParent();
        }
        // Not found: return reference to the original env (will throw on set in strict mode).
        return new Reference($env, $name, $this->strictMode);
    }

    /**
     * Extract the private withObject from an Environment using reflection.
     * This is needed to create property references for "with" scopes so that
     * PutValue operates on the binding object directly.
     */
    private function getWithObject(Environment $env): ?JsObject
    {
        static $prop = null;
        if ($prop === null) {
            $prop = new \ReflectionProperty(Environment::class, 'withObject');
        }
        return $prop->getValue($env);
    }

    private function isDestructuringTarget(Node $node): bool
    {
        return $node instanceof ArrayPattern
            || $node instanceof ObjectPattern
            || $node instanceof ArrayExpression
            || $node instanceof ObjectExpression;
    }

    private function destructureAssign(Node $target, JsValue $value, Environment $env): void
    {
        if ($target instanceof ArrayPattern || $target instanceof ArrayExpression) {
            [$iterator, $nextMethod] = $this->getIteratorOrThrow($value);
            $done = false;
            $elements = $target instanceof ArrayPattern ? $target->elements : $target->elements;
            foreach ($elements as $elem) {
                if ($elem instanceof RestElement || $elem instanceof SpreadElement) {
                    $restValue = $this->iteratorRest($iterator, $nextMethod, $done);
                    $restArg = $elem->argument;
                    if ($this->isDestructuringTarget($restArg)) {
                        $this->destructureAssign($restArg, $restValue, $env);
                    } else {
                        $ref = $this->resolveReference($restArg, $env);
                        $ref->setValue($restValue);
                    }
                    break;
                }
                $elemValue = $this->iteratorNext($iterator, $nextMethod, $done);
                if ($elem === null) {
                    // Elision: advance iterator but discard value.
                    continue;
                }
                if ($elem instanceof AssignmentPattern || $elem instanceof AssignmentExpression) {
                    $elemTarget = $elem instanceof AssignmentPattern ? $elem->left : $elem->left;
                    $defaultNode = $elem instanceof AssignmentPattern ? $elem->right : $elem->right;
                    if ($elemValue instanceof JsUndefined) {
                        $elemValue = $this->evaluate($defaultNode, $env);
                        // Function name inference: check AST node type.
                        if (
                            $elemValue instanceof JsFunction
                            && $elemTarget instanceof Identifier
                            && $this->isAnonymousFunctionDefinitionNode($defaultNode)
                        ) {
                            $elemValue->setName($elemTarget->name);
                        }
                    }
                    if ($this->isDestructuringTarget($elemTarget)) {
                        $this->destructureAssign($elemTarget, $elemValue, $env);
                    } else {
                        $ref = $this->resolveReference($elemTarget, $env);
                        $ref->setValue($elemValue);
                    }
                } elseif ($this->isDestructuringTarget($elem)) {
                    $this->destructureAssign($elem, $elemValue, $env);
                } else {
                    $ref = $this->resolveReference($elem, $env);
                    $ref->setValue($elemValue);
                }
            }
            return;
        }

        if ($target instanceof ObjectPattern || $target instanceof ObjectExpression) {
            // Object destructuring calls ToObject — throws TypeError on null/undefined.
            if ($value instanceof JsNull || $value instanceof JsUndefined) {
                throw new \PhpJs\Exceptions\TypeError(
                    "Cannot destructure property of " . TypeConversion::toString($value),
                );
            }
            $props = $target instanceof ObjectPattern ? $target->properties : $target->properties;
            $usedKeys = [];
            foreach ($props as $prop) {
                if ($prop instanceof RestElement || $prop instanceof SpreadElement) {
                    // Collect all own enumerable properties not already consumed.
                    $restObj = new JsObject();
                    if ($value instanceof JsObject) {
                        // Per spec: object rest only includes own enumerable properties.
                        foreach ($value->getOwnEnumerableKeys() as $rk) {
                            if (!in_array($rk, $usedKeys, true)) {
                                $restObj->set($rk, $value->get($rk));
                            }
                        }
                    }
                    $restArg = $prop instanceof RestElement ? $prop->argument : $prop->argument;
                    if ($this->isDestructuringTarget($restArg)) {
                        $this->destructureAssign($restArg, $restObj, $env);
                    } else {
                        $ref = $this->resolveReference($restArg, $env);
                        $ref->setValue($restObj);
                    }
                    break;
                }
                $propNode = $prop instanceof AssignmentProperty ? $prop : $prop;
                $key = ($propNode instanceof AssignmentProperty || $propNode instanceof Property)
                    ? ($propNode->computed
                        ? TypeConversion::toString($this->evaluate($propNode->key, $env))
                        : ($propNode->key instanceof Identifier
                            ? $propNode->key->name
                            : TypeConversion::toString($this->evaluate($propNode->key, $env))))
                    : '';
                $usedKeys[] = $key;
                $propValue = ($value instanceof JsObject)
                    ? $value->get($key)
                    : JsUndefined::instance();

                $valueNode = ($propNode instanceof AssignmentProperty || $propNode instanceof Property)
                    ? $propNode->value
                    : $propNode;
                if ($valueNode instanceof AssignmentPattern || $valueNode instanceof AssignmentExpression) {
                    $realTarget = $valueNode instanceof AssignmentPattern
                        ? $valueNode->left
                        : $valueNode->left;
                    $defaultNode2 = $valueNode instanceof AssignmentPattern ? $valueNode->right : $valueNode->right;
                    if ($propValue instanceof JsUndefined) {
                        $propValue = $this->evaluate($defaultNode2, $env);
                        // Function name inference: check AST node type.
                        if (
                            $propValue instanceof JsFunction
                            && $realTarget instanceof Identifier
                            && $this->isAnonymousFunctionDefinitionNode($defaultNode2)
                        ) {
                            $propValue->setName($realTarget->name);
                        }
                    }
                    if ($this->isDestructuringTarget($realTarget)) {
                        $this->destructureAssign($realTarget, $propValue, $env);
                    } else {
                        $ref = $this->resolveReference($realTarget, $env);
                        $ref->setValue($propValue);
                    }
                } elseif ($this->isDestructuringTarget($valueNode)) {
                    // Nested destructuring target (e.g., { x: { y } } = ...).
                    $this->destructureAssign($valueNode, $propValue, $env);
                } else {
                    $ref = $this->resolveReference($valueNode, $env);
                    $ref->setValue($propValue);
                }
            }
        }
    }

    /**
     * Get the iterator for a value; throw TypeError if not iterable.
     * Returns [iterator, nextMethod].
     *
     * @return array{JsObject, JsFunction}
     */
    private function getIteratorOrThrow(JsValue $value): array
    {
        $iterator = $this->getIterator($value);
        if ($iterator === null) {
            $typeName = $value instanceof JsNumber ? 'number'
                : ($value instanceof JsBoolean ? 'boolean'
                : ($value instanceof JsSymbol ? 'symbol'
                : TypeConversion::toString($value)));
            throw new \PhpJs\Exceptions\TypeError($typeName . ' is not iterable');
        }
        $nextMethod = $iterator->get('next');
        if (!$nextMethod instanceof JsFunction) {
            throw new \PhpJs\Exceptions\TypeError('Iterator result next is not a function');
        }
        return [$iterator, $nextMethod];
    }

    /**
     * Advance an iterator one step. Returns the value, or undefined when done.
     * Sets $done to true once the iterator reports done.
     */
    private function iteratorNext(JsObject $iterator, JsFunction $nextMethod, bool &$done): JsValue
    {
        if ($done) {
            return JsUndefined::instance();
        }
        $result = $this->callFunction($nextMethod, $iterator, []);
        if (!$result instanceof JsObject) {
            throw new \PhpJs\Exceptions\TypeError('Iterator result is not an object');
        }
        if (TypeConversion::toBoolean($result->get('done'))) {
            $done = true;
            return JsUndefined::instance();
        }
        return $result->get('value');
    }

    /**
     * Collect all remaining iterator values into a JsArray.
     */
    private function iteratorRest(JsObject $iterator, JsFunction $nextMethod, bool &$done): JsArray
    {
        $rest = [];
        while (!$done) {
            $v = $this->iteratorNext($iterator, $nextMethod, $done);
            if (!$done) {
                $rest[] = $v;
            }
        }
        return JsArray::fromArray($rest);
    }

    /**
     * Per ECMAScript spec: IsAnonymousFunctionDefinition.
     * Returns true only if the node is a function/arrow/class expression WITHOUT a name.
     * Used to determine whether name inference applies when assigning to a binding.
     */
    private function isAnonymousFunctionDefinitionNode(Node $node): bool
    {
        if ($node instanceof FunctionExpression && $node->name === null) {
            return true;
        }
        if ($node instanceof ArrowFunction) {
            return true;
        }
        if ($node instanceof ClassExpression && $node->id === null) {
            return true;
        }
        return false;
    }

    /**
     * Check whether a function/class has an explicitly user-defined .name property.
     *
     * JsFunction constructor always sets .name (writable:false, enumerable:false, configurable:true)
     * with a JsString value. If the .name property has been overridden by user code (e.g.
     * static name() {} in a class body), the descriptor will differ (writable:true, or value
     * is not a JsString). This lets name inference distinguish default .name from explicit .name.
     */
    private function hasExplicitNameProperty(JsFunction $fn): bool
    {
        $desc = $fn->getOwnPropertyDescriptor('name');
        if ($desc === null) {
            return false;
        }
        // If the name property is not a simple data property with a string value and
        // writable:false, it was explicitly overridden (e.g. static name() {} method).
        if ($desc->isAccessorDescriptor()) {
            return true;
        }
        if ($desc->writable !== false) {
            return true;
        }
        if (!$desc->value instanceof JsString) {
            return true;
        }
        return false;
    }

    private function handleAbrupt(Completion $completion): JsValue
    {
        if ($completion->type === CompletionType::Throw) {
            $this->throwJsValue($completion->value);
        }
        return $completion->value;
    }

    /**
     * Convert a PHP exception (representing a JS error) to a JS value
     * suitable for use in a Completion::throw() record.
     */
    private function phpExceptionToJsValue(\RuntimeException $e): JsValue
    {
        $name = match (true) {
            $e instanceof \PhpJs\Exceptions\SyntaxError => 'SyntaxError',
            $e instanceof TypeError => 'TypeError',
            $e instanceof ReferenceError => 'ReferenceError',
            $e instanceof \PhpJs\Exceptions\RangeError => 'RangeError',
            default => 'Error',
        };

        $errorObj = new JsObject();
        $errorObj->set('message', new JsString($e->getMessage()));
        $errorObj->set('name', new JsString($name));
        $errorObj->set('stack', new JsString($name . ': ' . $e->getMessage()));

        // Set constructor to the global error constructor for instanceof/constructor checks
        if ($this->globalEnv->has($name)) {
            $constructor = $this->globalEnv->get($name);
            if ($constructor instanceof JsFunction) {
                $errorObj->set('constructor', $constructor);
                $proto = $constructor->get('prototype');
                if ($proto instanceof JsObject) {
                    $errorObj->setPrototype($proto);
                }
            }
        }

        return $errorObj;
    }

    /** @return never */
    private function throwJsValue(JsValue $value): void
    {
        // Always use JsThrowable to preserve the original JS value.
        // execTryStatement catches JsThrowable and extracts jsValue for the catch block.
        throw new \PhpJs\Exceptions\JsThrowable($value, TypeConversion::toString($value));
    }

    public function getCallStack(): CallStack
    {
        return $this->callStack;
    }

    public function getGlobalEnv(): Environment
    {
        return $this->globalEnv;
    }

    /**
     * Create a RegExp-like object from a pattern and flags string.
     * Uses PHP's PCRE2 engine under the hood.
     */
    public function createRegExpFromConstructor(string $pattern, string $flags): JsObject
    {
        return $this->createRegExpObject($pattern, $flags);
    }

    private function createRegExpObject(string $pattern, string $flags): JsObject
    {
        // Validate flags per spec 22.2.3.1: only valid flag characters, no duplicates.
        // 'v' is the unicodeSets flag (ES2024), mutually exclusive with 'u'.
        $validFlags = 'dgimsuvy';
        $seenFlags = [];
        for ($fi = 0; $fi < strlen($flags); $fi++) {
            $ch = $flags[$fi];
            if (strpos($validFlags, $ch) === false) {
                throw new \PhpJs\Exceptions\SyntaxError("Invalid flags supplied to RegExp constructor '{$flags}'");
            }
            if (isset($seenFlags[$ch])) {
                throw new \PhpJs\Exceptions\SyntaxError("Invalid flags supplied to RegExp constructor '{$flags}'");
            }
            $seenFlags[$ch] = true;
        }
        // 'u' and 'v' are mutually exclusive per spec.
        if (str_contains($flags, 'u') && str_contains($flags, 'v')) {
            throw new \PhpJs\Exceptions\SyntaxError("Invalid flags supplied to RegExp constructor '{$flags}'");
        }

        $isUnicode = str_contains($flags, 'u') || str_contains($flags, 'v');

        // Unicode mode validation per spec B.1.4: octal escapes and certain
        // identity escapes are not allowed in /u patterns.
        if ($isUnicode) {
            $this->validateUnicodePattern($pattern);
        }

        $regexpProto = null;
        if ($this->globalEnv->has('RegExp')) {
            $ctor = $this->globalEnv->get('RegExp');
            if ($ctor instanceof JsFunction) {
                $proto = $ctor->get('prototype');
                if ($proto instanceof JsObject) {
                    $regexpProto = $proto;
                }
            }
        }
        $obj = new JsObject($regexpProto);

        // Per spec §22.2.5.3 get RegExp.prototype.flags, flags are returned in canonical order:
        // d, g, i, m, s, u, v, y (alphabetical subset of valid flag characters).
        $canonicalFlagOrder = 'dgimsvy';
        $sortedFlags = '';
        for ($fi = 0; $fi < strlen($canonicalFlagOrder); $fi++) {
            if (str_contains($flags, $canonicalFlagOrder[$fi])) {
                $sortedFlags .= $canonicalFlagOrder[$fi];
            }
        }

        // Per spec §22.2.6, regexp instance own properties are non-writable, non-enumerable.
        // source and flags are configurable (per modern ES), others are non-configurable.
        // Making all configurable to allow Object.defineProperty overrides in tests.
        $noenum = static fn (JsValue $v) => PropertyDescriptor::data($v, false, false, true);
        $obj->defineOwnProperty('source', $noenum(new JsString($pattern === '' ? '(?:)' : $pattern)));
        $obj->defineOwnProperty('flags', $noenum(new JsString($sortedFlags)));
        // Internal slots for compile() per Annex B: these are not affected by
        // user-visible property overrides on the instance.
        $obj->defineOwnProperty(
            '[[OriginalSource]]',
            PropertyDescriptor::data(new JsString($pattern), false, false, false),
        );
        $obj->defineOwnProperty(
            '[[OriginalFlags]]',
            PropertyDescriptor::data(new JsString($sortedFlags), false, false, false),
        );
        $obj->defineOwnProperty('global', $noenum(new JsBoolean(str_contains($flags, 'g'))));
        $obj->defineOwnProperty('ignoreCase', $noenum(new JsBoolean(str_contains($flags, 'i'))));
        $obj->defineOwnProperty('multiline', $noenum(new JsBoolean(str_contains($flags, 'm'))));
        $obj->defineOwnProperty('dotAll', $noenum(new JsBoolean(str_contains($flags, 's'))));
        $obj->defineOwnProperty('unicode', $noenum(new JsBoolean(str_contains($flags, 'u'))));
        $obj->defineOwnProperty('unicodeSets', $noenum(new JsBoolean(str_contains($flags, 'v'))));
        $obj->defineOwnProperty('sticky', $noenum(new JsBoolean(str_contains($flags, 'y'))));
        $obj->defineOwnProperty('hasIndices', $noenum(new JsBoolean(str_contains($flags, 'd'))));
        // lastIndex is writable but not enumerable, not configurable per spec.
        $obj->defineOwnProperty('lastIndex', PropertyDescriptor::data(new JsNumber(0.0), true, false, false));

        // Build PCRE pattern.
        $pcreFlags = '';
        if (str_contains($flags, 'i')) {
            $pcreFlags .= 'i';
        }
        if (str_contains($flags, 'm')) {
            $pcreFlags .= 'm';
        }
        if (str_contains($flags, 's')) {
            $pcreFlags .= 's';
        }

        // Transform ECMAScript-specific character class escapes for PCRE compatibility.
        // PCRE's \s does not include U+FEFF; ECMAScript's does.
        $transformedPattern = $this->transformEsPatternForPcre($pattern);

        // Escape unescaped forward slashes for the PCRE delimiter.
        // Already-escaped slashes (\/) must not be double-escaped.
        $escapedPattern = $this->escapeForPcreDelimiter($transformedPattern);
        $pcrePattern = '/' . $escapedPattern . '/' . $pcreFlags . 'u';

        // Validate the pattern compiles. Throw SyntaxError if invalid.
        if (@preg_match($pcrePattern, '') === false) {
            throw new \PhpJs\Exceptions\SyntaxError(
                'Invalid regular expression: /' . $pattern . '/: ' . preg_last_error_msg(),
            );
        }

        $isGlobal = str_contains($flags, 'g');
        $isSticky = str_contains($flags, 'y');

        // Store the compiled PCRE pattern as a non-enumerable internal slot so prototype
        // methods (exec, test) installed on RegExp.prototype can access it via $this_.
        $obj->defineOwnProperty(
            '[[PCREPattern]]',
            PropertyDescriptor::data(new JsString($pcrePattern), false, false, false),
        );

        // exec(): handles lastIndex for global/sticky regexes per spec 22.2.5.2.
        $execFn = function (JsValue $this_, array $args) use ($pcrePattern, $obj, $isGlobal, $isSticky): JsValue {
            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : '';
            $strLen = mb_strlen($str, 'UTF-8');

            if ($isGlobal || $isSticky) {
                $lastIndexVal = $obj->get('lastIndex');
                $lastIndex = (int) TypeConversion::toNumber($lastIndexVal);
            } else {
                $lastIndex = 0;
            }

            if ($lastIndex < 0 || $lastIndex > $strLen) {
                if ($isGlobal || $isSticky) {
                    // Per spec: Set(R, "lastIndex", 0, Throw=true).
                    $obj->set('lastIndex', new JsNumber(0.0), true);
                }
                return JsNull::instance();
            }

            // Use byte offset for PCRE: convert character offset to byte offset.
            $byteOffset = strlen(mb_substr($str, 0, $lastIndex, 'UTF-8'));

            if (@preg_match($pcrePattern, $str, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $byteOffset)) {
                $matchBytePos = $matches[0][1];
                // For sticky regex, the match must start exactly at lastIndex.
                if ($isSticky && $matchBytePos !== $byteOffset) {
                    // Per spec: Set(R, "lastIndex", 0, Throw=true).
                    $obj->set('lastIndex', new JsNumber(0.0), true);
                    return JsNull::instance();
                }
                // Convert byte position back to character position.
                $matchCharPos = mb_strlen(substr($str, 0, $matchBytePos), 'UTF-8');
                $matchStr = $matches[0][0];
                $matchCharLen = mb_strlen($matchStr, 'UTF-8');

                if ($isGlobal || $isSticky) {
                    // Per spec: Set(R, "lastIndex", matchEnd, Throw=true).
                    $obj->set('lastIndex', new JsNumber((float) ($matchCharPos + $matchCharLen)), true);
                }

                // Build result array with numeric capture groups.
                $numericCount = 0;
                $elements = [];
                foreach ($matches as $key => $match) {
                    if (is_int($key)) {
                        $elements[] = ($match[1] === -1 || $match[0] === null)
                            ? JsUndefined::instance()
                            : new JsString($match[0]);
                        $numericCount++;
                    }
                }
                $result = JsArray::fromArray($elements);
                $result->set('index', new JsNumber((float) $matchCharPos));
                $result->set('input', new JsString($str));

                // Named capture groups.
                $groups = new JsObject(null);
                $hasGroups = false;
                foreach ($matches as $key => $match) {
                    if (is_string($key)) {
                        $hasGroups = true;
                        $groups->set($key, ($match[1] === -1 || $match[0] === null)
                            ? JsUndefined::instance()
                            : new JsString($match[0]));
                    }
                }
                $result->set('groups', $hasGroups ? $groups : JsUndefined::instance());

                return $result;
            }

            if ($isGlobal || $isSticky) {
                // Per spec: Set(R, "lastIndex", 0, Throw=true).
                $obj->set('lastIndex', new JsNumber(0.0), true);
            }
            return JsNull::instance();
        };
        $obj->defineOwnProperty(
            'exec',
            PropertyDescriptor::data(JsFunction::fromCallable('exec', $execFn, 1), true, false, true),
        );

        // test(): uses lastIndex for global/sticky regexes per spec 22.2.5.3.
        $testFn = function (JsValue $this_, array $args) use ($pcrePattern, $obj, $isGlobal, $isSticky): JsValue {
            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : '';
            $strLen = mb_strlen($str, 'UTF-8');

            if ($isGlobal || $isSticky) {
                $lastIndexVal = $obj->get('lastIndex');
                $lastIndex = (int) TypeConversion::toNumber($lastIndexVal);
            } else {
                $lastIndex = 0;
            }

            if ($lastIndex < 0 || $lastIndex > $strLen) {
                if ($isGlobal || $isSticky) {
                    $obj->set('lastIndex', new JsNumber(0.0));
                }
                return new JsBoolean(false);
            }

            $byteOffset = strlen(mb_substr($str, 0, $lastIndex, 'UTF-8'));

            $result = @preg_match($pcrePattern, $str, $matches, PREG_OFFSET_CAPTURE, $byteOffset);
            if ($result === 1) {
                $matchBytePos = $matches[0][1];
                if ($isSticky && $matchBytePos !== $byteOffset) {
                    $obj->set('lastIndex', new JsNumber(0.0));
                    return new JsBoolean(false);
                }
                if ($isGlobal || $isSticky) {
                    $matchCharPos = mb_strlen(substr($str, 0, $matchBytePos), 'UTF-8');
                    $matchCharLen = mb_strlen($matches[0][0], 'UTF-8');
                    $obj->set('lastIndex', new JsNumber((float) ($matchCharPos + $matchCharLen)));
                }
                return new JsBoolean(true);
            }

            if ($isGlobal || $isSticky) {
                $obj->set('lastIndex', new JsNumber(0.0));
            }
            return new JsBoolean(false);
        };
        $obj->defineOwnProperty(
            'test',
            PropertyDescriptor::data(JsFunction::fromCallable('test', $testFn, 1), true, false, true),
        );

        // toString(): returns /pattern/flags per spec 22.2.5.14.
        $displayPattern = $pattern === '' ? '(?:)' : $pattern;
        $toStringFn = function () use ($displayPattern, $sortedFlags): JsValue {
            return new JsString("/{$displayPattern}/{$sortedFlags}");
        };
        $obj->defineOwnProperty(
            'toString',
            PropertyDescriptor::data(JsFunction::fromCallable('toString', $toStringFn, 0), true, false, true),
        );

        return $obj;
    }

    /**
     * Transform ECMAScript regex pattern for PCRE compatibility.
     *
     * ECMAScript \s includes U+FEFF (BOM) but PCRE \s does not.
     * This transforms \s and \S outside character classes to include FEFF.
     * Inside character classes, \s is replaced with \s\x{FEFF}.
     */
    private function transformEsPatternForPcre(string $pattern): string
    {
        // Count capturing groups for backreference validation (Annex B).
        $numGroups = $this->countCapturingGroups($pattern);
        $result = '';
        $len = strlen($pattern);
        $inCharClass = false;
        $i = 0;

        while ($i < $len) {
            $ch = $pattern[$i];

            if ($ch === '\\' && $i + 1 < $len) {
                $next = $pattern[$i + 1];
                if ($next === 's') {
                    if ($inCharClass) {
                        // Inside [...], add \x{FEFF} alongside \s.
                        $result .= '\\s\\x{FEFF}';
                    } else {
                        // Outside [...], wrap in alternation group.
                        $result .= '(?:\\s|\\x{FEFF})';
                    }
                    $i += 2;
                    continue;
                }
                if ($next === 'S') {
                    if ($inCharClass) {
                        // Inside character class, \S excluding FEFF is hard.
                        // Fall back to PCRE \S (close enough for most tests).
                        $result .= '\\S';
                    } else {
                        // Outside [...], use negative lookahead for FEFF.
                        $result .= '(?:(?!\\x{FEFF})\\S)';
                    }
                    $i += 2;
                    continue;
                }
                // \u{XXXXXX} ES2015 unicode escape with braces.
                if ($next === 'u' && $i + 2 < $len && $pattern[$i + 2] === '{') {
                    $end = strpos($pattern, '}', $i + 3);
                    if ($end !== false) {
                        $hex = substr($pattern, $i + 3, $end - ($i + 3));
                        if (ctype_xdigit($hex)) {
                            $result .= '\\x{' . strtoupper($hex) . '}';
                            $i = $end + 1;
                            continue;
                        }
                    }
                }
                // \uXXXX 4-digit Unicode escape: convert to PCRE \x{XXXX}.
                // Surrogate code points (D800-DFFF) are invalid in UTF-8 and
                // rejected by PCRE. Replace them with U+FFFE (non-character)
                // so the regex compiles; the alternatives won't match real text.
                if ($next === 'u' && $i + 5 < $len + 1) {
                    $hex = substr($pattern, $i + 2, 4);
                    if (strlen($hex) === 4 && ctype_xdigit($hex)) {
                        $codePoint = hexdec($hex);
                        if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
                            // Surrogate: replace with non-character U+FFFE to avoid PCRE error.
                            $result .= '\\x{FFFE}';
                        } else {
                            $result .= '\\x{' . strtoupper($hex) . '}';
                        }
                        $i += 6;
                        continue;
                    }
                }
                // \xNN 2-digit hex escape: convert to PCRE \x{NN} for proper
                // Unicode mode handling (avoids raw-byte interpretation in UTF-8).
                if ($next === 'x') {
                    if ($i + 3 < $len + 1) {
                        $hex = substr($pattern, $i + 2, 2);
                        if (strlen($hex) === 2 && ctype_xdigit($hex)) {
                            $result .= '\\x{' . strtoupper($hex) . '}';
                            $i += 4;
                            continue;
                        }
                    }
                    // \x without valid hex digits: in non-unicode ECMAScript,
                    // this is treated as literal 'x'. PCRE would error on bare \x.
                    $result .= 'x';
                    $i += 2;
                    continue;
                }
                // \k<name> named backreference: In non-unicode ECMAScript,
                // \k when no named groups exist is treated as literal 'k'.
                // PCRE always treats \k<...> as a backreference and errors
                // when the group doesn't exist. Convert to literal 'k' when
                // no named groups exist in the pattern.
                if ($next === 'k') {
                    if ($i + 2 < $len && $pattern[$i + 2] === '<') {
                        $closeAngle = strpos($pattern, '>', $i + 3);
                        if ($closeAngle !== false) {
                            $groupName = substr($pattern, $i + 3, $closeAngle - ($i + 3));
                            if (preg_match('/\(\?<' . preg_quote($groupName, '/') . '>/', $pattern) === 1) {
                                $result .= $ch . $next;
                                $i += 2;
                            } else {
                                $result .= 'k';
                                $i += 2;
                            }
                        } else {
                            $result .= 'k';
                            $i += 2;
                        }
                    } else {
                        $result .= 'k';
                        $i += 2;
                    }
                    continue;
                }
                // \c escape: In ECMAScript, \cX where X is a letter A-Z/a-z
                // produces a control character. If X is NOT a letter, Annex B
                // says treat \c as a literal backslash followed by 'c' (the
                // remaining chars are parsed normally).
                if ($next === 'c') {
                    if ($i + 2 < $len) {
                        $controlChar = $pattern[$i + 2];
                        if (
                            ($controlChar >= 'A' && $controlChar <= 'Z')
                            || ($controlChar >= 'a' && $controlChar <= 'z')
                        ) {
                            // Valid \cX: pass through as PCRE handles it.
                            $result .= $ch . $next . $controlChar;
                            $i += 3;
                            continue;
                        }
                    }
                    // Invalid \c: Annex B treats this as literal backslash + 'c'.
                    if ($inCharClass) {
                        // Inside character class: [\c<invalid>] means [\, c, <char>].
                        $result .= '\\\\c';
                    } else {
                        // Outside: \c<invalid> matches literal \c (backslash then c).
                        $result .= '\\\\c';
                    }
                    $i += 2;
                    continue;
                }
                // Inside character classes, \1-\9 are always octal escapes
                // (backreferences don't exist in classes). Convert to \x{XX}.
                if ($next >= '0' && $next <= '9' && $inCharClass) {
                    if ($next === '0') {
                        // \0 is NUL. Collect up to 3 octal digits.
                        $octalStr = '0';
                        $oj = $i + 2;
                        while (
                            $oj < $len
                            && $pattern[$oj] >= '0'
                            && $pattern[$oj] <= '7'
                            && strlen($octalStr) < 3
                        ) {
                            $octalStr .= $pattern[$oj];
                            $oj++;
                        }
                        $cp = octdec($octalStr);
                        $result .= '\\x{' . strtoupper(dechex($cp)) . '}';
                        $i = $oj;
                    } elseif ($next >= '1' && $next <= '7') {
                        // Octal escape \1-\7 (possibly multi-digit).
                        $octalStr = '';
                        $oj = $i + 1;
                        while (
                            $oj < $len
                            && $pattern[$oj] >= '0'
                            && $pattern[$oj] <= '7'
                            && strlen($octalStr) < 3
                        ) {
                            $octalStr .= $pattern[$oj];
                            $oj++;
                        }
                        $cp = octdec($octalStr);
                        $result .= '\\x{' . strtoupper(dechex($cp)) . '}';
                        $i = $oj;
                    } else {
                        // \8 or \9: identity escape for the digit.
                        $result .= '\\x{' . strtoupper(dechex(ord($next))) . '}';
                        $i += 2;
                    }
                    continue;
                }
                // Numeric backreferences to non-existent groups (Annex B).
                // In non-unicode mode, \N where N exceeds the group count is
                // treated as an octal escape (digits 0-7) or identity escape
                // (digits 8-9). PCRE would error on invalid backreferences.
                if ($next >= '1' && $next <= '9' && !$inCharClass) {
                    $numStr = '';
                    $j = $i + 1;
                    while ($j < $len && $pattern[$j] >= '0' && $pattern[$j] <= '9') {
                        $numStr .= $pattern[$j];
                        $j++;
                    }
                    $refNum = (int) $numStr;
                    if ($refNum > $numGroups) {
                        // Not a valid backreference. Convert to octal or identity.
                        if ($next >= '0' && $next <= '7') {
                            $octalStr = '';
                            $oj = $i + 1;
                            while (
                                $oj < $len
                                && $pattern[$oj] >= '0'
                                && $pattern[$oj] <= '7'
                                && strlen($octalStr) < 3
                            ) {
                                $octalStr .= $pattern[$oj];
                                $oj++;
                            }
                            $cp = octdec($octalStr);
                            $result .= '\\x{' . strtoupper(dechex($cp)) . '}';
                            $i = $oj;
                        } else {
                            // \8 or \9: identity escape for the digit.
                            $result .= $next;
                            $i += 2;
                        }
                        continue;
                    }
                    // Valid backreference: pass through as-is.
                    $result .= $ch . $numStr;
                    $i = $j;
                    continue;
                }
                // Other escape: in ECMAScript non-unicode mode, any \X where
                // X is not a meaningful escape character is an identity escape
                // matching the literal character X. PCRE may interpret some of
                // these differently (e.g. \a = BEL, \e = ESC) or error on them
                // (e.g. \F, \I, \J). Convert to PCRE-safe form.
                // Escapes that PCRE handles the same as ECMAScript identity:
                $ecmaMeaningful = 'dDwWbBnrtfv0';
                // Regex syntax characters that should stay escaped:
                $syntaxChars = '\\^$.|*+?()[]{}/-';
                if (strpos($ecmaMeaningful, $next) !== false) {
                    // Meaningful ECMAScript escape that PCRE also handles.
                    $result .= $ch . $next;
                } elseif (strpos($syntaxChars, $next) !== false) {
                    // Syntax character: keep escaped for PCRE.
                    $result .= $ch . $next;
                } else {
                    // Identity escape: convert to PCRE-safe literal.
                    $ord = ord($next);
                    if ($ord >= 0x20 && $ord <= 0x7E) {
                        // Printable ASCII: use \x{XX} to avoid PCRE misinterpretation.
                        $result .= '\\x{' . strtoupper(dechex($ord)) . '}';
                    } elseif ($ord < 0x80) {
                        // Non-printable ASCII: use \x{XX}.
                        $result .= '\\x{' . strtoupper(dechex($ord)) . '}';
                    } else {
                        // Multi-byte UTF-8 start: consume the full character and
                        // emit it as a \x{XXXX} code point.
                        $mbChar = $next;
                        $j = $i + 2;
                        while ($j < $len && (ord($pattern[$j]) & 0xC0) === 0x80) {
                            $mbChar .= $pattern[$j];
                            $j++;
                        }
                        $cp = mb_ord($mbChar, 'UTF-8');
                        if ($cp !== false) {
                            $result .= '\\x{' . strtoupper(dechex($cp)) . '}';
                        } else {
                            $result .= $mbChar;
                        }
                        $i = $j;
                        continue;
                    }
                }
                $i += 2;
                continue;
            }

            if ($ch === '[' && !$inCharClass) {
                // ECMAScript allows [] (empty class, matches nothing) and
                // [^] (complement of empty class, matches anything).
                // PCRE does not support these. Convert them to equivalents.
                if ($i + 1 < $len && $pattern[$i + 1] === ']') {
                    // [] -> (?![\s\S]) which is a never-matching pattern.
                    // Use PCRE's (*FAIL) or a simpler approach: [^\s\S]
                    $result .= '[^\\s\\S]';
                    $i += 2;
                    continue;
                }
                if ($i + 2 < $len && $pattern[$i + 1] === '^' && $pattern[$i + 2] === ']') {
                    // [^] -> [\s\S] which matches any character including newline.
                    $result .= '[\\s\\S]';
                    $i += 3;
                    continue;
                }
                $inCharClass = true;
                $result .= $ch;
                $i++;
                // Handle negated class [^
                if ($i < $len && $pattern[$i] === '^') {
                    $result .= '^';
                    $i++;
                }
                // Handle ] as first char in class (PCRE treats ] after [ or [^ as literal)
                if ($i < $len && $pattern[$i] === ']') {
                    $result .= ']';
                    $i++;
                }
                // PCRE interprets [. [= [: after [ as POSIX bracket expressions.
                // ECMAScript does not have POSIX bracket expressions.
                // Escape . = : when they appear as the first char in a class
                // to prevent PCRE from misinterpreting them.
                if ($i < $len && ($pattern[$i] === '.' || $pattern[$i] === '=' || $pattern[$i] === ':')) {
                    $result .= '\\' . $pattern[$i];
                    $i++;
                }
                continue;
            }

            if ($ch === ']' && $inCharClass) {
                $inCharClass = false;
                $result .= $ch;
                $i++;
                continue;
            }

            // Inside a character class, PCRE interprets [. [= and [: as POSIX
            // collating element / equivalence class / named class openers.
            // ECMAScript does not have POSIX bracket expressions; [ inside a
            // character class is just a literal. Escape it to prevent PCRE errors.
            if ($ch === '[' && $inCharClass) {
                $result .= '\\[';
                $i++;
                continue;
            }

            $result .= $ch;
            $i++;
        }

        return $result;
    }

    /**
     * Escape unescaped forward slashes for use with the PCRE / delimiter.
     * Slashes already preceded by an odd number of backslashes are left as-is.
     */
    private function escapeForPcreDelimiter(string $pattern): string
    {
        $result = '';
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            if ($pattern[$i] === '/') {
                // Count preceding backslashes.
                $bs = 0;
                for ($j = $i - 1; $j >= 0 && $pattern[$j] === '\\'; $j--) {
                    $bs++;
                }
                // Even number of backslashes means the slash is unescaped.
                if ($bs % 2 === 0) {
                    $result .= '\\/';
                } else {
                    $result .= '/';
                }
            } else {
                $result .= $pattern[$i];
            }
        }
        return $result;
    }

    /**
     * Validate a pattern for Unicode mode restrictions per spec B.1.4.
     *
     * In /u mode, the Annex B extensions are not applied:
     * - Octal escape sequences (\1-\9, \00-\09, etc.) are forbidden
     *   unless they are valid backreferences.
     * - Identity escapes are restricted to SyntaxCharacter and /
     * - \c must be followed by a letter (A-Z, a-z)
     */
    private function validateUnicodePattern(string $pattern): void
    {
        $len = strlen($pattern);
        // Count capturing groups to know which \N are valid backreferences.
        $groupCount = $this->countCapturingGroups($pattern);

        for ($i = 0; $i < $len; $i++) {
            if ($pattern[$i] !== '\\') {
                // Skip character class contents for bracket tracking.
                if ($pattern[$i] === '[') {
                    $i++;
                    while ($i < $len && $pattern[$i] !== ']') {
                        if ($pattern[$i] === '\\' && $i + 1 < $len) {
                            // Validate escapes inside character classes too.
                            $next = $pattern[$i + 1];
                            if ($next >= '0' && $next <= '9') {
                                $this->validateUnicodeDecimalEscape($pattern, $i + 1, $len, 0, true);
                            } elseif ($next === 'c') {
                                $this->validateUnicodeControlEscape($pattern, $i + 1, $len);
                            }
                            $i++; // skip the escaped char
                        }
                        $i++;
                    }
                }
                continue;
            }
            // We have a backslash at position $i.
            if ($i + 1 >= $len) {
                // Trailing backslash: PCRE will catch this.
                break;
            }
            $next = $pattern[$i + 1];

            if ($next >= '1' && $next <= '9') {
                // DecimalEscape: \1-\9 etc. In /u mode, these must be valid
                // backreferences. Collect the full decimal number.
                $numStr = '';
                $j = $i + 1;
                while ($j < $len && $pattern[$j] >= '0' && $pattern[$j] <= '9') {
                    $numStr .= $pattern[$j];
                    $j++;
                }
                $num = (int) $numStr;
                if ($num > $groupCount) {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        "Invalid regular expression: /\\{$numStr}/ is not a valid backreference in unicode mode",
                    );
                }
                $i = $j - 1;
            } elseif ($next === '0') {
                // \0 followed by another digit is an octal escape, forbidden in /u mode.
                if ($i + 2 < $len && $pattern[$i + 2] >= '0' && $pattern[$i + 2] <= '9') {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        'Invalid regular expression: octal escape sequences are not allowed in unicode mode',
                    );
                }
                $i++; // skip past \0 (NUL escape is OK)
            } elseif ($next === 'c') {
                $this->validateUnicodeControlEscape($pattern, $i + 1, $len);
                $i += 2; // skip \cX
            } else {
                $i++; // skip the backslash and the next character
            }
        }
    }

    /**
     * Validate a decimal escape sequence starting at $pos in /u mode.
     * In character classes, any decimal escape (except \0 not followed by a digit) is forbidden.
     */
    private function validateUnicodeDecimalEscape(
        string $pattern,
        int $pos,
        int $len,
        int $groupCount,
        bool $inClass,
    ): void {
        $next = $pattern[$pos];
        if ($inClass) {
            // In character classes in /u mode, \0 is OK only if not followed by another digit.
            if ($next === '0') {
                if ($pos + 1 < $len && $pattern[$pos + 1] >= '0' && $pattern[$pos + 1] <= '9') {
                    throw new \PhpJs\Exceptions\SyntaxError(
                        'Invalid regular expression: octal escape sequences are not allowed in unicode mode',
                    );
                }
                return; // \0 NUL is fine
            }
            // \1-\9 inside character class in /u mode: always invalid.
            throw new \PhpJs\Exceptions\SyntaxError(
                'Invalid regular expression: decimal escape sequences are not allowed'
                . ' in unicode mode character classes',
            );
        }
    }

    /**
     * Validate \c escape in /u mode: must be followed by a letter.
     */
    private function validateUnicodeControlEscape(string $pattern, int $cPos, int $len): void
    {
        // $cPos points to 'c' in the pattern. Next char must be a letter.
        if ($cPos + 1 >= $len) {
            throw new \PhpJs\Exceptions\SyntaxError(
                'Invalid regular expression: \\c at end of pattern in unicode mode',
            );
        }
        $controlChar = $pattern[$cPos + 1];
        if (!(($controlChar >= 'A' && $controlChar <= 'Z') || ($controlChar >= 'a' && $controlChar <= 'z'))) {
            throw new \PhpJs\Exceptions\SyntaxError(
                'Invalid regular expression: \\c must be followed by a letter in unicode mode',
            );
        }
    }

    /**
     * Count the number of capturing groups in a regex pattern.
     * This counts '(' that are not followed by '?' (which would indicate
     * a non-capturing group, lookahead, etc.).
     */
    private function countCapturingGroups(string $pattern): int
    {
        $count = 0;
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            if ($pattern[$i] === '\\') {
                $i++; // skip escaped char
                continue;
            }
            if ($pattern[$i] === '[') {
                // Skip character class.
                $i++;
                while ($i < $len && $pattern[$i] !== ']') {
                    if ($pattern[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                continue;
            }
            if ($pattern[$i] === '(' && $i + 1 < $len) {
                if ($pattern[$i + 1] !== '?') {
                    $count++;
                } elseif (
                    $i + 2 < $len && $pattern[$i + 2] === '<'
                    && $i + 3 < $len && $pattern[$i + 3] !== '=' && $pattern[$i + 3] !== '!'
                ) {
                    // Named capturing group (?<name>...)
                    $count++;
                }
            }
        }
        return $count;
    }

    /** Lazily created global object used as the default this in sloppy mode. */
    private ?JsObject $globalObject = null;

    public function getGlobalObject(): JsObject
    {
        if ($this->globalObject === null) {
            // Use the same global object that Engine installed as 'this'.
            if ($this->globalEnv->has('this')) {
                $obj = $this->globalEnv->get('this');
                if ($obj instanceof JsObject) {
                    $this->globalObject = $obj;
                    return $this->globalObject;
                }
            }
            $this->globalObject = new JsObject();
        }
        return $this->globalObject;
    }

    /**
     * Parse a BigInt literal string (any base) to canonical decimal string.
     * Replaces gmp_init($value, 0) + gmp_strval() since GMP may not be installed.
     */
    private static function parseBigIntLiteral(string $value): string
    {
        $negative = '';
        if ($value !== '' && $value[0] === '-') {
            $negative = '-';
            $value = substr($value, 1);
        }
        if (strlen($value) > 2 && $value[0] === '0') {
            $prefix = $value[1];
            $digits = substr($value, 2);
            if ($prefix === 'x' || $prefix === 'X') {
                $decimal = self::baseStringToDecimal($digits, 16);
                return $negative . $decimal;
            }
            if ($prefix === 'b' || $prefix === 'B') {
                $decimal = self::baseStringToDecimal($digits, 2);
                return $negative . $decimal;
            }
            if ($prefix === 'o' || $prefix === 'O') {
                $decimal = self::baseStringToDecimal($digits, 8);
                return $negative . $decimal;
            }
        }
        // Decimal: strip leading zeros.
        $trimmed = ltrim($value, '0');
        return $negative . ($trimmed !== '' ? $trimmed : '0');
    }

    /**
     * Convert a string of digits in the given base (2, 8, or 16) to a decimal string.
     * Uses PHP native integers; for values exceeding PHP_INT_MAX uses string long multiplication.
     */
    private static function baseStringToDecimal(string $digits, int $base): string
    {
        // For small values, PHP native int is sufficient and fast.
        // For large values, we use a pure-PHP string big-integer multiply-add.
        $result = '0'; // Big-integer decimal string.
        foreach (str_split(strtolower($digits)) as $char) {
            $d = $char >= 'a' ? (ord($char) - ord('a') + 10) : (int) $char;
            // result = result * base + d  (pure-PHP string arithmetic)
            $result = self::bigStrAdd(self::bigStrMul($result, (string) $base), (string) $d);
        }
        return $result;
    }

    /** Pure-PHP string addition of two non-negative decimal integer strings. */
    private static function bigStrAdd(string $a, string $b): string
    {
        $result = '';
        $carry = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0 || $j >= 0 || $carry) {
            $sum = $carry;
            if ($i >= 0) {
                $sum += (int) $a[$i--];
            }
            if ($j >= 0) {
                $sum += (int) $b[$j--];
            }
            $carry = intdiv($sum, 10);
            $result = ($sum % 10) . $result;
        }
        return $result !== '' ? $result : '0';
    }

    /** Pure-PHP string multiplication of a non-negative decimal integer string by a small int. */
    private static function bigStrMul(string $a, string $b): string
    {
        if ($a === '0' || $b === '0') {
            return '0';
        }
        // Schoolbook multiplication for single-digit multiplier (base 2/8/16).
        $bInt = (int) $b;
        $result = '';
        $carry = 0;
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            $prod = (int) $a[$i] * $bInt + $carry;
            $carry = intdiv($prod, 10);
            $result = ($prod % 10) . $result;
        }
        while ($carry > 0) {
            $result = ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }
        return $result !== '' ? $result : '0';
    }

    /** Compare two non-negative decimal integer strings. Returns -1, 0, 1. */
    private static function bigStrCompUnsigned(string $a, string $b): int
    {
        $la = strlen($a);
        $lb = strlen($b);
        if ($la !== $lb) {
            return $la < $lb ? -1 : 1;
        }
        return strcmp($a, $b) <=> 0;
    }

    /** Signed comparison of two decimal integer strings. Returns -1, 0, 1. */
    private static function bigStrComp(string $a, string $b): int
    {
        $aNeg = isset($a[0]) && $a[0] === '-';
        $bNeg = isset($b[0]) && $b[0] === '-';
        if ($aNeg !== $bNeg) {
            return $aNeg ? -1 : 1;
        }
        $cmp = self::bigStrCompUnsigned(ltrim($a, '-'), ltrim($b, '-'));
        return $aNeg ? -$cmp : $cmp;
    }

    /** Signed addition of two decimal integer strings. */
    private static function bigStrAddSigned(string $a, string $b): string
    {
        // Fast path: both fit in native PHP int.
        if (abs((float) $a) < 9.2e18 && abs((float) $b) < 9.2e18 && strlen($a) <= 18 && strlen($b) <= 18) {
            $ia = (int) $a;
            $ib = (int) $b;
            if ((string) $ia === ltrim($a, '+') && (string) $ib === ltrim($b, '+')) {
                return (string) ($ia + $ib);
            }
        }
        $aNeg = isset($a[0]) && $a[0] === '-';
        $bNeg = isset($b[0]) && $b[0] === '-';
        $absA = ltrim($a, '-');
        $absB = ltrim($b, '-');
        if ($aNeg === $bNeg) {
            $sum = self::bigStrAdd($absA, $absB);
            return $aNeg ? ('-' . $sum) : $sum;
        }
        $cmp = self::bigStrCompUnsigned($absA, $absB);
        if ($cmp === 0) {
            return '0';
        }
        if ($cmp > 0) {
            // |a| > |b|, result sign = sign of a
            $diff = self::bigStrSubUnsigned($absA, $absB);
            return ($aNeg && $diff !== '0') ? ('-' . $diff) : $diff;
        }
        // |a| < |b|, result sign = sign of b
        $diff = self::bigStrSubUnsigned($absB, $absA);
        return ($bNeg && $diff !== '0') ? ('-' . $diff) : $diff;
    }

    /** Subtract b from a where a >= b (both non-negative). */
    private static function bigStrSubUnsigned(string $a, string $b): string
    {
        $result = '';
        $borrow = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0) {
            $diff = (int) $a[$i--] - ($j >= 0 ? (int) $b[$j--] : 0) - $borrow;
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = $diff . $result;
        }
        return ltrim($result, '0') ?: '0';
    }

    /**
     * Full unsigned long division. Returns [quotient, remainder] as decimal strings.
     * Uses digit-by-digit algorithm: at each step remainder < b, so q is 0-9.
     */
    private static function bigStrDivModFull(string $a, string $b): array
    {
        if ($b === '0') {
            throw new \PhpJs\Exceptions\RangeError('Division by zero');
        }
        if (self::bigStrCompUnsigned($a, $b) < 0) {
            return ['0', $a];
        }
        if ($b === '1') {
            return [$a, '0'];
        }

        // Fast path: b fits in native PHP int.
        $bInt = (int) $b;
        if ((string) $bInt === $b && $bInt > 0) {
            $q = '';
            $rem = 0;
            for ($i = 0; $i < strlen($a); $i++) {
                $cur = $rem * 10 + (int) $a[$i];
                $q .= (string) intdiv($cur, $bInt);
                $rem = $cur % $bInt;
            }
            return [ltrim($q, '0') ?: '0', (string) $rem];
        }

        // General long division: digit-by-digit.
        // Invariant: $rem < $b at all times.
        $quotient = '';
        $rem = '0';
        for ($i = 0; $i < strlen($a); $i++) {
            // current = rem * 10 + digit
            $rem = self::bigStrAdd(self::bigStrMul($rem, '10'), (string) (int) $a[$i]);
            // Find q in 0..9 such that q*b <= rem < (q+1)*b
            $q = 0;
            for ($try = 9; $try >= 1; $try--) {
                $prod = self::bigStrMul($b, (string) $try);
                if (self::bigStrCompUnsigned($prod, $rem) <= 0) {
                    $q = $try;
                    break;
                }
            }
            $quotient .= (string) $q;
            if ($q > 0) {
                $rem = self::bigStrSubUnsigned($rem, self::bigStrMul($b, (string) $q));
            }
        }
        return [ltrim($quotient, '0') ?: '0', $rem];
    }

    /** Signed BigInt add (replaces bcadd). */
    private static function bigStrBcAdd(string $a, string $b): string
    {
        return self::bigStrAddSigned($a, $b);
    }

    /** Signed BigInt subtract (replaces bcsub). */
    private static function bigStrBcSub(string $a, string $b): string
    {
        // a - b = a + (-b)
        if ($b !== '0' && $b !== '') {
            $bNeg = $b[0] === '-';
            $negB = $bNeg ? substr($b, 1) : ('-' . $b);
        } else {
            $negB = '0';
        }
        return self::bigStrAddSigned($a, $negB);
    }

    /** Signed BigInt multiply (replaces bcmul). */
    private static function bigStrBcMul(string $a, string $b): string
    {
        $aNeg = $a !== '0' && isset($a[0]) && $a[0] === '-';
        $bNeg = $b !== '0' && isset($b[0]) && $b[0] === '-';
        $absA = ltrim($a, '-');
        $absB = ltrim($b, '-');

        // Use full schoolbook multiplication for both operands.
        // bigStrMul only handles small second operand; bigStrMulUnsigned handles full.
        if (strlen($absB) === 1) {
            $prod = self::bigStrMul($absA, $absB);
        } else {
            // Schoolbook.
            $m = strlen($absA);
            $n = strlen($absB);
            $result = array_fill(0, $m + $n, 0);
            for ($i = $m - 1; $i >= 0; $i--) {
                for ($j = $n - 1; $j >= 0; $j--) {
                    $mul = (int) $absA[$i] * (int) $absB[$j];
                    $p1 = $i + $j;
                    $p2 = $i + $j + 1;
                    $sum = $mul + $result[$p2];
                    $result[$p2] = $sum % 10;
                    $result[$p1] += intdiv($sum, 10);
                }
            }
            $prod = ltrim(implode('', $result), '0') ?: '0';
        }
        if ($prod === '0') {
            return '0';
        }
        return ($aNeg xor $bNeg) ? ('-' . $prod) : $prod;
    }

    /**
     * Signed BigInt divide, truncating toward zero (replaces bcdiv($a, $b, 0)).
     * Returns quotient as decimal string.
     */
    private static function bigStrBcDiv(string $a, string $b): string
    {
        if ($b === '0') {
            throw new \PhpJs\Exceptions\RangeError('Division by zero');
        }
        $aNeg = isset($a[0]) && $a[0] === '-';
        $bNeg = isset($b[0]) && $b[0] === '-';
        $absA = ltrim($a, '-');
        $absB = ltrim($b, '-');
        [$q,] = self::bigStrDivModFull($absA, $absB);
        if ($q === '0') {
            return '0';
        }
        return ($aNeg xor $bNeg) ? ('-' . $q) : $q;
    }

    /**
     * Signed BigInt remainder (replaces bcmod).
     * Result has same sign as dividend.
     */
    private static function bigStrBcMod(string $a, string $b): string
    {
        if ($b === '0') {
            throw new \PhpJs\Exceptions\RangeError('Division by zero');
        }
        $aNeg = isset($a[0]) && $a[0] === '-';
        $absA = ltrim($a, '-');
        $absB = ltrim($b, '-');
        [,$r] = self::bigStrDivModFull($absA, $absB);
        if ($r === '0') {
            return '0';
        }
        return $aNeg ? ('-' . $r) : $r;
    }

    /**
     * BigInt exponentiation (replaces bcpow($a, $b, 0)).
     * $b must be non-negative.
     */
    private static function bigStrBcPow(string $base, string $exp): string
    {
        if ($exp === '0') {
            return '1';
        }
        if ($exp === '1') {
            return $base;
        }
        if ($base === '0') {
            return '0';
        }
        if ($base === '1') {
            return '1';
        }
        // Fast path: exp fits in native int.
        $expInt = (int) $exp;
        if ((string) $expInt === $exp) {
            // Exponentiation by squaring.
            $result = '1';
            $b = $base;
            $e = $expInt;
            while ($e > 0) {
                if ($e % 2 === 1) {
                    $result = self::bigStrBcMul($result, $b);
                }
                $b = self::bigStrBcMul($b, $b);
                $e = intdiv($e, 2);
            }
            return $result;
        }
        // Very large exponent: impractical, throw range error.
        throw new \PhpJs\Exceptions\RangeError('BigInt exponent too large');
    }

    /**
     * BigInt bitwise AND/OR/XOR without GMP or bcmath.
     * Uses native PHP int for values that fit, binary-string two's-complement for large values.
     */
    private function bigintBitwiseOp(JsBigInt $left, JsBigInt $right, string $op): JsBigInt
    {
        // Fast path: both values fit in a native PHP int.
        if ($this->bigStrFitsInt($left->value) && $this->bigStrFitsInt($right->value)) {
            $l = (int) $left->value;
            $r = (int) $right->value;
            $result = match ($op) {
                '&' => $l & $r,
                '|' => $l | $r,
                '^' => $l ^ $r,
            };
            return new JsBigInt((string) $result);
        }

        // Large values: two's-complement binary string manipulation.
        // Width: max bit-length + 1 guard bit for sign extension.
        $lBin = $this->bigintToTwosCompBin($left->value);
        $rBin = $this->bigintToTwosCompBin($right->value);
        $len = max(strlen($lBin), strlen($rBin)) + 1;
        $lSign = $left->value[0] === '-' ? '1' : '0';
        $rSign = $right->value[0] === '-' ? '1' : '0';
        $lBin = str_pad($lBin, $len, $lSign, STR_PAD_LEFT);
        $rBin = str_pad($rBin, $len, $rSign, STR_PAD_LEFT);

        $resultBin = '';
        for ($i = 0; $i < $len; $i++) {
            $lb = (int) $lBin[$i];
            $rb = (int) $rBin[$i];
            $resultBin .= (string) match ($op) {
                '&' => $lb & $rb,
                '|' => $lb | $rb,
                '^' => $lb ^ $rb,
            };
        }
        return new JsBigInt($this->twosCompBinToDecimal($resultBin));
    }

    /** Check whether a BigInt decimal string fits in PHP's native int. */
    private function bigStrFitsInt(string $value): bool
    {
        $abs = ltrim($value, '-');
        $max = (string) PHP_INT_MAX;
        if (strlen($abs) < strlen($max)) {
            return true;
        }
        if (strlen($abs) > strlen($max)) {
            return false;
        }
        // Same digit length: compare lexicographically.
        return $abs <= $max;
    }

    /** Convert a decimal BigInt string to a two's-complement binary string. Pure PHP. */
    private function bigintToTwosCompBin(string $value): string
    {
        $negative = $value[0] === '-';
        $abs = $negative ? substr($value, 1) : $value;
        if ($abs === '' || $abs === '0') {
            return '0';
        }
        // Convert |value| to binary via repeated halving.
        $bin = '';
        $v = $abs;
        while ($v !== '0') {
            [$q, $r] = $this->bigStrDivMod($v, '2');
            $bin = $r . $bin;
            $v = $q;
        }
        if (!$negative) {
            return $bin;
        }
        // Negative: two's complement = NOT(|value| - 1).
        // Subtract 1 from $abs, then invert bits.
        $abs1 = $this->bigStrSub($abs, '1');
        if ($abs1 === '0') {
            // -1 in two's complement is all 1s; single '1' is fine (sign-extended).
            return '1';
        }
        $bin2 = '';
        $v = $abs1;
        while ($v !== '0') {
            [$q, $r] = $this->bigStrDivMod($v, '2');
            $bin2 = $r . $bin2;
            $v = $q;
        }
        return strtr($bin2, ['0' => '1', '1' => '0']);
    }

    /** Convert a two's-complement binary string back to a decimal BigInt string. Pure PHP. */
    private function twosCompBinToDecimal(string $bin): string
    {
        if ($bin === '' || $bin[0] === '0') {
            // Positive or zero.
            $result = '0';
            foreach (str_split($bin) as $bit) {
                $result = self::bigStrAdd(self::bigStrMul($result, '2'), $bit);
            }
            return $result;
        }
        // Negative (sign bit = 1): invert bits and add 1 to get magnitude.
        $inverted = strtr($bin, ['0' => '1', '1' => '0']);
        $mag = '0';
        foreach (str_split($inverted) as $bit) {
            $mag = self::bigStrAdd(self::bigStrMul($mag, '2'), $bit);
        }
        return '-' . self::bigStrAdd($mag, '1');
    }

    /**
     * Divide two non-negative decimal integer strings.
     * Returns [$quotient, $remainder] as strings.
     */
    private function bigStrDivMod(string $a, string $divisor): array
    {
        $d = (int) $divisor;
        $q = '';
        $rem = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $cur = $rem * 10 + (int) $a[$i];
            $q .= (string) intdiv($cur, $d);
            $rem = $cur % $d;
        }
        $q = ltrim($q, '0') ?: '0';
        return [$q, (string) $rem];
    }

    /** Pure-PHP string subtraction of non-negative decimal integer strings (a >= b). */
    private function bigStrSub(string $a, string $b): string
    {
        $result = '';
        $borrow = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        while ($i >= 0) {
            $diff = (int) $a[$i--] - ($j >= 0 ? (int) $b[$j--] : 0) - $borrow;
            if ($diff < 0) {
                $diff += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = $diff . $result;
        }
        return ltrim($result, '0') ?: '0';
    }
}
