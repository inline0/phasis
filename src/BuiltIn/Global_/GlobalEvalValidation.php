<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Global_;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Exceptions\SyntaxError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Runtime\Interpreter;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * GlobalObject trait part: GlobalEvalValidation. Composed into GlobalObject
 * via `use Global_\GlobalEvalValidation;`.
 */
trait GlobalEvalValidation
{
    /**
     * Validate a dynamically created function (via Function constructor).
     *
     * Per spec 20.2.1.1 step 20, if the body contains "use strict":
     * - The body must not contain a WithStatement.
     * - Parameters must not have duplicate names.
     *
     * @throws \Phasis\Exceptions\SyntaxError
     */
    /** Public wrapper for Engine.php to reach rejectYieldAwaitInParams. */
    public static function rejectYieldAwaitInParamsPublic(\Phasis\Ast\Program $program): void
    {
        self::rejectYieldAwaitInParams($program);
    }

    /** Public wrapper for Engine.php to reach rejectPrivateIdentifiersInProgram. */
    public static function rejectPrivateIdentifiersInProgramPublic(\Phasis\Ast\Program $program): void
    {
        self::rejectPrivateIdentifiersInProgram($program);
    }

    /**
     * Validate AllPrivateIdentifiersValid for a Program: every PrivateName
     * reference must be lexically enclosed by a class that declares (in
     * itself or an enclosing class) that private name.
     */
    private static function rejectPrivateIdentifiersInProgram(\Phasis\Ast\Program $program): void
    {
        $stack = [];
        self::validatePrivateIdentifiersInNode($program, $stack);
    }

