<?php

declare(strict_types=1);

namespace Phasis\Runtime\Parts\Helpers;

use Phasis\Ast\Declaration\ClassDeclaration;
use Phasis\Ast\Declaration\ExportDeclaration;
use Phasis\Ast\Declaration\FunctionDeclaration;
use Phasis\Ast\Declaration\ImportDeclaration;
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
use Phasis\Ast\Expression\FunctionExpression;
use Phasis\Ast\Expression\ImportExpression;
use Phasis\Ast\Expression\MetaProperty;
use Phasis\Ast\Expression\Identifier;
use Phasis\Ast\Expression\Literal;
use Phasis\Ast\Expression\LogicalExpression;
use Phasis\Ast\Expression\MemberExpression;
use Phasis\Ast\Expression\NewExpression;
use Phasis\Ast\Expression\ObjectExpression;
use Phasis\Ast\Expression\Property;
use Phasis\Ast\Expression\SequenceExpression;
use Phasis\Ast\Expression\SpreadElement;
use Phasis\Ast\Expression\TaggedTemplate;
use Phasis\Ast\Expression\TemplateLiteral;
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
use Phasis\Exceptions\InternalError;
use Phasis\Exceptions\ReferenceError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\GeneratorReturnSignal;
use Phasis\Value\GeneratorThrowSignal;
use Phasis\Value\JsAsyncGenerator;
use Phasis\Value\JsFunction;
use Phasis\Value\JsGenerator;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsArgumentsObject;
use Phasis\Value\JsObject;
use Phasis\Value\JsOptionalUndefined;
use Phasis\Value\JsProxy;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\Runtime\Environment;
use Phasis\Runtime\CallStack;
use Phasis\Runtime\Completion;
use Phasis\Runtime\CompletionType;
use Phasis\Runtime\Reference;

/**
 * Interpreter helper part: ReferenceResolution. Composed back into the
 * Interpreter via the InterpreterHelpers trait. `self::`/`$this->`
 * resolve into the composing class.
 */
trait ReferenceResolution
{
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
                $refBase = $superBase ?? \Phasis\Value\JsNull::instance();
                // The actual `this` is the receiver for [[Set]] and getter invocations.
                // Per spec, if this is uninitialized (derived constructor before super()),
                // GetThisBinding() throws ReferenceError.
                $superThisVal = $env->get('this');
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

