<?php

declare(strict_types=1);

namespace Phasis\Bytecode;

use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Expression\AssignmentExpression;
use Phasis\Ast\Expression\BinaryExpression;
use Phasis\Ast\Expression\CallExpression;
use Phasis\Ast\Expression\ConditionalExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Expression\LogicalExpression;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\UnaryExpression;
use Phasis\Ast\Expression\UpdateExpression;
use Phasis\Ast\Node;
use Phasis\Ast\Statement\BlockStatement;
use Phasis\Ast\Statement\BreakStatement;
use Phasis\Ast\Statement\DoWhileStatement;
use Phasis\Ast\Statement\EmptyStatement;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Ast\Statement\ForStatement;
use Phasis\Ast\Statement\IfStatement;
use Phasis\Ast\Statement\ReturnStatement;
use Phasis\Ast\Statement\WhileStatement;
use Phasis\Value\JsFunction;

/** A conservative proof that a string loop cannot invoke JavaScript during its optimized body. */
final class StringScannerPlan
{
    /** @var array<string, true> */
    public array $stringParameters = [];

    /** @var array<string, array{parameters: list<string>, body: Node, reference: \WeakReference<JsFunction>}> */
    public array $predicates = [];

    public bool $usesCharacterRead = false;

    /** @var array<string, true> */
    private array $parameters = [];

    /** @var array<string, true> */
    private array $numericBindings = [];

    /** @var array<string, string> */
    private array $declarations = [];

    private int $loops = 0;

    private function __construct(private readonly JsFunction $function)
    {
    }

    /** Keep environment-dependent specialization failures out of the shared syntax cache. */
    public static function maySpecialize(JsFunction $function): bool
    {
        $body = $function->getBody();
        if (!$body instanceof BlockStatement) {
            return false;
        }
        $plan = new self($function);
        foreach ($function->getParams() as $parameter) {
            if ($parameter instanceof Identifier) {
                $plan->parameters[$parameter->name] = true;
            }
        }
        $plan->findStringParameters($body);
        return $plan->stringParameters !== [];
    }

    /** Reject ambiguous types, scope effects and user callbacks before emitting any code. */
    public static function analyze(JsFunction $function): ?self
    {
        $body = $function->getBody();
        if (!$body instanceof BlockStatement || $function->realm === null || $function->getBoundTarget() !== null) {
            return null;
        }
        $plan = new self($function);
        foreach ($function->getParams() as $parameter) {
            if (!$parameter instanceof Identifier || isset($plan->parameters[$parameter->name])) {
                return null;
            }
            $plan->parameters[$parameter->name] = true;
        }
        $plan->findStringParameters($body);
        if ($plan->stringParameters === []) {
            return null;
        }
        $plan->numericBindings = array_diff_key($plan->parameters, $plan->stringParameters);
        if (!$plan->collectDeclarations($body)) {
            return null;
        }
        $safeNames = [];
        foreach (array_keys($plan->parameters + $plan->numericBindings) as $name) {
            $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
            if (isset($safeNames[$safe])) {
                return null;
            }
            $safeNames[$safe] = true;
        }
        $initialized = array_diff_key($plan->parameters, $plan->stringParameters);
        return $plan->validateBlock($body, $initialized, 0, true) && $plan->loops > 0 ? $plan : null;
    }

