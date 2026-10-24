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
use Phasis\Ast\Statement\EmptyStatement;
use Phasis\Ast\Statement\ExpressionStatement;
use Phasis\Ast\Statement\IfStatement;
use Phasis\Ast\Statement\ReturnStatement;
use Phasis\Ast\Statement\WhileStatement;

/** Conservative scalar type inference and opaque-boundary discovery for one loop. */
final class StringLoopRegionAnalysis
{
    /** @var array<string, string> */
    private array $types = [];

    /** @var array<string, true> */
    private array $inputNames = [];

    /** @var array<string, true> */
    private array $writtenNames = [];

    /** @var array<string, string> */
    private array $declarations = [];

    /** @var list<Node> */
    private array $opaqueStatements = [];

    private bool $characterRead = false;

    /**
     * @param array<string, int> $slots
     * @param array<string, string> $captured
     * @param array<int, bool> $constants
     */
    private function __construct(
        public readonly WhileStatement $loop,
        private readonly array $slots,
        private readonly array $captured,
        private readonly array $constants,
    ) {
    }

    /**
     * No partial effects are permitted when a proof or entry guard fails.
     *
     * @param array<string, int> $slots
     * @param array<string, string> $captured
     * @param array<int, bool> $constants
     */
    public static function analyze(WhileStatement $loop, array $slots, array $captured, array $constants): ?StringLoopRegionPlan
    {
        if (!$loop->body instanceof BlockStatement) {
            return null;
        }
        $plan = new self($loop, $slots, $captured, $constants);
        if (!$plan->collectDeclarations($loop->body)) {
            return null;
        }
        $initialized = [];
        if ($plan->expressionType($loop->test, 'boolean', $initialized) !== 'boolean') {
            return null;
        }
        [$body, $backedge] = $plan->rewrite($loop->body, $initialized);
        if (
            !$body instanceof BlockStatement || !$backedge || !$plan->characterRead
            || $plan->knownExpressionType($loop->test) !== 'boolean' || !$plan->recheckExpressions($body)
        ) {
            return null;
        }
        $safeNames = [];
        foreach ($plan->types as $name => $_) {
            $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
            if (isset($safeNames[$safe])) {
                return null;
            }
            $safeNames[$safe] = true;
        }
        $inputs = $outputs = [];
        foreach ($plan->inputNames as $name => $_) {
            $inputs[$name] = ['slot' => $slots[$name], 'type' => $plan->types[$name]];
        }
        foreach ($plan->writtenNames as $name => $_) {
            $outputs[$name] = ['slot' => $slots[$name], 'type' => $plan->types[$name]];
        }
        return new StringLoopRegionPlan($loop, $body, $inputs, $outputs, $plan->types, $plan->opaqueStatements);
    }

