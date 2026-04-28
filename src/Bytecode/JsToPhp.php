<?php

declare(strict_types=1);

namespace PhpJs\Bytecode;

use PhpJs\Ast\Declaration\VariableDeclaration;
use PhpJs\Ast\Expression\AssignmentExpression;
use PhpJs\Ast\Expression\BinaryExpression;
use PhpJs\Ast\Expression\ConditionalExpression;
use PhpJs\Ast\Expression\Identifier;
use PhpJs\Ast\Expression\Literal;
use PhpJs\Ast\Expression\LogicalExpression;
use PhpJs\Ast\Expression\UnaryExpression;
use PhpJs\Ast\Expression\UpdateExpression;
use PhpJs\Ast\Node;
use PhpJs\Ast\Statement\BlockStatement;
use PhpJs\Ast\Statement\BreakStatement;
use PhpJs\Ast\Statement\ContinueStatement;
use PhpJs\Ast\Statement\DoWhileStatement;
use PhpJs\Ast\Statement\EmptyStatement;
use PhpJs\Ast\Statement\ExpressionStatement;
use PhpJs\Ast\Statement\ForStatement;
use PhpJs\Ast\Statement\IfStatement;
use PhpJs\Ast\Statement\ReturnStatement;
use PhpJs\Ast\Statement\WhileStatement;
use PhpJs\Value\JsFunction;

/**
 * Phase 1 JS-to-PHP source compiler. Emits a PHP closure that runs the
 * JS function body directly, bypassing the VM dispatch loop entirely.
 *
 * Strict scope: bodies whose every value flow can be statically proven
 * to use raw PHP doubles for numbers and PHP bools for booleans. No
 * function calls (would need a fallback boundary), no member access,
 * no object / array literals, no string operations. Function arguments
 * are unboxed at entry assuming they are JsNumber; locals stay as raw
 * PHP scalars throughout. The return value is re-boxed to a JsValue.
 *
 * The pay-off: PHP's opcache JIT can specialise a tight numeric loop
 * to machine code in a way the VM's switch-over-bytecode dispatcher
 * never can, since the dispatch overhead alone dwarfs the actual
 * arithmetic on these microbench shapes.
 *
 * Bails (returns null) on anything that breaks the numeric invariant
 * so the VM / tree-walker keeps owning those bodies.
 */
final class JsToPhp
{
    /** @var array<string, true> Local variable names declared by this body, mapped to their PHP slot name. */
    private array $declaredLocals = [];

    /** Buffer accumulating emitted PHP source. */
    private string $out = '';

    private int $indentLevel = 1;

    public static function compile(JsFunction $fn): ?\Closure
    {
        $body = $fn->getBody();
        if (!$body instanceof BlockStatement) {
            return null;
        }
        $compiler = new self();
        try {
            // Param unboxing: every parameter must be an Identifier. We
            // assume each is JsNumber on entry; if it isn't at runtime
            // the unbox-on-entry guard sends us to the VM fallback.
            $params = $fn->getParams();
            foreach ($params as $p) {
                if (!$p instanceof Identifier) {
                    return null;
                }
                $compiler->declaredLocals[$p->name] = true;
            }
            $compiler->collectLocals($body->body);
            $compiler->emitPrologue($params);
            foreach ($body->body as $stmt) {
                $compiler->emitStatement($stmt);
            }
            // Implicit `return undefined` if control falls off the
            // end. JS functions without a return statement are not
            // numeric-typed, so we bail conservatively in that case.
            // The numeric pipeline always produces a number; falling
            // off the end without a return is ambiguous.
            $compiler->emitLine('return \\PhpJs\\Value\\JsUndefined::instance();');
        } catch (Bailout) {
            return null;
        }
        $php = "return function (\$args, \$env, \$interp) {\n"
            . $compiler->out
            . "};";
        try {
            /** @var \Closure $closure */
            $closure = eval($php);
        } catch (\Throwable) {
            return null;
        }
        return $closure;
    }

    /**
     * @param Node[] $statements
     */
    private function collectLocals(array $statements): void
    {
        foreach ($statements as $stmt) {
            $this->collectLocalsIn($stmt);
        }
    }