    private function findStringParameters(Node $node): void
    {
        if (
            $node instanceof MemberExpression && !$node->computed && $node->object instanceof Identifier
            && $node->property instanceof Identifier && isset($this->parameters[$node->object->name])
            && in_array($node->property->name, ['length', 'charCodeAt'], true)
        ) {
            $this->stringParameters[$node->object->name] = true;
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->findStringParameters($child);
                }
            }
        }
    }

    private function collectDeclarations(Node $node): bool
    {
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $declaration) {
                if (
                    !$declaration->id instanceof Identifier || $declaration->init === null
                    || isset($this->parameters[$declaration->id->name]) || isset($this->declarations[$declaration->id->name])
                ) {
                    return false;
                }
                $this->declarations[$declaration->id->name] = $node->kind;
                $this->numericBindings[$declaration->id->name] = true;
            }
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node && !$this->collectDeclarations($child)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @param array<string, true> $initialized */
    private function validateBlock(BlockStatement $block, array &$initialized, int $loopDepth, bool $functionBody = false): bool
    {
        $lexical = [];
        foreach ($block->body as $statement) {
            if (!$this->validateStatement($statement, $initialized, $loopDepth)) {
                return false;
            }
            if ($statement instanceof VariableDeclaration && $statement->kind !== 'var') {
                foreach ($statement->declarations as $declaration) {
                    if ($declaration->id instanceof Identifier) {
                        $lexical[$declaration->id->name] = true;
                    }
                }
            }
        }
        if (!$functionBody) {
            $initialized = array_diff_key($initialized, $lexical);
        }
        return true;
    }

    /** @param array<string, true> $initialized */
    private function validateStatement(Node $statement, array &$initialized, int $loopDepth): bool
    {
        if ($statement instanceof EmptyStatement) {
            return true;
        }
        if ($statement instanceof BlockStatement) {
            return $this->validateBlock($statement, $initialized, $loopDepth);
        }
        if ($statement instanceof VariableDeclaration) {
            if (!in_array($statement->kind, ['var', 'let', 'const'], true)) {
                return false;
            }
            foreach ($statement->declarations as $declaration) {
                if (
                    !$declaration->id instanceof Identifier || $declaration->init === null
                    || $this->expressionType($declaration->init, $initialized) !== 'number'
                ) {
                    return false;
                }
                $initialized[$declaration->id->name] = true;
            }
            return true;
        }
        if ($statement instanceof ExpressionStatement) {
            $expression = $statement->expression;
            if ($expression instanceof Literal && is_string($expression->value)) {
                return true;
            }
            if ($expression instanceof UpdateExpression && $expression->argument instanceof Identifier) {
                $name = $expression->argument->name;
                return isset($initialized[$name]) && ($this->declarations[$name] ?? null) !== 'const';
            }
            if ($expression instanceof AssignmentExpression && $expression->left instanceof Identifier) {
                $name = $expression->left->name;
                if (
                    !isset($initialized[$name]) || ($this->declarations[$name] ?? null) === 'const'
                    || !in_array($expression->operator, ['=', '+=', '-=', '*='], true)
                    || $this->expressionType($expression->right, $initialized) !== 'number'
                ) {
                    return false;
                }
                $initialized[$name] = true;
                return true;
            }
            return false;
        }
        if ($statement instanceof ReturnStatement) {
            return $statement->argument === null || $this->expressionType($statement->argument, $initialized) !== null;
        }
        if ($statement instanceof BreakStatement) {
            return $statement->label === null && $loopDepth > 0;
        }
        if ($statement instanceof IfStatement) {
            if ($this->expressionType($statement->test, $initialized) !== 'boolean') {
                return false;
            }
            $yes = $no = $initialized;
            if (!$this->validateStatement($statement->consequent, $yes, $loopDepth)) {
                return false;
            }
            if ($statement->alternate !== null && !$this->validateStatement($statement->alternate, $no, $loopDepth)) {
                return false;
            }
            $initialized = array_intersect_key($yes, $no);
            return true;
        }
        if ($statement instanceof WhileStatement || $statement instanceof DoWhileStatement) {
            ++$this->loops;
            if ($this->expressionType($statement->test, $initialized) !== 'boolean') {
                return false;
            }
            $body = $initialized;
            return $this->validateStatement($statement->body, $body, $loopDepth + 1);
        }
        if ($statement instanceof ForStatement) {
            ++$this->loops;
            if ($statement->init !== null) {
                $init = $statement->init instanceof VariableDeclaration ? $statement->init : new ExpressionStatement(
                    $statement->init->location,
                    $statement->init,
                );
                if (!$this->validateStatement($init, $initialized, $loopDepth)) {
                    return false;
                }
            }
            if ($statement->test !== null && $this->expressionType($statement->test, $initialized) !== 'boolean') {
                return false;
            }
            $body = $initialized;
            if (!$this->validateStatement($statement->body, $body, $loopDepth + 1)) {
                return false;
            }
            if (
                $statement->update !== null && !$this->validateStatement(
                    new ExpressionStatement($statement->update->location, $statement->update),
                    $body,
                    $loopDepth + 1,
                )
            ) {
                return false;
            }
            if ($statement->init instanceof VariableDeclaration && $statement->init->kind !== 'var') {
                foreach ($statement->init->declarations as $declaration) {
                    if ($declaration->id instanceof Identifier) {
                        unset($initialized[$declaration->id->name]);
                    }
                }
            }
            return true;
        }
        return false;
    }

    /** @param array<string, true> $initialized */
    private function expressionType(Node $expression, array $initialized): ?string
    {
        if ($expression instanceof Identifier) {
            return isset($initialized[$expression->name]) ? 'number' : null;
        }
        if ($expression instanceof Literal) {
            return is_int($expression->value) || is_float($expression->value)
                ? 'number' : (is_bool($expression->value) ? 'boolean' : null);
        }
        if ($expression instanceof MemberExpression) {
            return $this->isStringMember($expression, 'length') ? 'number' : null;
        }
        if ($expression instanceof CallExpression && !$expression->optional) {
            if ($expression->callee instanceof MemberExpression && $this->isStringMember($expression->callee, 'charCodeAt')) {
                if (count($expression->arguments) !== 1 || $this->expressionType($expression->arguments[0], $initialized) !== 'number') {
                    return null;
                }
                $this->usesCharacterRead = true;
                return 'number';
            }
            if ($expression->callee instanceof Identifier && !isset($this->parameters[$expression->callee->name])) {
                $name = $expression->callee->name;
                $predicate = $this->predicate($name);
                if ($predicate === null || count($predicate['parameters']) !== count($expression->arguments)) {
                    return null;
                }
                foreach ($expression->arguments as $argument) {
                    if ($this->expressionType($argument, $initialized) !== 'number') {
                        return null;
                    }
                }
                return 'boolean';
            }
        }
        return self::scalarExpressionType($expression, fn (Node $child): ?string => $this->expressionType($child, $initialized));
    }

    private function isStringMember(MemberExpression $expression, string $name): bool
    {
        return !$expression->computed && !$expression->optional && $expression->object instanceof Identifier
            && isset($this->stringParameters[$expression->object->name])
            && $expression->property instanceof Identifier && $expression->property->name === $name;
    }

    /** @return array{parameters: list<string>, body: Node, reference: \WeakReference<JsFunction>}|null */
    private function predicate(string $name): ?array
    {
        if (isset($this->numericBindings[$name])) {
            return null;
        }
        if (isset($this->predicates[$name])) {
            return $this->predicates[$name];
        }
        $value = $this->function->getClosure()->findBindingDepth($name)[0] ?? null;
        if (
            !$value instanceof JsFunction || $value->getNativeCallable() !== null || $value->getBoundTarget() !== null
            || $value->isClassConstructor() || $value->isDerivedConstructor() || $value->isAsync() || $value->isGenerator()
        ) {
            return null;
        }
        $parameters = [];
        foreach ($value->getParams() as $parameter) {
            if (!$parameter instanceof Identifier || isset($parameters[$parameter->name])) {
                return null;
            }
            $parameters[$parameter->name] = true;
        }
        $body = $value->getBody();
        if ($body instanceof BlockStatement) {
            $statements = array_values(array_filter($body->body, static fn (Node $node): bool => !(
                $node instanceof ExpressionStatement && $node->expression instanceof Literal
                && is_string($node->expression->value)
            )));
            if (count($statements) !== 1 || !$statements[0] instanceof ReturnStatement || $statements[0]->argument === null) {
                return null;
            }
            $body = $statements[0]->argument;
        }
        if (!$body instanceof Node || self::predicateExpressionType($body, $parameters) !== 'boolean') {
            return null;
        }
        return $this->predicates[$name] = [
            'parameters' => array_keys($parameters), 'body' => $body, 'reference' => \WeakReference::create($value),
        ];
    }

    /** @param array<string, true> $parameters */
    private static function predicateExpressionType(Node $expression, array $parameters): ?string
    {
        if ($expression instanceof Identifier) {
            return isset($parameters[$expression->name]) ? 'number' : null;
        }
        if ($expression instanceof Literal) {
            return is_int($expression->value) || is_float($expression->value)
                ? 'number' : (is_bool($expression->value) ? 'boolean' : null);
        }
        return self::scalarExpressionType($expression, static fn (Node $child): ?string => self::predicateExpressionType($child, $parameters));
    }

    /** @param \Closure(Node): ?string $type */
    public static function scalarExpressionType(Node $expression, \Closure $type): ?string
    {
        if ($expression instanceof UnaryExpression) {
            $argument = $type($expression->argument);
            return $expression->operator === '!' && $argument === 'boolean' ? 'boolean'
                : (in_array($expression->operator, ['+', '-'], true) && $argument === 'number' ? 'number' : null);
        }
        if ($expression instanceof BinaryExpression) {
            $left = $type($expression->left);
            $right = $type($expression->right);
            if ($left === 'number' && $right === 'number') {
                if (in_array($expression->operator, ['+', '-', '*', '/', '%'], true)) {
                    return 'number';
                }
                if (in_array($expression->operator, ['===', '!==', '==', '!=', '<', '>', '<=', '>='], true)) {
                    return 'boolean';
                }
            }
            return $left === 'boolean' && $right === 'boolean' && in_array($expression->operator, ['===', '!==', '==', '!='], true)
                ? 'boolean' : null;
        }
        if ($expression instanceof LogicalExpression) {
            return in_array($expression->operator, ['&&', '||'], true)
                && $type($expression->left) === 'boolean' && $type($expression->right) === 'boolean' ? 'boolean' : null;
        }
        if ($expression instanceof ConditionalExpression && $type($expression->test) === 'boolean') {
            $yes = $type($expression->consequent);
            return $yes !== null && $yes === $type($expression->alternate) ? $yes : null;
        }
        return null;
    }
}