            // Private identifier: obj.#name
            if ($node->property instanceof PrivateIdentifier) {
                $brandedName = $env->resolvePrivateName($node->property->name);
                return new Reference(
                    $obj,
                    $brandedName,
                    $this->strictMode,
                    privateFieldName: $brandedName,
                );
            }

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
     * Find the environment that owns a binding for $name, or null if no
     * declarative binding exists anywhere on the chain. Used by the
     * compound-assignment fast path to capture the target env before
     * RHS evaluation, so eval-injected shadowing bindings cannot
     * redirect the write away from the spec-required target.
     *
     * Caller must ensure no with-environments are active (the fast
     * path checks that). Stops at the first scope that owns the name
     * or the root global env.
     */
    private function resolveBindingEnvironment(Environment $env, string $name): ?Environment
    {
        $cursor = $env;
        while ($cursor !== null) {
            if ($cursor->hasOwnBinding($name)) {
                return $cursor;
            }
            $linked = $cursor->getLinkedObject();
            if (
                $linked !== null
                && $cursor->getParent() === null
                && $linked->hasOwnProperty($name)
            ) {
                return $cursor;
            }
            $cursor = $cursor->getParent();
        }
        return null;
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
                // Per spec 9.1.1.2.1 HasBinding: check [[HasProperty]] first,
                // then check @@unscopables. If unscopable, skip this environment.
                if ($withObj->has($name) && !$this->isWithUnscopable($withObj, $name)) {
                    return new Reference($withObj, $name, $this->strictMode);
                }
                // The with-object does not have the binding or it is
                // unscopable; skip to parent.
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
     * Per spec 9.1.1.2.1 HasBinding step 5-7: after confirming the binding object
     * has the property, check @@unscopables. If unscopables[name] is truthy, the
     * binding is considered not present. This is the same logic as
     * Environment::isUnscopable but operates on a JsObject directly for use in
     * resolveIdentifierReference.
     */
    private function isWithUnscopable(JsObject $withObj, string $name): bool
    {
        $unscopables = $withObj->getBySymbol(
            \Phasis\BuiltIn\SymbolConstructor::unscopables()
        );
        if ($unscopables instanceof JsObject) {
            $value = $unscopables->get($name);
            return TypeConversion::toBoolean($value);
        }
        return false;
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

    /**
     * Per spec 9.1.1.2.6 GetBindingValue for Object Environment Records:
     * Before reading a value through a with-binding reference, perform a
     * separate HasProperty check. This is required because the binding may
     * have been deleted between HasBinding and GetBindingValue, and Proxy
     * traps must fire independently for each spec step. Returns the value
     * from GetBindingValue.
     */
    private function withGetBindingValue(Reference $ref): JsValue
    {
        if (
            $ref->base instanceof JsObject
            && isset($this->activeWithObjectIds[spl_object_id($ref->base)])
        ) {
            // GetBindingValue step 2: HasProperty(bindingObject, N).
            if (!$ref->base->has($ref->name)) {
                if ($ref->strict) {
                    throw new ReferenceError(
                        "{$ref->name} is not defined"
                    );
                }
                return JsUndefined::instance();
            }
            // GetBindingValue step 4: Get(bindingObject, N).
            return $ref->base->get($ref->name);
        }
        return $ref->getValue();
    }

    /**
     * Per spec 9.1.1.2.5 SetMutableBinding for Object Environment Records:
     * Before writing through a with-binding reference, re-check HasProperty
     * on the binding object. If the property no longer exists (e.g. deleted
     * by the RHS expression) and strict mode is active, throw ReferenceError.
     * This must be called before Reference::setValue() for with-binding
     * references.
     */
    private function withSetMutableBindingCheck(Reference $ref, JsValue $value): void
    {
        if (
            $ref->base instanceof JsObject
            && isset($this->activeWithObjectIds[spl_object_id($ref->base)])
        ) {
            // Step 2: Let stillExists be HasProperty(bindingObject, N).
            $stillExists = $ref->base->has($ref->name);
            // Step 3: If stillExists is false and S is true, throw ReferenceError.
            if (!$stillExists && $ref->strict) {
                throw new ReferenceError("{$ref->name} is not defined");
            }
            // Step 4: Perform Set(bindingObject, N, V, S).
            // Use internalSet so we get the boolean success result for strict mode.
            $success = $ref->base->internalSet($ref->name, $value, $ref->base);
            if (!$success && $ref->strict) {
                throw new TypeError(
                    "Cannot assign to read only property '{$ref->name}' of object '#<Object>'"
                );
            }
            return;
        }
        $ref->setValue($value);
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
            try {
                foreach ($elements as $elem) {
                    if ($elem instanceof RestElement || $elem instanceof SpreadElement) {
                        $restArg = $elem->argument;
                        // Per spec: evaluate DestructuringAssignmentTarget BEFORE consuming iterator.
                        $restRef = null;
                        if (!$this->isDestructuringTarget($restArg)) {
                            $restRef = $this->resolveReference($restArg, $env);
                        }
                        $restValue = $this->iteratorRest($iterator, $nextMethod, $done);
                        if ($restRef !== null) {
                            $restRef->setValue($restValue);
                        } else {
                            $this->destructureAssign($restArg, $restValue, $env);
                        }
                        break;
                    }
                    if ($elem === null) {
                        // Elision: advance iterator but discard value.
                        $this->iteratorNext($iterator, $nextMethod, $done);
                        continue;
                    }
                    // Per spec 13.15.5.3 IteratorDestructuringAssignmentEvaluation:
                    // Step 1: evaluate DestructuringAssignmentTarget to get lref
                    // BEFORE stepping the iterator (step 2).
                    $elemTarget = $elem;
                    $defaultNode = null;
                    $ref = null;
                    if ($elem instanceof AssignmentPattern || $elem instanceof AssignmentExpression) {
                        $elemTarget = $elem instanceof AssignmentPattern ? $elem->left : $elem->left;
                        $defaultNode = $elem instanceof AssignmentPattern ? $elem->right : $elem->right;
                    }
                    if (!$this->isDestructuringTarget($elemTarget)) {
                        $ref = $this->resolveReference($elemTarget, $env);
                    }
                    // Step 2: advance the iterator.
                    $elemValue = $this->iteratorNext($iterator, $nextMethod, $done);
                    // Steps 3-5: apply default value if present and value is undefined.
                    if ($defaultNode !== null && $elemValue instanceof JsUndefined) {
                        $elemValue = $this->evaluate($defaultNode, $env);
                        if (
                            $elemValue instanceof JsFunction
                            && $elemTarget instanceof Identifier
                            && $this->isAnonymousFunctionDefinitionNode($defaultNode)
                            && !$this->hasExplicitNameProperty($elemValue)
                        ) {
                            $elemValue->setName($elemTarget->name);
                        }
                    }
                    // Steps 6-8: assign the value.
                    if ($ref !== null) {
                        $ref->setValue($elemValue);
                    } else {
                        $this->destructureAssign($elemTarget, $elemValue, $env);
                    }
                }
            } catch (\Throwable $e) {
                // Per spec: if destructuring aborts, close the iterator.
                if (!$done) {
                    $this->iteratorClose($iterator, $e);
                }
                throw $e;
            }
            // Per spec: if iterator is not exhausted after processing all elements, close it.
            if (!$done) {
                $this->iteratorClose($iterator);
            }
            return;
        }

        if ($target instanceof ObjectPattern || $target instanceof ObjectExpression) {
            // Object destructuring calls ToObject — throws TypeError on null/undefined.
            if ($value instanceof JsNull || $value instanceof JsUndefined) {
                throw new \Phasis\Exceptions\TypeError(
                    "Cannot destructure property of " . TypeConversion::toString($value),
                );
            }
            // Per spec: object destructuring calls ToObject on the value so that
            // primitives (strings, numbers, etc.) are wrapped.
            $objValue = $value instanceof JsObject ? $value : TypeConversion::toObject($value);
            $props = $target instanceof ObjectPattern ? $target->properties : $target->properties;
            $usedKeys = [];
            $usedSymIds = [];
            foreach ($props as $prop) {
                if ($prop instanceof RestElement || $prop instanceof SpreadElement) {
                    $restObj = new JsObject();
                    $this->copyRestDataProperties($objValue, $restObj, $usedKeys, $usedSymIds);
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
                // Step 1: evaluate PropertyName to get the source key.
                $isSymKey = false;
                $rawPropKey = null;
                if ($propNode instanceof AssignmentProperty || $propNode instanceof Property) {
                    if ($propNode->computed) {
                        $rawPropKey = $this->evaluate($propNode->key, $env);
                        if ($rawPropKey instanceof JsSymbol) {
                            $isSymKey = true;
                            $usedSymIds[$rawPropKey->getId()] = true;
                            $key = '';
                        } else {
                            $key = TypeConversion::toString($rawPropKey);
                        }
                    } else {
                        $key = $propNode->key instanceof Identifier
                            ? $propNode->key->name
                            : TypeConversion::toString($this->evaluate($propNode->key, $env));
                    }
                } else {
                    $key = '';
                }
                if (!$isSymKey) {
                    $usedKeys[] = $key;
                }

                $valueNode = ($propNode instanceof AssignmentProperty || $propNode instanceof Property)
                    ? $propNode->value
                    : $propNode;

                // Determine the actual target and default node.
                $realTarget = $valueNode;
                $defaultNode2 = null;
                if ($valueNode instanceof AssignmentPattern || $valueNode instanceof AssignmentExpression) {
                    $realTarget = $valueNode instanceof AssignmentPattern
                        ? $valueNode->left
                        : $valueNode->left;
                    $defaultNode2 = $valueNode instanceof AssignmentPattern
                        ? $valueNode->right
                        : $valueNode->right;
                }

                // Per spec 13.15.5.4 KeyedDestructuringAssignmentEvaluation:
                // Step 1: evaluate DestructuringAssignmentTarget BEFORE GetV.
                $ref = null;
                if (!$this->isDestructuringTarget($realTarget)) {
                    $ref = $this->resolveReference($realTarget, $env);
                }

                // Step 2: GetV(value, propertyName). Symbol keys use getBySymbol.
                $propValue = $isSymKey
                    ? $objValue->getBySymbol($rawPropKey)
                    : $objValue->get($key);

                // Step 3: apply default if present and value is undefined.
                if ($defaultNode2 !== null && $propValue instanceof JsUndefined) {
                    $propValue = $this->evaluate($defaultNode2, $env);
                    if (
                        $propValue instanceof JsFunction
                        && $realTarget instanceof Identifier
                        && $this->isAnonymousFunctionDefinitionNode($defaultNode2)
                        && !$this->hasExplicitNameProperty($propValue)
                    ) {
                        $propValue->setName($realTarget->name);
                    }
                }

                // Steps 4-7: assign via PutValue or nested destructuring.
                if ($ref !== null) {
                    $ref->setValue($propValue);
                } else {
                    $this->destructureAssign($realTarget, $propValue, $env);
                }
            }
        }
    }
}