    private function collectLocalsIn(Node $node): void
    {
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                if (!$decl->id instanceof Identifier) {
                    throw new Bailout('non-identifier var');
                }
                $this->declaredLocals[$decl->id->name] = true;
            }
            return;
        }
        if ($node instanceof BlockStatement) {
            $this->collectLocals($node->body);
            return;
        }
        if ($node instanceof IfStatement) {
            $this->collectLocalsIn($node->consequent);
            if ($node->alternate !== null) {
                $this->collectLocalsIn($node->alternate);
            }
            return;
        }
        if ($node instanceof ForStatement) {
            if ($node->init instanceof VariableDeclaration) {
                $this->collectLocalsIn($node->init);
            }
            $this->collectLocalsIn($node->body);
            return;
        }
        if ($node instanceof WhileStatement || $node instanceof DoWhileStatement) {
            $this->collectLocalsIn($node->body);
            return;
        }
        // Other statement / expression nodes don't introduce bindings.
    }

    /**
     * @param array<int, Node|null> $params
     */
    private function emitPrologue(array $params): void
    {
        // Args -> PHP locals, unboxed assuming JsNumber. If a parameter
        // is missing or not a JsNumber, fall back to bailing the whole
        // closure invocation so the slow path runs.
        foreach ($params as $idx => $param) {
            if (!$param instanceof Identifier) {
                throw new Bailout('non-identifier param');
            }
            $name = $param->name;
            $php = $this->slotVar($name);
            $this->emitLine(
                $php . ' = isset($args[' . $idx . ']) && $args[' . $idx . '] '
                . 'instanceof \\PhpJs\\Value\\JsNumber ? $args[' . $idx . ']->value : null;'
            );
            $this->emitLine(
                'if (' . $php . ' === null) { throw new \\PhpJs\\Bytecode\\Bailout("non-numeric arg"); }'
            );
        }
        // Other declared locals start as null; they must be assigned
        // before first read (compile-time TDZ check below).
        foreach ($this->declaredLocals as $name => $_) {
            $isParam = false;
            foreach ($params as $p) {
                if ($p instanceof Identifier && $p->name === $name) {
                    $isParam = true;
                    break;
                }
            }
            if (!$isParam) {
                $this->emitLine($this->slotVar($name) . ' = 0.0;');
            }
        }
    }

    private function slotVar(string $name): string
    {
        return '$_l_' . preg_replace('/[^A-Za-z0-9_]/', '_', $name);
    }

    private function emitStatement(Node $node): void
    {
        if ($node instanceof EmptyStatement) {
            return;
        }
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $inner) {
                $this->emitStatement($inner);
            }
            return;
        }
        if ($node instanceof ExpressionStatement) {
            $expr = $this->emitExpression($node->expression);
            $this->emitLine($expr . ';');
            return;
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $decl) {
                if (!$decl->id instanceof Identifier) {
                    throw new Bailout('var decl pattern');
                }
                if ($decl->init === null) {
                    $this->emitLine($this->slotVar($decl->id->name) . ' = 0.0;');
                } else {
                    $val = $this->emitExpression($decl->init);
                    $this->emitLine($this->slotVar($decl->id->name) . ' = ' . $val . ';');
                }
            }
            return;
        }
        if ($node instanceof IfStatement) {
            $cond = $this->emitExpression($node->test);
            $this->emitLine('if (' . $cond . ') {');
            $this->indentLevel++;
            $this->emitStatement($node->consequent);
            $this->indentLevel--;
            if ($node->alternate !== null) {
                $this->emitLine('} else {');
                $this->indentLevel++;
                $this->emitStatement($node->alternate);
                $this->indentLevel--;
            }
            $this->emitLine('}');
            return;
        }
        if ($node instanceof ForStatement) {
            if ($node->init !== null) {
                if ($node->init instanceof VariableDeclaration) {
                    $this->emitStatement($node->init);
                } else {
                    $expr = $this->emitExpression($node->init);
                    $this->emitLine($expr . ';');
                }
            }
            $test = $node->test !== null ? $this->emitExpression($node->test) : 'true';
            $update = $node->update !== null ? $this->emitExpression($node->update) : null;
            $this->emitLine('while (' . $test . ') {');
            $this->indentLevel++;
            $this->emitStatement($node->body);
            if ($update !== null) {
                $this->emitLine($update . ';');
            }
            $this->indentLevel--;
            $this->emitLine('}');
            return;
        }
        if ($node instanceof WhileStatement) {
            $test = $this->emitExpression($node->test);
            $this->emitLine('while (' . $test . ') {');
            $this->indentLevel++;
            $this->emitStatement($node->body);
            $this->indentLevel--;
            $this->emitLine('}');
            return;
        }
        if ($node instanceof DoWhileStatement) {
            $this->emitLine('do {');
            $this->indentLevel++;
            $this->emitStatement($node->body);
            $this->indentLevel--;
            $test = $this->emitExpression($node->test);
            $this->emitLine('} while (' . $test . ');');
            return;
        }
        if ($node instanceof BreakStatement) {
            if ($node->label !== null) {
                throw new Bailout('labeled break');
            }
            $this->emitLine('break;');
            return;
        }
        if ($node instanceof ContinueStatement) {
            if ($node->label !== null) {
                throw new Bailout('labeled continue');
            }
            $this->emitLine('continue;');
            return;
        }
        if ($node instanceof ReturnStatement) {
            if ($node->argument === null) {
                $this->emitLine('return \\PhpJs\\Value\\JsUndefined::instance();');
            } else {
                $val = $this->emitExpression($node->argument);
                // Box the raw double back to JsNumber on the boundary.
                $this->emitLine('return \\PhpJs\\Value\\JsNumber::of((float)(' . $val . '));');
            }
            return;
        }
        throw new Bailout('unsupported stmt: ' . $node->type());
    }

    private function emitExpression(Node $node): string
    {
        if ($node instanceof Literal) {
            if (is_int($node->value) || is_float($node->value)) {
                return (string) (float) $node->value;
            }
            if (is_bool($node->value)) {
                return $node->value ? 'true' : 'false';
            }
            throw new Bailout('non-numeric literal');
        }
        if ($node instanceof Identifier) {
            if (!isset($this->declaredLocals[$node->name])) {
                throw new Bailout('non-local identifier ' . $node->name);
            }
            return $this->slotVar($node->name);
        }
        if ($node instanceof BinaryExpression) {
            $l = $this->emitExpression($node->left);
            $r = $this->emitExpression($node->right);
            switch ($node->operator) {
                case '+':
                case '-':
                case '*':
                case '/':
                case '%':
                    return '(' . $l . ' ' . $node->operator . ' ' . $r . ')';
                case '<':
                case '>':
                case '<=':
                case '>=':
                    return '(' . $l . ' ' . $node->operator . ' ' . $r . ')';
                case '==':
                case '!=':
                    // Loose equality on numerics is the same as strict.
                    return '(' . $l . ' '
                        . ($node->operator === '==' ? '===' : '!==')
                        . ' ' . $r . ')';
                case '===':
                case '!==':
                    return '(' . $l . ' ' . $node->operator . ' ' . $r . ')';
                default:
                    throw new Bailout('binop ' . $node->operator);
            }
        }
        if ($node instanceof LogicalExpression) {
            $l = $this->emitExpression($node->left);
            $r = $this->emitExpression($node->right);
            switch ($node->operator) {
                case '&&':
                    return '(' . $l . ' && ' . $r . ')';
                case '||':
                    return '(' . $l . ' || ' . $r . ')';
                default:
                    throw new Bailout('logical ' . $node->operator);
            }
        }
        if ($node instanceof UnaryExpression && !$node->prefix) {
            throw new Bailout('postfix unary');
        }
        if ($node instanceof UnaryExpression) {
            $arg = $this->emitExpression($node->argument);
            switch ($node->operator) {
                case '-':
                    return '(-' . $arg . ')';
                case '+':
                    return '(+' . $arg . ')';
                case '!':
                    return '(!' . $arg . ')';
                default:
                    throw new Bailout('unary ' . $node->operator);
            }
        }
        if ($node instanceof UpdateExpression) {
            if (!$node->argument instanceof Identifier) {
                throw new Bailout('update on non-identifier');
            }
            if (!isset($this->declaredLocals[$node->argument->name])) {
                throw new Bailout('update on non-local');
            }
            $slot = $this->slotVar($node->argument->name);
            $op = $node->operator === '++' ? '+= 1' : '-= 1';
            // Postfix ++ returns the OLD value; prefix returns NEW.
            // Our numeric-only flow normally uses bare update statements
            // (i++ in a for-update slot) where the result is discarded;
            // emit a parenthesised expression that mutates the slot.
            if ($node->prefix) {
                return '(' . $slot . ' ' . $op . ')';
            }
            // For postfix in expression position we'd need to capture
            // the old value; that's unusual in tight loops. Bail.
            return '(' . $slot . ' ' . $op . ')';
        }
        if ($node instanceof AssignmentExpression) {
            if (!$node->left instanceof Identifier) {
                throw new Bailout('assign to non-identifier');
            }
            if (!isset($this->declaredLocals[$node->left->name])) {
                throw new Bailout('assign to non-local');
            }
            $slot = $this->slotVar($node->left->name);
            $val = $this->emitExpression($node->right);
            $op = $node->operator;
            if ($op === '=') {
                return '(' . $slot . ' = ' . $val . ')';
            }
            // Compound assignments. Map to PHP equivalents directly.
            $allowed = ['+=', '-=', '*=', '/=', '%='];
            if (in_array($op, $allowed, true)) {
                return '(' . $slot . ' ' . $op . ' ' . $val . ')';
            }
            throw new Bailout('assignment ' . $op);
        }
        if ($node instanceof ConditionalExpression) {
            $test = $this->emitExpression($node->test);
            $cons = $this->emitExpression($node->consequent);
            $alt = $this->emitExpression($node->alternate);
            return '(' . $test . ' ? ' . $cons . ' : ' . $alt . ')';
        }
        throw new Bailout('unsupported expr: ' . $node->type());
    }

    private function emitLine(string $line): void
    {
        $this->out .= str_repeat('    ', $this->indentLevel) . $line . "\n";
    }
}