    private function collectDeclarations(Node $node): bool
    {
        if (
            $node instanceof WhileStatement || $node instanceof \Phasis\Ast\Statement\ForStatement
            || $node instanceof \Phasis\Ast\Statement\ForOfStatement || $node instanceof \Phasis\Ast\Statement\ForInStatement
            || $node instanceof \Phasis\Ast\Statement\DoWhileStatement
        ) {
            return false;
        }
        if (
            $node instanceof \Phasis\Ast\Declaration\FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression || $node instanceof \Phasis\Ast\Expression\ArrowFunction
        ) {
            return true;
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $declaration) {
                if (!$declaration->id instanceof Identifier || isset($this->declarations[$declaration->id->name])) {
                    return false;
                }
                $this->declarations[$declaration->id->name] = $node->kind;
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

    /**
     * @param array<string, true> $initialized
     * @return array{Node, bool}
     */
    private function rewrite(Node $node, array &$initialized): array
    {
        if ($node instanceof EmptyStatement) {
            return [$node, true];
        }
        if ($node instanceof BlockStatement) {
            $statements = [];
            $fallsThrough = true;
            $lexical = [];
            foreach ($node->body as $statement) {
                if (!$fallsThrough) {
                    break;
                }
                [$rewritten, $fallsThrough] = $this->rewrite($statement, $initialized);
                $statements[] = $rewritten;
                if ($statement instanceof VariableDeclaration && $statement->kind !== 'var') {
                    foreach ($statement->declarations as $declaration) {
                        if ($declaration->id instanceof Identifier) {
                            $lexical[$declaration->id->name] = true;
                        }
                    }
                }
            }
            $initialized = array_diff_key($initialized, $lexical);
            return [new BlockStatement($node->location, $statements), $fallsThrough];
        }
        $saved = [$this->types, $this->inputNames, $this->writtenNames, $this->characterRead, $initialized];
        if ($node instanceof IfStatement && $this->expressionType($node->test, 'boolean', $initialized) === 'boolean') {
            $yes = $no = $initialized;
            [$consequent, $yesFallsThrough] = $this->rewrite($node->consequent, $yes);
            [$alternate, $noFallsThrough] = $node->alternate !== null
                ? $this->rewrite($node->alternate, $no) : [null, true];
            $initialized = $yesFallsThrough && $noFallsThrough ? array_intersect_key($yes, $no)
                : ($yesFallsThrough ? $yes : $no);
            return [new IfStatement($node->location, $node->test, $consequent, $alternate), $yesFallsThrough || $noFallsThrough];
        }
        if ($node instanceof VariableDeclaration && in_array($node->kind, ['var', 'let', 'const'], true)) {
            $valid = true;
            foreach ($node->declarations as $declaration) {
                if (!$declaration->id instanceof Identifier || $declaration->init === null) {
                    $valid = false;
                    break;
                }
                $name = $declaration->id->name;
                $type = $this->expressionType($declaration->init, null, $initialized);
                if (!in_array($type, ['number', 'boolean'], true) || !$this->binding($name, $type)) {
                    $valid = false;
                    break;
                }
                $initialized[$name] = true;
                $this->writtenNames[$name] = true;
            }
            if ($valid) {
                return [$node, true];
            }
        }
        if ($node instanceof ExpressionStatement) {
            $expression = $node->expression;
            if ($expression instanceof Literal && is_string($expression->value)) {
                return [new EmptyStatement($node->location), true];
            }
            if ($expression instanceof UpdateExpression && $expression->argument instanceof Identifier) {
                $name = $expression->argument->name;
                if (!$this->isConst($name) && $this->identifierType($name, 'number', $initialized) === 'number') {
                    $this->writtenNames[$name] = true;
                    return [$node, true];
                }
            }
            if ($expression instanceof AssignmentExpression && $expression->left instanceof Identifier) {
                $name = $expression->left->name;
                $type = $this->expressionType($expression->right, null, $initialized);
                if (
                    !$this->isConst($name) && in_array($type, ['number', 'boolean'], true)
                    && ($expression->operator === '=' || ($type === 'number' && in_array($expression->operator, ['+=', '-=', '*='], true)))
                    && $this->identifierType($name, $type, $initialized) === $type
                ) {
                    $this->writtenNames[$name] = true;
                    return [$node, true];
                }
            }
        }
        [$this->types, $this->inputNames, $this->writtenNames, $this->characterRead, $initialized] = $saved;
        $exit = count($this->opaqueStatements);
        $this->opaqueStatements[] = $node;
        return [new ReturnStatement($node->location, new Literal($node->location, $exit, (string) $exit)), false];
    }

    private function isConst(string $name): bool
    {
        return ($this->declarations[$name] ?? null) === 'const'
            || isset($this->slots[$name], $this->constants[$this->slots[$name]]);
    }

    private function binding(string $name, string $type): bool
    {
        if (!isset($this->slots[$name]) || isset($this->captured[$name])) {
            return false;
        }
        if (isset($this->types[$name]) && $this->types[$name] !== $type) {
            return false;
        }
        $this->types[$name] = $type;
        return true;
    }

    /** @param array<string, true> $initialized */
    private function identifierType(string $name, ?string $expected, array $initialized): ?string
    {
        if (isset($this->declarations[$name]) && !isset($initialized[$name])) {
            return null;
        }
        $type = $expected ?? $this->types[$name] ?? 'number';
        if (!$this->binding($name, $type)) {
            return null;
        }
        if (!isset($this->declarations[$name])) {
            $this->inputNames[$name] = true;
        }
        return $type;
    }

    /** @param array<string, true> $initialized */
    private function expressionType(Node $node, ?string $expected, array $initialized): ?string
    {
        $type = null;
        if ($node instanceof Identifier) {
            $type = $this->identifierType($node->name, $expected, $initialized);
        } elseif ($node instanceof Literal) {
            $type = is_bool($node->value) ? 'boolean' : (is_int($node->value) || is_float($node->value) ? 'number' : null);
        } elseif ($node instanceof MemberExpression && $this->stringMember($node, 'length', $initialized)) {
            $type = 'number';
        } elseif (
            $node instanceof CallExpression && !$node->optional && $node->callee instanceof MemberExpression
            && $this->stringMember($node->callee, 'charCodeAt', $initialized) && count($node->arguments) === 1
            && $this->expressionType($node->arguments[0], 'number', $initialized) === 'number'
        ) {
            $this->characterRead = true;
            $type = 'number';
        } elseif ($node instanceof UnaryExpression && $node->prefix) {
            $argumentType = $node->operator === '!' ? 'boolean' : (in_array($node->operator, ['+', '-'], true) ? 'number' : null);
            if ($argumentType !== null && $this->expressionType($node->argument, $argumentType, $initialized) === $argumentType) {
                $type = $argumentType;
            }
        } elseif ($node instanceof BinaryExpression) {
            $equality = in_array($node->operator, ['===', '!==', '==', '!='], true);
            $operandType = $equality && ($this->knownBoolean($node->left) || $this->knownBoolean($node->right)) ? 'boolean' : 'number';
            if (
                ($equality || in_array($node->operator, ['+', '-', '*', '/', '%', '<', '>', '<=', '>='], true))
                && $this->expressionType($node->left, $operandType, $initialized) === $operandType
                && $this->expressionType($node->right, $operandType, $initialized) === $operandType
            ) {
                $type = in_array($node->operator, ['+', '-', '*', '/', '%'], true) ? 'number' : 'boolean';
            }
        } elseif (
            $node instanceof LogicalExpression && in_array($node->operator, ['&&', '||'], true)
            && $this->expressionType($node->left, 'boolean', $initialized) === 'boolean'
            && $this->expressionType($node->right, 'boolean', $initialized) === 'boolean'
        ) {
            $type = 'boolean';
        } elseif ($node instanceof ConditionalExpression && $this->expressionType($node->test, 'boolean', $initialized) === 'boolean') {
            $yes = $this->expressionType($node->consequent, $expected, $initialized);
            if ($yes !== null && $this->expressionType($node->alternate, $yes, $initialized) === $yes) {
                $type = $yes;
            }
        }
        return $expected === null || $type === $expected ? $type : null;
    }

    private function recheckExpressions(Node $node): bool
    {
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $statement) {
                if (!$this->recheckExpressions($statement)) {
                    return false;
                }
            }
            return true;
        }
        if ($node instanceof IfStatement) {
            return $this->knownExpressionType($node->test) === 'boolean'
                && $this->recheckExpressions($node->consequent)
                && ($node->alternate === null || $this->recheckExpressions($node->alternate));
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $declaration) {
                if ($declaration->init === null || $this->knownExpressionType($declaration->init) === null) {
                    return false;
                }
            }
            return true;
        }
        if ($node instanceof ExpressionStatement) {
            return $node->expression instanceof UpdateExpression
                || ($node->expression instanceof AssignmentExpression && $this->knownExpressionType($node->expression->right) !== null);
        }
        return $node instanceof EmptyStatement || ($node instanceof ReturnStatement && $node->argument instanceof Literal);
    }

    private function knownExpressionType(Node $node): ?string
    {
        if ($node instanceof Identifier) {
            $type = $this->types[$node->name] ?? null;
            return $type === 'number' || $type === 'boolean' ? $type : null;
        }
        if ($node instanceof Literal) {
            return is_bool($node->value) ? 'boolean' : (is_int($node->value) || is_float($node->value) ? 'number' : null);
        }
        if ($node instanceof MemberExpression) {
            return $this->knownStringMember($node, 'length') ? 'number' : null;
        }
        if (
            $node instanceof CallExpression && !$node->optional && $node->callee instanceof MemberExpression
            && $this->knownStringMember($node->callee, 'charCodeAt') && count($node->arguments) === 1
            && $this->knownExpressionType($node->arguments[0]) === 'number'
        ) {
            return 'number';
        }
        return StringScannerPlan::scalarExpressionType($node, fn (Node $child): ?string => $this->knownExpressionType($child));
    }

    private function knownStringMember(MemberExpression $node, string $name): bool
    {
        return !$node->computed && !$node->optional && $node->object instanceof Identifier
            && ($this->types[$node->object->name] ?? null) === 'string'
            && $node->property instanceof Identifier && $node->property->name === $name;
    }

    private function knownBoolean(Node $node): bool
    {
        return ($node instanceof Literal && is_bool($node->value))
            || ($node instanceof Identifier && ($this->types[$node->name] ?? null) === 'boolean');
    }

    /** @param array<string, true> $initialized */
    private function stringMember(MemberExpression $node, string $name, array $initialized): bool
    {
        if (
            $node->computed || $node->optional || !$node->object instanceof Identifier
            || !$node->property instanceof Identifier || $node->property->name !== $name
        ) {
            return false;
        }
        return $this->identifierType($node->object->name, 'string', $initialized) === 'string';
    }
}
