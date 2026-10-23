<?php

declare(strict_types=1);

namespace Phasis\Parser;

use Phasis\Ast\Declaration\ClassDeclaration;
use Phasis\Ast\Declaration\ExportDeclaration;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\ImportDeclaration;
use Phasis\Ast\Declaration\VariableDeclaration;
use Phasis\Ast\Expression\ArrowFunction;
use Phasis\Ast\Expression\ClassExpression;
use Phasis\Ast\Expression\ClassMethod;
use Phasis\Ast\Expression\ClassProperty;
use Phasis\Ast\Expression\FunctionExpression;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\PrivateIdentifier;
use Phasis\Ast\Expression\Property;
use Phasis\Ast\Expression\StaticBlock;
use Phasis\Ast\Node;
use Phasis\Ast\Pattern\ArrayPattern;
use Phasis\Ast\Pattern\AssignmentPattern;
use Phasis\Ast\Pattern\AssignmentProperty;
use Phasis\Ast\Pattern\ObjectPattern;
use Phasis\Ast\Pattern\RestElement;
use Phasis\Ast\Program;
use Phasis\Ast\Statement\ReturnStatement;
use Phasis\Exceptions\SyntaxError;

/** Static semantics that require the complete ScriptBody or ModuleBody. */
final class ProgramEarlyErrors
{
    /** Validate declarations and references before executing or linking a program. */
    public static function validate(Program $program, bool $module = false, bool $directEval = false, bool $fragment = false): void
    {
        self::validateBindings($program, $module, $fragment);
        // Direct eval inherits private names, super, and new.target from its
        // caller. PerformEval validates those references with that environment.
        if (!$directEval) {
            self::validateReferences($program, [], true, $fragment);
        }
    }

    private static function validateBindings(Program $program, bool $module, bool $fragment): void
    {
        $lexical = [];
        $vars = [];
        $exported = [];
        $exportedBindings = [];
        foreach ($program->body as $statement) {
            self::collectVarNames($statement, $vars);
            if ($statement instanceof ExportDeclaration) {
                if ($statement->isDefault) {
                    self::addUniqueName($exported, 'default', 'Duplicate export');
                } else {
                    foreach ($statement->specifiers as $specifier) {
                        self::addUniqueName($exported, $specifier->exported ?? $specifier->local, 'Duplicate export');
                        if ($statement->source === null) {
                            $exportedBindings[$specifier->local] = true;
                        }
                    }
                    if ($statement->isAll && $statement->allAs !== null) {
                        self::addUniqueName($exported, $statement->allAs, 'Duplicate export');
                    }
                    if ($statement->declaration !== null) {
                        foreach (self::declarationNames($statement->declaration) as $name) {
                            self::addUniqueName($exported, $name, 'Duplicate export');
                        }
                    }
                }
            }

            $declaration = $statement instanceof ExportDeclaration ? $statement->declaration : $statement;
            if ($declaration === null || ($declaration instanceof VariableDeclaration && $declaration->kind === 'var')) {
                continue;
            }
            foreach (self::declarationNames($declaration) as $name) {
                if (!$module && $declaration instanceof FunctionDeclaration) {
                    $vars[$name] = true;
                } else {
                    self::addUniqueName($lexical, $name, 'Duplicate declaration');
                }
            }
        }
        foreach ($lexical as $name => $_) {
            if (isset($vars[$name])) {
                throw new SyntaxError("Identifier '{$name}' has already been declared");
            }
        }
        foreach ($exportedBindings as $name => $_) {
            if (!$fragment && !isset($lexical[$name]) && !isset($vars[$name])) {
                throw new SyntaxError("Export of unknown binding '{$name}'");
            }
        }
    }

    /** @param array<string, true> $names */
    private static function addUniqueName(array &$names, string $name, string $message): void
    {
        if (isset($names[$name])) {
            throw new SyntaxError("{$message} '{$name}'");
        }
        $names[$name] = true;
    }

    /** @return list<string> */
    private static function declarationNames(Node $declaration): array
    {
        if ($declaration instanceof ImportDeclaration) {
            return array_map(static fn($specifier): string => $specifier->local, $declaration->specifiers);
        }
        if ($declaration instanceof FunctionDeclaration || $declaration instanceof ClassDeclaration) {
            return $declaration->id === null ? [] : [$declaration->id->name];
        }
        $names = [];
        if ($declaration instanceof VariableDeclaration) {
            foreach ($declaration->declarations as $declarator) {
                array_push($names, ...self::boundNames($declarator->id));
            }
        }
        return $names;
    }

