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
use PhpJs\Value\JsBoolean;
use PhpJs\Value\GeneratorThrowSignal;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsGenerator;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsSymbol;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class Interpreter
{
    private CallStack $callStack;
    private int $maxLoopIterations;
    private bool $strictMode = false;

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
                return true;
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

        $this->hoistDeclarations($program->body, $this->globalEnv);
        return $this->executeStatements($program->body, $this->globalEnv);
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
            $result = $completion->value;
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
                return $completion;
            }
            $result = $completion->value;
        }
        return Completion::normal($result);
    }

    private function executeStatement(Node $node, Environment $env): Completion
    {
        return match (true) {
            $node instanceof ExpressionStatement => $this->execExpressionStatement($node, $env),
            $node instanceof VariableDeclaration => $this->execVariableDeclaration($node, $env),
            $node instanceof FunctionDeclaration => Completion::normal(JsUndefined::instance()),
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
            $node instanceof EmptyStatement,
            $node instanceof DebuggerStatement => Completion::normal(JsUndefined::instance()),
            default => throw new InternalError('Unknown statement type: ' . $node->type()),
        };
    }

    // -------------------------------------------------------------------------
    // Expression evaluation
    // -------------------------------------------------------------------------

    public function evaluate(Node $node, Environment $env): JsValue
    {
        return match (true) {
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
            $node instanceof AwaitExpression => JsUndefined::instance(),
            default => throw new InternalError('Unknown expression type: ' . $node->type()),
        };
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
            '+' => AbstractOperations::add($left, $right),
            '-' => new JsNumber(TypeConversion::toNumber($left) - TypeConversion::toNumber($right)),
            '*' => new JsNumber(TypeConversion::toNumber($left) * TypeConversion::toNumber($right)),
            '/' => $this->divide(TypeConversion::toNumber($left), TypeConversion::toNumber($right)),
            '%' => $this->modulo(TypeConversion::toNumber($left), TypeConversion::toNumber($right)),
            '**' => $this->exponentiate(
                TypeConversion::toNumber($left),
                TypeConversion::toNumber($right),
            ),
            '==' => new JsBoolean(AbstractOperations::abstractEquals($left, $right)),
            '!=' => new JsBoolean(!AbstractOperations::abstractEquals($left, $right)),
            '===' => new JsBoolean(AbstractOperations::strictEquals($left, $right)),
            '!==' => new JsBoolean(!AbstractOperations::strictEquals($left, $right)),
            '<' => $this->relational($left, $right, '<'),
            '>' => $this->relational($right, $left, '>'),
            '<=' => $this->relational($right, $left, '<='),
            '>=' => $this->relational($left, $right, '>='),
            '<<' => new JsNumber(
                (float) (TypeConversion::toInt32($left) << (TypeConversion::toUint32($right) & 0x1F)),
            ),
            '>>' => new JsNumber(
                (float) (TypeConversion::toInt32($left) >> (TypeConversion::toUint32($right) & 0x1F)),
            ),
            '>>>' => new JsNumber(
                (float) ($this->unsignedRightShift(
                    TypeConversion::toInt32($left),
                    TypeConversion::toUint32($right) & 0x1F,
                )),
            ),
            '&' => new JsNumber((float) (TypeConversion::toInt32($left) & TypeConversion::toInt32($right))),
            '|' => new JsNumber((float) (TypeConversion::toInt32($left) | TypeConversion::toInt32($right))),
            '^' => new JsNumber((float) (TypeConversion::toInt32($left) ^ TypeConversion::toInt32($right))),
            'in' => $this->evalInOperator($left, $right),
            'instanceof' => new JsBoolean(AbstractOperations::instanceofOperator($left, $right)),
            default => throw new InternalError("Unknown binary operator: {$node->operator}"),
        };
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

    private function exponentiate(float $base, float $exp): JsNumber
    {
        return new JsNumber($base ** $exp);
    }

    private function relational(JsValue $x, JsValue $y, string $op): JsValue
    {
        $result = AbstractOperations::abstractRelational($x, $y, $op === '<' || $op === '>=');
        if ($result === null) {
            return new JsBoolean($op === '<=' || $op === '>=');
        }
        if ($op === '<=' || $op === '>=') {
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

    private function evalInOperator(JsValue $left, JsValue $right): JsValue
    {
        if (!$right instanceof JsObject) {
            throw new TypeError(
                'Cannot use "in" operator to search for "'
                . TypeConversion::toString($left) . '" in ' . TypeConversion::toString($right)
            );
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

        return match ($node->operator) {
            '!' => new JsBoolean(!TypeConversion::toBoolean($value)),
            '-' => new JsNumber(-TypeConversion::toNumber($value)),
            '+' => new JsNumber(TypeConversion::toNumber($value)),
            '~' => new JsNumber((float) (~TypeConversion::toInt32($value))),
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
            if ($obj instanceof JsObject) {
                $key = $argument->computed
                    ? TypeConversion::toString($this->evaluate($argument->property, $env))
                    : ($argument->property instanceof Identifier ? $argument->property->name : '');
                return new JsBoolean($obj->delete($key));
            }
        }
        return new JsBoolean(true);
    }

    private function evalUpdateExpression(UpdateExpression $node, Environment $env): JsValue
    {
        $ref = $this->resolveReference($node->argument, $env);
        $oldValue = new JsNumber(TypeConversion::toNumber($ref->getValue()));
        $delta = $node->operator === '++' ? 1.0 : -1.0;
        $newValue = new JsNumber($oldValue->value + $delta);
        $ref->setValue($newValue);

        return $node->prefix ? $newValue : $oldValue;
    }

    private function evalAssignment(AssignmentExpression $node, Environment $env): JsValue
    {
        if ($node->operator === '=' && $this->isDestructuringTarget($node->left)) {
            $value = $this->evaluate($node->right, $env);
            $this->destructureAssign($node->left, $value, $env);
            return $value;
        }

        $ref = $this->resolveReference($node->left, $env);
        $right = $this->evaluate($node->right, $env);

        if ($node->operator === '=') {
            $ref->setValue($right);
            return $right;
        }

        $leftVal = $ref->getValue();
        $result = match ($node->operator) {
            '+=' => AbstractOperations::add($leftVal, $right),
            '-=' => new JsNumber(TypeConversion::toNumber($leftVal) - TypeConversion::toNumber($right)),
            '*=' => new JsNumber(TypeConversion::toNumber($leftVal) * TypeConversion::toNumber($right)),
            '/=' => $this->divide(TypeConversion::toNumber($leftVal), TypeConversion::toNumber($right)),
            '%=' => $this->modulo(TypeConversion::toNumber($leftVal), TypeConversion::toNumber($right)),
            '**=' => $this->exponentiate(
                TypeConversion::toNumber($leftVal),
                TypeConversion::toNumber($right),
            ),
            '<<=' => new JsNumber(
                (float) (TypeConversion::toInt32($leftVal) << (TypeConversion::toUint32($right) & 0x1F)),
            ),
            '>>=' => new JsNumber(
                (float) (TypeConversion::toInt32($leftVal) >> (TypeConversion::toUint32($right) & 0x1F)),
            ),
            '>>>=' => new JsNumber((float) $this->unsignedRightShift(
                TypeConversion::toInt32($leftVal),
                TypeConversion::toUint32($right) & 0x1F,
            )),
            '&=' => new JsNumber(
                (float) (TypeConversion::toInt32($leftVal) & TypeConversion::toInt32($right)),
            ),
            '|=' => new JsNumber(
                (float) (TypeConversion::toInt32($leftVal) | TypeConversion::toInt32($right)),
            ),
            '^=' => new JsNumber(
                (float) (TypeConversion::toInt32($leftVal) ^ TypeConversion::toInt32($right)),
            ),
            '&&=' => TypeConversion::toBoolean($leftVal) ? $right : $leftVal,
            '||=' => TypeConversion::toBoolean($leftVal) ? $leftVal : $right,
            '??=' => ($leftVal instanceof JsNull || $leftVal instanceof JsUndefined) ? $right : $leftVal,
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

            $obj = $rawObj instanceof JsObject ? $rawObj : TypeConversion::toObject($rawObj);
            $callee = $isSymbolCallKey ? $obj->getBySymbol($rawCallKey) : $obj->get($key);
            $thisValue = $obj;
            $isMethodCall = true;
        } else {
            $callee = $this->evaluate($node->callee, $env);
        }

        if (!$callee instanceof JsFunction) {
            $desc = TypeConversion::toString($callee);
            throw new TypeError("{$desc} is not a function");
        }

        // In sloppy mode, unbound function calls receive the global object as this.
        // In strict mode, this remains undefined.
        if (!$isMethodCall && !$this->strictMode && $callee->getBoundThis() === null) {
            $thisValue = $this->getGlobalObject();
        }

        $args = $this->evaluateArguments($node->arguments, $env);
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

        $parser = new \PhpJs\Parser\Parser($arg->value);
        $program = $parser->parse();

        // Hoist var declarations and function declarations into the current scope.
        $this->hoistDeclarations($program->body, $env);

        // Execute the parsed program body in the current scope.
        $completion = $this->executeBody($program->body, $env);

        if ($completion->type === CompletionType::Throw) {
            $this->throwJsValue($completion->value);
        }

        return $completion->value;
    }

    private function evalNewExpression(NewExpression $node, Environment $env): JsValue
    {
        $callee = $this->evaluate($node->callee, $env);

        if (!$callee instanceof JsFunction) {
            throw new TypeError(TypeConversion::toString($callee) . ' is not a constructor');
        }

        $args = $this->evaluateArguments($node->arguments, $env);

        // Create a new object with the constructor's prototype
        $proto = $callee->get('prototype');
        $newObj = new JsObject($proto instanceof JsObject ? $proto : null);

        $result = $this->callFunction($callee, $newObj, $args);

        // If constructor returned an object, use that; otherwise return newObj
        return $result instanceof JsObject ? $result : $newObj;
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
        }
    }

    /** @param JsValue[] $args */
    public function callFunction(
        JsFunction $fn,
        JsValue $thisValue,
        array $args,
    ): JsValue {
        // Native (PHP callable) function
        $nativeFn = $fn->getNativeCallable();
        if ($nativeFn !== null) {
            return $nativeFn($thisValue, $args, $this);
        }

        // Generator function: return a JsGenerator instead of executing.
        if ($fn->isGenerator()) {
            return $this->createGenerator($fn, $thisValue, $args);
        }

        // Interpreted function
        return $this->executeFunction($fn, $thisValue, $args);
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

            // Detect function-level "use strict" directive.
            $body = $fn->getBody();
            if ($body instanceof BlockStatement && $this->hasUseStrictDirective($body->body)) {
                $this->strictMode = true;
            }

            // In strict mode, if this is an unbound non-arrow function call
            // with the global object as this, replace with undefined.
            if (
                $this->strictMode
                && !$fn->isArrow()
                && $fn->getBoundThis() === null
                && $thisValue instanceof JsObject
                && $thisValue === $this->getGlobalObject()
            ) {
                $thisValue = JsUndefined::instance();
            }

            // Bind this
            if ($fn->isArrow()) {
                // Arrow functions inherit this from closure
            } else {
                $fnEnv->defineVar('this', $thisValue);
            }

            // Bind parameters
            $this->bindParameters($fn->getParams(), $args, $fnEnv);

            // Bind arguments object (non-arrow only)
            if (!$fn->isArrow()) {
                $argsObj = JsArray::fromArray($args);
                // In sloppy mode, arguments.callee references the current function.
                if (!$this->strictMode) {
                    $argsObj->set('callee', $fn);
                }
                $fnEnv->defineVar('arguments', $argsObj);
            }

            // Execute body
            if ($body instanceof BlockStatement) {
                $this->hoistDeclarations($body->body, $fnEnv);
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
        $interpreter = $this;

        $executor = function (
            JsFunction $fn,
            JsValue $thisValue,
            array $args,
        ) use ($interpreter): JsValue {
            return $interpreter->executeGeneratorBody($fn, $thisValue, $args);
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
    ): JsValue {
        $this->callStack->push($fn->getName(), 0);

        try {
            $fnEnv = $fn->getClosure()->createChild();

            // Bind this
            $fnEnv->defineVar('this', $thisValue);

            // Bind parameters
            $this->bindParameters($fn->getParams(), $args, $fnEnv);

            // Bind arguments object
            $argsObj = JsArray::fromArray($args);
            $fnEnv->defineVar('arguments', $argsObj);

            // Execute body
            $body = $fn->getBody();
            if ($body instanceof BlockStatement) {
                $this->hoistDeclarations($body->body, $fnEnv);
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
    private function bindParameters(array $params, array $args, Environment $env): void
    {
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

    private function bindPattern(Node $pattern, JsValue $value, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            $env->defineVar($pattern->name, $value);
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
            $this->bindPattern($pattern->left, $value, $env);
            return;
        }

        throw new InternalError('Unknown parameter pattern: ' . $pattern->type());
    }

    private function bindArrayPattern(ArrayPattern $pattern, JsValue $value, Environment $env): void
    {
        for ($i = 0; $i < count($pattern->elements); $i++) {
            $element = $pattern->elements[$i];
            if ($element === null) {
                continue;
            }

            if ($element instanceof RestElement) {
                $rest = [];
                if ($value instanceof JsArray) {
                    $len = $value->getLength();
                    for ($j = $i; $j < $len; $j++) {
                        $rest[] = $value->get((string) $j);
                    }
                }
                $this->bindPattern($element->argument, JsArray::fromArray($rest), $env);
                break;
            }

            $elemValue = ($value instanceof JsObject) ? $value->get((string) $i) : JsUndefined::instance();
            $this->bindPattern($element, $elemValue, $env);
        }
    }

    private function bindObjectPattern(ObjectPattern $pattern, JsValue $value, Environment $env): void
    {
        $usedKeys = [];
        foreach ($pattern->properties as $prop) {
            if ($prop instanceof RestElement) {
                $restObj = new JsObject();
                if ($value instanceof JsObject) {
                    foreach ($value->getOwnPropertyNames() as $key) {
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
        $obj = $this->evaluate($node->object, $env);

        if ($node->optional && ($obj instanceof JsNull || $obj instanceof JsUndefined)) {
            return JsUndefined::instance();
        }

        // Evaluate the property key. For computed access, the key may be a Symbol.
        $rawKey = null;
        if ($node->computed) {
            $rawKey = $this->evaluate($node->property, $env);
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
                return new JsNumber((float) mb_strlen($obj->value, 'UTF-8'));
            }
            if (ctype_digit($key)) {
                $idx = (int) $key;
                if ($idx >= 0 && $idx < mb_strlen($obj->value, 'UTF-8')) {
                    return new JsString(mb_substr($obj->value, $idx, 1, 'UTF-8'));
                }
                return JsUndefined::instance();
            }
            // Check for String.prototype methods via global String.prototype
            if ($env->has('__StringPrototype__')) {
                $proto = $env->get('__StringPrototype__');
                if ($proto instanceof JsObject) {
                    $method = $proto->get($key);
                    if ($method instanceof JsFunction) {
                        // Return a bound method
                        return JsFunction::fromCallable($key, function (JsValue $this_, array $args) use ($method, $obj): JsValue {
                            return $method->call($obj, $args);
                        });
                    }
                }
            }
            return JsUndefined::instance();
        }

        if ($obj instanceof JsObject) {
            if ($isSymbolKey) {
                return $obj->getBySymbol($rawKey);
            }
            return $obj->get($key);
        }

        // null/undefined property access is always a TypeError
        if ($obj instanceof JsNull || $obj instanceof JsUndefined) {
            throw new TypeError(
                "Cannot read properties of " . ($obj instanceof JsNull ? 'null' : 'undefined')
                . " (reading '{$key}')",
            );
        }

        // Auto-boxing for primitives (number, boolean)
        $boxed = TypeConversion::toObject($obj);
        if ($isSymbolKey) {
            return $boxed->getBySymbol($rawKey);
        }
        return $boxed->get($key);
    }

    /** Create a factory function that returns a string iterator (for Symbol.iterator). */
    private function createStringIteratorFactory(JsString $str): JsFunction
    {
        $iteratorFactory = function () use ($str): JsValue {
            $chars = [];
            $len = mb_strlen($str->value, 'UTF-8');
            for ($i = 0; $i < $len; $i++) {
                $chars[] = mb_substr($str->value, $i, 1, 'UTF-8');
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
        };

        return JsFunction::fromCallable('[Symbol.iterator]', $iteratorFactory);
    }

    private function evalArrayExpression(ArrayExpression $node, Environment $env): JsValue
    {
        $elements = [];
        foreach ($node->elements as $elem) {
            if ($elem === null) {
                $elements[] = JsUndefined::instance();
                continue;
            }
            if ($elem instanceof SpreadElement) {
                $iterable = $this->evaluate($elem->argument, $env);
                $this->spreadInto($iterable, $elements);
                continue;
            }
            $elements[] = $this->evaluate($elem, $env);
        }
        return JsArray::fromArray($elements);
    }

    private function evalObjectExpression(ObjectExpression $node, Environment $env): JsValue
    {
        $obj = new JsObject();

        foreach ($node->properties as $prop) {
            if ($prop instanceof SpreadElement) {
                $source = $this->evaluate($prop->argument, $env);
                if ($source instanceof JsObject) {
                    foreach ($source->getOwnPropertyNames() as $key) {
                        $obj->set($key, $source->get($key));
                    }
                }
                continue;
            }

            if (!$prop instanceof Property) {
                continue;
            }

            $key = $prop->computed
                ? TypeConversion::toString($this->evaluate($prop->key, $env))
                : ($prop->key instanceof Identifier
                    ? $prop->key->name
                    : ($prop->key instanceof Literal
                        ? TypeConversion::toString($this->evalLiteral($prop->key))
                        : ''));

            if ($prop->kind === 'get' || $prop->kind === 'set') {
                $fn = $this->evaluate($prop->value, $env);
                if ($fn instanceof JsFunction) {
                    $existing = $obj->getOwnPropertyDescriptor($key);
                    if ($prop->kind === 'get') {
                        $obj->defineOwnProperty($key, PropertyDescriptor::accessor(
                            $fn,
                            $existing?->set,
                        ));
                    } else {
                        $obj->defineOwnProperty($key, PropertyDescriptor::accessor(
                            $existing?->get,
                            $fn,
                        ));
                    }
                }
                continue;
            }

            $value = $this->evaluate($prop->value, $env);
            $obj->set($key, $value);
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
            true,
        );
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
        );
        // Named function expressions can reference themselves
        if ($node->name !== null) {
            $fnEnv->defineVar($node->name, $fn);
        }
        // Constructor prototype
        $proto = new JsObject();
        $proto->set('constructor', $fn);
        $fn->set('prototype', $proto);
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
     * Handle yield* (delegate yield).
     *
     * Iterates the sub-iterable and yields each value. The final result
     * of the yield* expression is the return value of the sub-iterator
     * (i.e., the value when done is true).
     */
    private function evalYieldDelegate(
        YieldExpression $node,
        Environment $env,
    ): JsValue {
        $iterable = $node->argument !== null
            ? $this->evaluate($node->argument, $env)
            : JsUndefined::instance();

        // If the iterable is a JsGenerator, delegate to its iterator protocol.
        if ($iterable instanceof JsGenerator) {
            return $this->delegateToGenerator($iterable);
        }

        // If the iterable is a JsArray, yield each element.
        if ($iterable instanceof JsArray) {
            $len = $iterable->getLength();
            for ($i = 0; $i < $len; $i++) {
                $val = $iterable->get((string) $i);
                \Fiber::suspend($val);
            }
            return JsUndefined::instance();
        }

        // If the iterable is a JsString, yield each character.
        if ($iterable instanceof JsString) {
            $str = $iterable->value;
            $len = mb_strlen($str, 'UTF-8');
            for ($i = 0; $i < $len; $i++) {
                \Fiber::suspend(new JsString(mb_substr($str, $i, 1, 'UTF-8')));
            }
            return JsUndefined::instance();
        }

        // If it has a next() method, treat as an iterator.
        if ($iterable instanceof JsObject) {
            $nextFn = $iterable->get('next');
            if ($nextFn instanceof JsFunction) {
                return $this->delegateToIterator($iterable, $nextFn);
            }
        }

        return JsUndefined::instance();
    }

    /**
     * Delegate to a JsGenerator sub-iterator.
     *
     * Calls next() on the sub-generator, yielding each value until done.
     * Values passed to next() on the outer generator are forwarded to the
     * inner generator.
     */
    private function delegateToGenerator(JsGenerator $inner): JsValue
    {
        $result = $inner->next();
        while (true) {
            $done = $result->get('done');
            if ($done instanceof JsBoolean && $done->value) {
                return $result->get('value');
            }

            // Yield the inner value to the outer caller.
            $received = \Fiber::suspend($result->get('value'));
            $nextArg = $received instanceof JsValue ? $received : JsUndefined::instance();

            $result = $inner->next($nextArg);
        }
    }

    /**
     * Delegate to a generic iterator object (one with a next() method).
     */
    private function delegateToIterator(JsObject $iterator, JsFunction $nextFn): JsValue
    {
        while (true) {
            $result = $this->callFunction($nextFn, $iterator, []);
            if (!$result instanceof JsObject) {
                return JsUndefined::instance();
            }

            $done = $result->get('done');
            if ($done instanceof JsBoolean && $done->value) {
                return $result->get('value');
            }

            $received = \Fiber::suspend($result->get('value'));
            // For generic iterators we don't forward the received value
            // (the spec does for generator delegates, handled above).
        }
    }

    private function evalClassExpression(ClassExpression $node, Environment $env): JsValue
    {
        return $this->buildClass($node->id?->name, $node->superClass, $node->body, $env);
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
            $init = $declarator->init !== null
                ? $this->evaluate($declarator->init, $env)
                : JsUndefined::instance();

            $this->declareBinding($node->kind, $declarator->id, $init, $env);
        }
        return Completion::normal(JsUndefined::instance());
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
        if ($node->id !== null) {
            $env->defineVar($node->id->name, $cls);
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

        $constructor = null;
        $staticMethods = [];
        $instanceMethods = [];

        foreach ($methods as $method) {
            $key = $method->computed
                ? TypeConversion::toString($this->evaluate($method->key, $env))
                : ($method->key instanceof Identifier
                    ? $method->key->name
                    : TypeConversion::toString($this->evaluate($method->key, $env)));

            $fn = $this->evaluate($method->value, $env);

            if ($method->kind === 'constructor') {
                $constructor = $fn;
            } elseif ($method->static) {
                $staticMethods[] = [$key, $fn, $method->kind];
            } else {
                $instanceMethods[] = [$key, $fn, $method->kind];
            }
        }

        if ($constructor === null) {
            // Default constructor
            if ($superClass instanceof JsFunction) {
                $constructor = JsFunction::fromCallable(
                    $name ?? '(anonymous)',
                    function (JsValue $thisVal, array $args, Interpreter $interp) use ($superClass) {
                        return $interp->callFunction($superClass, $thisVal, $args);
                    },
                );
            } else {
                $constructor = JsFunction::fromCallable(
                    $name ?? '(anonymous)',
                    fn() => JsUndefined::instance(),
                );
            }
        }

        if (!$constructor instanceof JsFunction) {
            $constructor = JsFunction::fromCallable($name ?? '', fn() => JsUndefined::instance());
        }

        // Set up prototype chain
        $proto = new JsObject(
            $superClass instanceof JsFunction
                ? ($superClass->get('prototype') instanceof JsObject
                    ? $superClass->get('prototype')
                    : null)
                : null,
        );

        foreach ($instanceMethods as [$key, $fn, $kind]) {
            if ($kind === 'get' || $kind === 'set') {
                $existing = $proto->getOwnPropertyDescriptor($key);
                if ($kind === 'get') {
                    $proto->defineOwnProperty(
                        $key,
                        PropertyDescriptor::accessor($fn instanceof JsFunction ? $fn : null, $existing?->set),
                    );
                } else {
                    $proto->defineOwnProperty(
                        $key,
                        PropertyDescriptor::accessor($existing?->get, $fn instanceof JsFunction ? $fn : null),
                    );
                }
            } else {
                $proto->set($key, $fn);
            }
        }

        $constructor->set('prototype', $proto);
        $proto->set('constructor', $constructor);

        // Static methods
        foreach ($staticMethods as [$key, $fn, $kind]) {
            $constructor->set($key, $fn);
        }

        // Inheritance
        if ($superClass instanceof JsFunction) {
            $constructor->setPrototype($superClass);
        }

        return $constructor;
    }

    private function execBlockStatement(BlockStatement $node, Environment $env): Completion
    {
        $blockEnv = $env->createChild();
        $this->hoistDeclarations($node->body, $blockEnv);
        $completion = $this->executeBody($node->body, $blockEnv);

        // Annex B: in sloppy mode, propagate function declaration values from
        // block scope back to the enclosing scope so they are visible outside.
        if (!$this->strictMode) {
            foreach ($node->body as $stmt) {
                if ($stmt instanceof FunctionDeclaration && $env->has($stmt->id->name)) {
                    $env->defineVar($stmt->id->name, $blockEnv->get($stmt->id->name));
                }
            }
        }

        return $completion;
    }

    private function execIfStatement(IfStatement $node, Environment $env): Completion
    {
        $test = $this->evaluate($node->test, $env);
        if (TypeConversion::toBoolean($test)) {
            return $this->executeStatement($node->consequent, $env);
        }
        if ($node->alternate !== null) {
            return $this->executeStatement($node->alternate, $env);
        }
        return Completion::normal(JsUndefined::instance());
    }

    private function execForStatement(ForStatement $node, Environment $env): Completion
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

        $iterations = 0;
        while (true) {
            if (++$iterations > $this->maxLoopIterations) {
                throw new InternalError('Maximum loop iterations exceeded');
            }

            if ($node->test !== null) {
                $test = $this->evaluate($node->test, $loopEnv);
                if (!TypeConversion::toBoolean($test)) {
                    break;
                }
            }

            // For let/const: create per-iteration scope with copied bindings
            $iterEnv = $loopEnv->createChild();
            if ($isLetConst) {
                /** @var VariableDeclaration $varDecl */
                $varDecl = $node->init;
                foreach ($varDecl->declarations as $decl) {
                    $this->copyBindingToChild($decl->id, $loopEnv, $iterEnv);
                }
            }
            $completion = $this->executeStatement($node->body, $iterEnv);

            if ($completion->type === CompletionType::Break && $completion->target === null) {
                break;
            }
            if ($completion->type === CompletionType::Continue && $completion->target === null) {
                // fall through to update
            } elseif ($completion->isAbrupt()) {
                return $completion;
            }

            if ($node->update !== null) {
                $this->evaluate($node->update, $loopEnv);
            }
        }

        return Completion::normal(JsUndefined::instance());
    }

    private function execForInStatement(ForInStatement $node, Environment $env): Completion
    {
        $obj = $this->evaluate($node->right, $env);
        if ($obj instanceof JsNull || $obj instanceof JsUndefined) {
            return Completion::normal(JsUndefined::instance());
        }
        if (!$obj instanceof JsObject) {
            $obj = TypeConversion::toObject($obj);
        }

        $keys = $obj->getEnumerableKeys();
        $iterations = 0;

        foreach ($keys as $key) {
            if (++$iterations > $this->maxLoopIterations) {
                throw new InternalError('Maximum loop iterations exceeded');
            }

            $iterEnv = $env->createChild();
            $this->assignForBinding($node->left, new JsString($key), $iterEnv);
            $completion = $this->executeStatement($node->body, $iterEnv);

            if ($completion->type === CompletionType::Break && $completion->target === null) {
                break;
            }
            if ($completion->type === CompletionType::Continue && $completion->target === null) {
                continue;
            }
            if ($completion->isAbrupt()) {
                return $completion;
            }
        }

        return Completion::normal(JsUndefined::instance());
    }

    private function execForOfStatement(ForOfStatement $node, Environment $env): Completion
    {
        $iterable = $this->evaluate($node->right, $env);
        $iterations = 0;

        // Try the iterator protocol first.
        $iterator = $this->getIterator($iterable);

        if ($iterator !== null) {
            $nextMethod = $iterator->get('next');
            if (!$nextMethod instanceof JsFunction) {
                throw new TypeError('Iterator result next is not a function');
            }

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
                $this->assignForBinding($node->left, $value, $iterEnv);
                $completion = $this->executeStatement($node->body, $iterEnv);

                if ($completion->type === CompletionType::Break && $completion->target === null) {
                    break;
                }
                if ($completion->type === CompletionType::Continue && $completion->target === null) {
                    continue;
                }
                if ($completion->isAbrupt()) {
                    return $completion;
                }
            }

            return Completion::normal(JsUndefined::instance());
        }

        // Fallback for JsArray without Symbol.iterator (should not normally happen).
        if ($iterable instanceof JsArray) {
            $len = $iterable->getLength();
            for ($i = 0; $i < $len; $i++) {
                if (++$iterations > $this->maxLoopIterations) {
                    throw new InternalError('Maximum loop iterations exceeded');
                }

                $iterEnv = $env->createChild();
                $this->assignForBinding($node->left, $iterable->get((string) $i), $iterEnv);
                $completion = $this->executeStatement($node->body, $iterEnv);

                if ($completion->type === CompletionType::Break && $completion->target === null) {
                    break;
                }
                if ($completion->type === CompletionType::Continue && $completion->target === null) {
                    continue;
                }
                if ($completion->isAbrupt()) {
                    return $completion;
                }
            }

            return Completion::normal(JsUndefined::instance());
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
            $this->declareBinding($left->kind, $left->declarations[0]->id, $value, $env);
        } elseif ($left instanceof Identifier) {
            $env->defineVar($left->name, $value);
        } elseif ($left instanceof ArrayPattern || $left instanceof ObjectPattern) {
            $this->bindPattern($left, $value, $env);
        }
    }

    private function execWhileStatement(WhileStatement $node, Environment $env): Completion
    {
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

            if ($completion->type === CompletionType::Break && $completion->target === null) {
                break;
            }
            if ($completion->type === CompletionType::Continue && $completion->target === null) {
                continue;
            }
            if ($completion->isAbrupt()) {
                return $completion;
            }
        }

        return Completion::normal(JsUndefined::instance());
    }

    private function execDoWhileStatement(DoWhileStatement $node, Environment $env): Completion
    {
        $iterations = 0;
        do {
            if (++$iterations > $this->maxLoopIterations) {
                throw new InternalError('Maximum loop iterations exceeded');
            }

            $completion = $this->executeStatement($node->body, $env);

            if ($completion->type === CompletionType::Break && $completion->target === null) {
                break;
            }
            if ($completion->type === CompletionType::Continue && $completion->target === null) {
                // fall through to test
            } elseif ($completion->isAbrupt()) {
                return $completion;
            }

            $test = $this->evaluate($node->test, $env);
        } while (TypeConversion::toBoolean($test));

        return Completion::normal(JsUndefined::instance());
    }

    private function execSwitchStatement(SwitchStatement $node, Environment $env): Completion
    {
        $discriminant = $this->evaluate($node->discriminant, $env);
        $switchEnv = $env->createChild();
        $matched = false;
        $defaultCase = null;

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
                $result = $this->executeCaseBody($case, $switchEnv);
                if ($result !== null) {
                    if ($result->type === CompletionType::Break && $result->target === null) {
                        return Completion::normal(JsUndefined::instance());
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
                    $result = $this->executeCaseBody($case, $switchEnv);
                    if ($result !== null) {
                        if ($result->type === CompletionType::Break && $result->target === null) {
                            return Completion::normal(JsUndefined::instance());
                        }
                        return $result;
                    }
                }
            }
        }

        return Completion::normal(JsUndefined::instance());
    }

    private function executeCaseBody(SwitchCase $case, Environment $env): ?Completion
    {
        foreach ($case->consequent as $stmt) {
            $completion = $this->executeStatement($stmt, $env);
            if ($completion->isAbrupt()) {
                return $completion;
            }
        }
        return null;
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
        try {
            $completion = $this->execBlockStatement($node->block, $env);
        } catch (GeneratorThrowSignal $e) {
            // A generator.throw() signal propagated into a try block.
            // Convert it to a Throw completion so the catch handler can run.
            $completion = Completion::throw($e->jsValue);
        } catch (\PhpJs\Exceptions\JsThrowable $e) {
            // A PHP exception carrying a JS value (e.g., from generator.throw()).
            // Extract the original JS value for the catch handler.
            $completion = Completion::throw($e->jsValue);
        } catch (\PhpJs\Exceptions\RuntimeError $e) {
            // A PHP exception representing a JS runtime error. Convert to
            // a Throw completion so the JS catch handler can process it.
            $completion = Completion::throw($this->phpExceptionToJsValue($e));
        }

        if ($completion->type === CompletionType::Throw && $node->handler !== null) {
            $catchEnv = $env->createChild();
            if ($node->handler->param !== null) {
                $this->bindPattern($node->handler->param, $completion->value, $catchEnv);
            }
            $this->hoistDeclarations($node->handler->body->body, $catchEnv);
            $completion = $this->executeBody($node->handler->body->body, $catchEnv);
        }

        if ($node->finalizer !== null) {
            $finallyCompletion = $this->execBlockStatement($node->finalizer, $env);
            if ($finallyCompletion->isAbrupt()) {
                return $finallyCompletion;
            }
        }

        return $completion;
    }

    private function execLabeledStatement(LabeledStatement $node, Environment $env): Completion
    {
        $completion = $this->executeStatement($node->body, $env);

        if (
            ($completion->type === CompletionType::Break || $completion->type === CompletionType::Continue)
            && $completion->target === $node->label
        ) {
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
        $withEnv = $env->createChild();
        foreach ($obj->getOwnPropertyNames() as $key) {
            $withEnv->defineVar($key, $obj->get($key));
        }
        return $this->executeStatement($node->body, $withEnv);
    }

    // -------------------------------------------------------------------------
    // Hoisting
    // -------------------------------------------------------------------------

    /** @param Node[] $statements */
    private function hoistDeclarations(array $statements, Environment $env): void
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof FunctionDeclaration) {
                $fn = new JsFunction(
                    $stmt->id->name,
                    $stmt->params,
                    $stmt->body,
                    $env,
                    isGenerator: $stmt->generator,
                );
                $proto = new JsObject();
                $proto->set('constructor', $fn);
                $fn->set('prototype', $proto);
                $env->defineVar($stmt->id->name, $fn);
            } elseif ($stmt instanceof VariableDeclaration && $stmt->kind === 'var') {
                foreach ($stmt->declarations as $decl) {
                    $this->hoistVarNames($decl->id, $env);
                }
            }

            // Annex B: in sloppy mode, hoist function declaration names from
            // nested blocks (if, for, while, etc.) to the enclosing scope.
            if (!$this->strictMode) {
                $this->hoistBlockFunctionDeclarations($stmt, $env);
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
    private function hoistBlockFunctionDeclarations(Node $stmt, Environment $env): void
    {
        $children = match (true) {
            $stmt instanceof BlockStatement => $stmt->body,
            $stmt instanceof IfStatement => array_filter([
                $stmt->consequent,
                $stmt->alternate,
            ]),
            $stmt instanceof LabeledStatement => [$stmt->body],
            default => [],
        };

        foreach ($children as $child) {
            if ($child instanceof FunctionDeclaration) {
                if (!$env->has($child->id->name)) {
                    $env->defineVar($child->id->name, JsUndefined::instance());
                }
            } elseif ($child instanceof BlockStatement) {
                foreach ($child->body as $inner) {
                    if ($inner instanceof FunctionDeclaration) {
                        if (!$env->has($inner->id->name)) {
                            $env->defineVar($inner->id->name, JsUndefined::instance());
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
                $env->defineVar($pattern->name, JsUndefined::instance());
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

    private function copyBindingToChild(Node $pattern, Environment $source, Environment $target): void
    {
        if ($pattern instanceof Identifier) {
            $target->defineVar($pattern->name, $source->get($pattern->name));
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveReference(Node $node, Environment $env): Reference
    {
        if ($node instanceof Identifier) {
            return new Reference($env, $node->name, $this->strictMode);
        }

        if ($node instanceof MemberExpression) {
            $obj = $this->evaluate($node->object, $env);
            if (!$obj instanceof JsObject) {
                $obj = TypeConversion::toObject($obj);
            }
            $rawRefKey = null;
            if ($node->computed) {
                $rawRefKey = $this->evaluate($node->property, $env);
            }
            if ($rawRefKey instanceof JsSymbol) {
                return new Reference($obj, '', $this->strictMode, $rawRefKey);
            }
            $key = $node->computed
                ? TypeConversion::toString($rawRefKey)
                : ($node->property instanceof Identifier ? $node->property->name : '');
            return new Reference($obj, $key, $this->strictMode);
        }

        throw new ReferenceError('Invalid assignment target');
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
            $elements = $target instanceof ArrayPattern ? $target->elements : $target->elements;
            for ($i = 0; $i < count($elements); $i++) {
                $elem = $elements[$i];
                if ($elem === null) {
                    continue;
                }
                if ($elem instanceof RestElement || $elem instanceof SpreadElement) {
                    $rest = [];
                    if ($value instanceof JsArray) {
                        $len = $value->getLength();
                        for ($j = $i; $j < $len; $j++) {
                            $rest[] = $value->get((string) $j);
                        }
                    }
                    $ref = $this->resolveReference(
                        $elem instanceof RestElement ? $elem->argument : $elem->argument,
                        $env,
                    );
                    $ref->setValue(JsArray::fromArray($rest));
                    break;
                }
                $elemValue = ($value instanceof JsObject)
                    ? $value->get((string) $i)
                    : JsUndefined::instance();

                if ($elem instanceof AssignmentPattern || $elem instanceof AssignmentExpression) {
                    $elemTarget = $elem instanceof AssignmentPattern ? $elem->left : $elem->left;
                    if ($elemValue instanceof JsUndefined) {
                        $elemValue = $this->evaluate(
                            $elem instanceof AssignmentPattern ? $elem->right : $elem->right,
                            $env,
                        );
                    }
                    $ref = $this->resolveReference($elemTarget, $env);
                    $ref->setValue($elemValue);
                } else {
                    $ref = $this->resolveReference($elem, $env);
                    $ref->setValue($elemValue);
                }
            }
            return;
        }

        if ($target instanceof ObjectPattern || $target instanceof ObjectExpression) {
            $props = $target instanceof ObjectPattern ? $target->properties : $target->properties;
            foreach ($props as $prop) {
                if ($prop instanceof RestElement || $prop instanceof SpreadElement) {
                    // Simplified: skip rest in destructuring assignment
                    continue;
                }
                $propNode = $prop instanceof AssignmentProperty ? $prop : $prop;
                $key = ($propNode instanceof AssignmentProperty || $propNode instanceof Property)
                    ? ($propNode->key instanceof Identifier
                        ? $propNode->key->name
                        : TypeConversion::toString($this->evaluate($propNode->key, $env)))
                    : '';
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
                    if ($propValue instanceof JsUndefined) {
                        $propValue = $this->evaluate(
                            $valueNode instanceof AssignmentPattern
                                ? $valueNode->right
                                : $valueNode->right,
                            $env,
                        );
                    }
                    $ref = $this->resolveReference($realTarget, $env);
                    $ref->setValue($propValue);
                } else {
                    $ref = $this->resolveReference($valueNode, $env);
                    $ref->setValue($propValue);
                }
            }
        }
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
    private function phpExceptionToJsValue(\PhpJs\Exceptions\RuntimeError $e): JsValue
    {
        $name = match (true) {
            $e instanceof TypeError => 'TypeError',
            $e instanceof ReferenceError => 'ReferenceError',
            $e instanceof \PhpJs\Exceptions\RangeError => 'RangeError',
            $e instanceof \PhpJs\Exceptions\SyntaxError => 'SyntaxError',
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
        if ($value instanceof JsObject && $value->has('message')) {
            $msg = TypeConversion::toString($value->get('message'));
            $name = $value->has('name') ? TypeConversion::toString($value->get('name')) : 'Error';
            throw match ($name) {
                'TypeError' => new TypeError($msg),
                'ReferenceError' => new ReferenceError($msg),
                'RangeError' => new \PhpJs\Exceptions\RangeError($msg),
                'SyntaxError' => new \PhpJs\Exceptions\SyntaxError($msg),
                default => new \PhpJs\Exceptions\RuntimeError($msg),
            };
        }
        throw new \PhpJs\Exceptions\RuntimeError(TypeConversion::toString($value));
    }

    public function getCallStack(): CallStack
    {
        return $this->callStack;
    }

    public function getGlobalEnv(): Environment
    {
        return $this->globalEnv;
    }

    /** Lazily created global object used as the default this in sloppy mode. */
    private ?JsObject $globalObject = null;

    public function getGlobalObject(): JsObject
    {
        if ($this->globalObject === null) {
            $this->globalObject = new JsObject();
        }
        return $this->globalObject;
    }
}