    /**
     * @param array<int,array<string,bool>> $stack
     */
    private static function validatePrivateIdentifiersInNode(?\Phasis\Ast\Node $node, array $stack): void
    {
        if ($node === null) {
            return;
        }
        if ($node instanceof \Phasis\Ast\Expression\PrivateIdentifier) {
            // Top-level (outside any class) reference is always invalid.
            if (empty($stack)) {
                throw new \Phasis\Exceptions\SyntaxError(
                    'Private identifiers are not allowed outside of a class body'
                );
            }
            // Look up the chain of enclosing class private-name sets.
            $name = ltrim($node->name, '#');
            foreach (array_reverse($stack) as $names) {
                if (isset($names[$name])) {
                    return;
                }
            }
            throw new \Phasis\Exceptions\SyntaxError(
                "Private field '#{$name}' must be declared in an enclosing class"
            );
        }
        if (
            $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
        ) {
            $declared = self::collectDeclaredPrivateNames($node);
            $stack[] = $declared;
            // Walk superclass with parent stack (it is parsed outside this
            // class body's scope).
            if ($node->superClass !== null) {
                self::validatePrivateIdentifiersInNode($node->superClass, array_slice($stack, 0, -1));
            }
            // Walk class body with this class's names visible.
            foreach ($node->body as $element) {
                self::validatePrivateIdentifiersInNode($element, $stack);
            }
            return;
        }
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof \Phasis\Ast\Node) {
                self::validatePrivateIdentifiersInNode($value, $stack);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof \Phasis\Ast\Node) {
                        self::validatePrivateIdentifiersInNode($item, $stack);
                    }
                }
            }
        }
    }

    /**
     * Collect all PrivateName names declared as elements of the given
     * class body (own scope only — does not descend into nested classes).
     *
     * @return array<string,bool>
     */
    private static function collectDeclaredPrivateNames(\Phasis\Ast\Node $classNode): array
    {
        $names = [];
        $body = $classNode->body ?? [];
        foreach ($body as $element) {
            // MethodDefinition / FieldDefinition / etc. carry a `key` field
            // that can be a PrivateIdentifier when the member is private.
            if (isset($element->key) && $element->key instanceof \Phasis\Ast\Expression\PrivateIdentifier) {
                $names[ltrim($element->key->name, '#')] = true;
            }
        }
        return $names;
    }

    /**
     * Walk the formal parameters of a parsed dynamic-function Program looking
     * for YieldExpression or AwaitExpression nodes. The dynamic
     * AsyncGeneratorFunction and GeneratorFunction constructors must reject
     * such expressions in their parameter lists per spec CreateDynamicFunction
     * step 28/29.
     */
    private static function rejectYieldAwaitInParams(\Phasis\Ast\Program $program): void
    {
        foreach ($program->body as $stmt) {
            if (
                !($stmt instanceof \Phasis\Ast\Statement\ExpressionStatement)
                || !($stmt->expression instanceof \Phasis\Ast\Expression\FunctionExpression)
            ) {
                continue;
            }
            foreach ($stmt->expression->params as $param) {
                if (self::nodeContainsYieldOrAwait($param)) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        'YieldExpression or AwaitExpression not permitted in parameters'
                    );
                }
            }
            return;
        }
    }

    private static function nodeContainsYieldOrAwait(?\Phasis\Ast\Node $node): bool
    {
        if ($node === null) {
            return false;
        }
        if (
            $node instanceof \Phasis\Ast\Expression\YieldExpression
            || $node instanceof \Phasis\Ast\Expression\AwaitExpression
        ) {
            return true;
        }
        // Stop descending into nested functions/classes; their own params are
        // their own scope.
        if (
            $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Declaration\FunctionDeclaration
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
            || $node instanceof \Phasis\Ast\Declaration\ClassDeclaration
        ) {
            return false;
        }
        // Walk public properties of the node looking for Node children/arrays.
        $reflection = new \ReflectionObject($node);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $value = $prop->getValue($node);
            if ($value instanceof \Phasis\Ast\Node) {
                if (self::nodeContainsYieldOrAwait($value)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof \Phasis\Ast\Node && self::nodeContainsYieldOrAwait($item)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private static function validateDynamicFunction(\Phasis\Ast\Program $program, string $params): void
    {
        // The parsed program should contain a single ExpressionStatement
        // wrapping a FunctionExpression. Extract its body.
        $fnBody = null;
        foreach ($program->body as $stmt) {
            if (
                $stmt instanceof \Phasis\Ast\Statement\ExpressionStatement
                && $stmt->expression instanceof \Phasis\Ast\Expression\FunctionExpression
            ) {
                $fnBody = $stmt->expression->body;
                break;
            }
        }
        if ($fnBody === null) {
            return;
        }

        // Check for "use strict" directive in the function body.
        $isStrict = false;
        if ($fnBody instanceof \Phasis\Ast\Statement\BlockStatement) {
            foreach ($fnBody->body as $bodyStmt) {
                if (!$bodyStmt instanceof \Phasis\Ast\Statement\ExpressionStatement) {
                    break;
                }
                $expr = $bodyStmt->expression;
                if (
                    $expr instanceof \Phasis\Ast\Expression\Literal
                    && is_string($expr->value)
                    && $expr->value === 'use strict'
                ) {
                    $isStrict = true;
                    break;
                }
                if (!$expr instanceof \Phasis\Ast\Expression\Literal || !is_string($expr->value)) {
                    break;
                }
            }
        }

        if (!$isStrict) {
            return;
        }

        // Validate: no 'with' statements in the body.
        if ($fnBody instanceof \Phasis\Ast\Statement\BlockStatement) {
            self::checkNoWithStatements($fnBody->body);
        }

        // Validate: no duplicate parameter names in strict mode.
        // Also check for restricted names: 'eval' and 'arguments'.
        if ($params !== '') {
            $names = array_map('trim', explode(',', $params));
            $seen = [];
            foreach ($names as $name) {
                if ($name === '') {
                    continue;
                }
                if ($name === 'eval' || $name === 'arguments') {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Unexpected eval or arguments in strict mode",
                    );
                }
                if (in_array($name, $seen, true)) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Duplicate parameter name not allowed in this context",
                    );
                }
                $seen[] = $name;
            }
        }
    }

    /**
     * Recursively check that no WithStatement exists in the given statements.
     *
     * @param \Phasis\Ast\Node[] $statements
     * @throws \Phasis\Exceptions\SyntaxError
     */
    private static function checkNoWithStatements(array $statements): void
    {
        foreach ($statements as $stmt) {
            if ($stmt instanceof \Phasis\Ast\Statement\WithStatement) {
                throw new \Phasis\Exceptions\SyntaxError(
                    'Strict mode code may not include a with statement',
                );
            }
            if ($stmt instanceof \Phasis\Ast\Statement\BlockStatement) {
                self::checkNoWithStatements($stmt->body);
            } elseif ($stmt instanceof \Phasis\Ast\Statement\IfStatement) {
                if ($stmt->consequent instanceof \Phasis\Ast\Statement\BlockStatement) {
                    self::checkNoWithStatements($stmt->consequent->body);
                } elseif ($stmt->consequent instanceof \Phasis\Ast\Statement\WithStatement) {
                    throw new \Phasis\Exceptions\SyntaxError('Strict mode code may not include a with statement');
                }
                if ($stmt->alternate !== null) {
                    if ($stmt->alternate instanceof \Phasis\Ast\Statement\BlockStatement) {
                        self::checkNoWithStatements($stmt->alternate->body);
                    } elseif ($stmt->alternate instanceof \Phasis\Ast\Statement\WithStatement) {
                        throw new \Phasis\Exceptions\SyntaxError('Strict mode code may not include a with statement');
                    }
                }
            }
        }
    }
}