    /** @return list<string> */
    private static function boundNames(Node $pattern): array
    {
        if ($pattern instanceof Identifier) {
            return [$pattern->name];
        }
        if ($pattern instanceof AssignmentPattern) {
            return self::boundNames($pattern->left);
        }
        if ($pattern instanceof RestElement) {
            return self::boundNames($pattern->argument);
        }
        if ($pattern instanceof AssignmentProperty) {
            return self::boundNames($pattern->value);
        }
        $names = [];
        $elements = match (true) {
            $pattern instanceof ArrayPattern => $pattern->elements,
            $pattern instanceof ObjectPattern => $pattern->properties,
            default => [],
        };
        foreach ($elements as $element) {
            if ($element !== null) {
                array_push($names, ...self::boundNames($element));
            }
        }
        return $names;
    }

    /** @param array<string, true> $names */
    private static function collectVarNames(Node $node, array &$names): void
    {
        if (
            $node instanceof FunctionDeclaration || $node instanceof FunctionExpression
            || $node instanceof ArrowFunction || $node instanceof ClassDeclaration || $node instanceof ClassExpression
        ) {
            return;
        }
        if ($node instanceof VariableDeclaration) {
            if ($node->kind === 'var') {
                foreach (self::declarationNames($node) as $name) {
                    $names[$name] = true;
                }
            }
            return;
        }
        foreach (self::children($node) as $child) {
            self::collectVarNames($child, $names);
        }
    }

    /** @param array<string, true> $privateNames */
    private static function validateReferences(Node $node, array $privateNames, bool $globalContext, bool $allowReturn): void
    {
        if ($node instanceof PrivateIdentifier && !isset($privateNames[ltrim($node->name, '#')])) {
            throw new SyntaxError("Private field '{$node->name}' must be declared in an enclosing class", $node->location);
        }
        if ($node instanceof Identifier && $globalContext) {
            if ($node->name === '[[NewTarget]]') {
                throw new SyntaxError('new.target expression is not allowed here', $node->location);
            }
            if ($node->name === 'super') {
                throw new SyntaxError("'super' keyword unexpected here", $node->location);
            }
        }
        if ($node instanceof ReturnStatement && !$allowReturn) {
            throw new SyntaxError('Illegal return statement', $node->location);
        }
        if ($node instanceof FunctionDeclaration || $node instanceof FunctionExpression) {
            $globalContext = false;
            $allowReturn = true;
        } elseif ($node instanceof ArrowFunction) {
            $allowReturn = true;
        }
        if ($node instanceof ClassDeclaration || $node instanceof ClassExpression) {
            if ($node->superClass !== null) {
                self::validateReferences($node->superClass, $privateNames, $globalContext, $allowReturn);
            }
            foreach ($node->decorators as $decorator) {
                self::validateReferences($decorator, $privateNames, $globalContext, $allowReturn);
            }
            foreach ($node->body as $element) {
                if (($element instanceof ClassMethod || $element instanceof ClassProperty) && $element->key instanceof PrivateIdentifier) {
                    $privateNames[ltrim($element->key->name, '#')] = true;
                }
            }
            foreach ($node->body as $element) {
                if ($element instanceof StaticBlock) {
                    self::validateReferences($element->body, $privateNames, false, false);
                } elseif ($element instanceof ClassMethod || $element instanceof ClassProperty) {
                    if ($element->computed) {
                        self::validateReferences($element->key, $privateNames, $globalContext, $allowReturn);
                    }
                    foreach ($element->decorators as $decorator) {
                        self::validateReferences($decorator, $privateNames, $globalContext, $allowReturn);
                    }
                    if ($element->value !== null) {
                        self::validateReferences($element->value, $privateNames, false, false);
                    }
                }
            }
            return;
        }
        // IdentifierName property keys are not references, while computed keys
        // and private member accesses still participate in static validation.
        if ($node instanceof Property || $node instanceof MemberExpression) {
            $key = $node instanceof Property ? $node->key : $node->property;
            if ($node->computed || $key instanceof PrivateIdentifier) {
                self::validateReferences($key, $privateNames, $globalContext, $allowReturn);
            }
            $value = $node instanceof Property ? $node->value : $node->object;
            self::validateReferences($value, $privateNames, $globalContext, $allowReturn);
            return;
        }
        foreach (self::children($node) as $child) {
            self::validateReferences($child, $privateNames, $globalContext, $allowReturn);
        }
    }

    /** @return iterable<Node> */
    private static function children(Node $node): iterable
    {
        foreach (get_object_vars($node) as $value) {
            if ($value instanceof Node) {
                yield $value;
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof Node) {
                        yield $item;
                    }
                }
            }
        }
    }
}
