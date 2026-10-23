<?php

declare(strict_types=1);

namespace Phasis\Bytecode\Parts;

use Phasis\Ast\Declaration\ClassDeclaration;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Expression\ArrowFunction;
use Phasis\Ast\Expression\ClassExpression;
use Phasis\Ast\Expression\FunctionExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\Property;
use Phasis\Ast\Node;
use Phasis\Ast\Statement\BlockStatement;

trait CompilerBlockBindings
{
    /**
     * Uncaptured bindings of one declaration kind may share a slot when their
     * blocks never overlap and every reference follows its own initialization. Keep all
     * shadowing, captures, pattern bindings and ambiguous lifetimes on the
     * interpreter path, which owns the general lexical-environment semantics.
     */
    private function collectReusableBlockBindings(BlockStatement $body): void
    {
        $declarations = [];
        $declarationKinds = [];
        $disallowed = array_fill_keys(array_keys($this->localSlots), true);
        $this->collectBlockBindingDeclarations($body, null, $body, $declarations, $declarationKinds, $disallowed);
        foreach ($declarations as $name => $blocks) {
            if (isset($disallowed[$name]) || count($blocks) < 2 || count(array_unique($blocks)) !== count($blocks)) {
                continue;
            }
            $initialized = false;
            if ($this->blockBindingLifetimeIsIndependent($body, $name, array_fill_keys($blocks, true), null, $initialized)) {
                $this->reusableBlockLocals[$name] = true;
            }
        }
    }

    /**
     * @param array<string, list<int>> $declarations
     * @param array<string, string> $declarationKinds
     * @param array<string, true> $disallowed
     */
    private function collectBlockBindingDeclarations(
        Node $node,
        ?BlockStatement $directBlock,
        BlockStatement $functionBody,
        array &$declarations,
        array &$declarationKinds,
        array &$disallowed,
    ): void {
        if ($node instanceof FunctionDeclaration || $node instanceof ClassDeclaration) {
            if ($node->id !== null) {
                $disallowed[$node->id->name] = true;
            }
            return;
        }
        if ($node instanceof FunctionExpression || $node instanceof ArrowFunction || $node instanceof ClassExpression) {
            return;
        }
        if ($node instanceof BlockStatement) {
            foreach ($node->body as $statement) {
                $this->collectBlockBindingDeclarations(
                    $statement,
                    $node,
                    $functionBody,
                    $declarations,
                    $declarationKinds,
                    $disallowed,
                );
            }
            return;
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $declaration) {
                $names = [];
                $this->collectPatternBoundNames($declaration->id, $names);
                foreach ($names as $name => $_) {
                    if (
                        ($node->kind === 'let' || $node->kind === 'const') && $declaration->id instanceof Identifier
                        && $directBlock !== null && $directBlock !== $functionBody
                    ) {
                        // Readonly state is tracked per slot. Mixing let and
                        // const would require that state to follow each scope.
                        if (isset($declarationKinds[$name]) && $declarationKinds[$name] !== $node->kind) {
                            $disallowed[$name] = true;
                        }
                        $declarationKinds[$name] ??= $node->kind;
                        $declarations[$name][] = spl_object_id($directBlock);
                    } else {
                        $disallowed[$name] = true;
                    }
                }
            }
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->collectBlockBindingDeclarations(
                        $child,
                        null,
                        $functionBody,
                        $declarations,
                        $declarationKinds,
                        $disallowed,
                    );
                }
            }
        }
    }

    /** @param array<int, true> $blocks */
    private function blockBindingLifetimeIsIndependent(
        Node $node,
        string $name,
        array $blocks,
        ?int $activeBlock,
        bool &$initialized,
    ): bool {
        if ($node instanceof Identifier) {
            return $node->name !== $name || ($activeBlock !== null && $initialized);
        }
        if ($node instanceof BlockStatement && isset($blocks[spl_object_id($node)])) {
            if ($activeBlock !== null) {
                return false;
            }
            $activeBlock = spl_object_id($node);
            $blockInitialized = false;
            foreach ($node->body as $statement) {
                if (!$this->blockBindingLifetimeIsIndependent($statement, $name, $blocks, $activeBlock, $blockInitialized)) {
                    return false;
                }
            }
            return true;
        }
        if (
            $node instanceof FunctionDeclaration || $node instanceof FunctionExpression || $node instanceof ArrowFunction
            || $node instanceof ClassDeclaration || $node instanceof ClassExpression
        ) {
            // Even a shadowed use is conservatively excluded here. A fresh
            // environment remains necessary for any possible closure capture.
            return !$this->blockBindingNameOccurs($node, $name);
        }
        if ($node instanceof VariableDeclaration) {
            foreach ($node->declarations as $declaration) {
                if (
                    $declaration->init !== null
                    && !$this->blockBindingLifetimeIsIndependent($declaration->init, $name, $blocks, $activeBlock, $initialized)
                ) {
                    return false;
                }
                if ($declaration->id instanceof Identifier) {
                    if ($declaration->id->name === $name) {
                        if ($activeBlock === null || $initialized) {
                            return false;
                        }
                        $initialized = true;
                    }
                } elseif ($this->blockBindingNameOccurs($declaration->id, $name)) {
                    return false;
                }
            }
            return true;
        }
        if ($node instanceof MemberExpression && !$node->computed) {
            return $this->blockBindingLifetimeIsIndependent($node->object, $name, $blocks, $activeBlock, $initialized);
        }
        if ($node instanceof Property && !$node->computed) {
            return $this->blockBindingLifetimeIsIndependent($node->value, $name, $blocks, $activeBlock, $initialized);
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if (
                    $child instanceof Node
                    && !$this->blockBindingLifetimeIsIndependent($child, $name, $blocks, $activeBlock, $initialized)
                ) {
                    return false;
                }
            }
        }
        return true;
    }

    private function blockBindingNameOccurs(Node $node, string $name): bool
    {
        if ($node instanceof Identifier && $node->name === $name) {
            return true;
        }
        foreach (get_object_vars($node) as $value) {
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node && $this->blockBindingNameOccurs($child, $name)) {
                    return true;
                }
            }
        }
        return false;
    }
}
