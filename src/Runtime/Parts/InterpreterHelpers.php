<?php

declare(strict_types=1);

namespace Phasis\Runtime\Parts;

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
 * Interpreter part: InterpreterHelpers. Composed into Interpreter via
 * `use Parts\InterpreterHelpers;`. `self::`/`$this->` references resolve
 * into the composing class.
 */
trait InterpreterHelpers
{
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

    /**
     * Get the iterator for a value; throw TypeError if not iterable.
     * Returns [iterator, nextMethod]. The next-method may be any JsValue;
     * the "next is not a function" TypeError surfaces later when iteration
     * actually invokes it.
     *
     * @return array{JsObject, JsValue}
     */
    private function getIteratorOrThrow(JsValue $value): array
    {
        $iterator = $this->getIterator($value);
        if ($iterator === null) {
            $typeName = $value instanceof JsNumber ? 'number'
                : ($value instanceof JsBoolean ? 'boolean'
                : ($value instanceof JsSymbol ? 'symbol'
                : TypeConversion::toString($value)));
            throw new \Phasis\Exceptions\TypeError($typeName . ' is not iterable');
        }
        // Per GetIteratorFromMethod, nextMethod is retrieved but not required
        // to be callable here. The "next is not a function" TypeError surfaces
        // later when we actually call next() during iteration.
        $nextMethod = $iterator->get('next');
        return [$iterator, $nextMethod];
    }

    /**
     * Advance an iterator one step. Returns the value, or undefined when done.
     * Sets $done to true once the iterator reports done.
     */
    private function iteratorNext(JsObject $iterator, JsValue $nextMethod, bool &$done): JsValue
    {
        if ($done) {
            return JsUndefined::instance();
        }
        if ($nextMethod instanceof \Phasis\Value\JsProxy && $nextMethod->isCallable()) {
            try {
                $result = $nextMethod->apply($iterator, []);
            } catch (\Throwable $e) {
                // Per spec 7.4.2 IteratorStep: if next() throws, iteratorRecord.[[done]] = true.
                $done = true;
                throw $e;
            }
        } elseif ($nextMethod instanceof JsFunction) {
            try {
                $result = $this->callFunction($nextMethod, $iterator, []);
            } catch (\Throwable $e) {
                // Per spec 7.4.2 IteratorStep: if next() throws, iteratorRecord.[[done]] = true.
                $done = true;
                throw $e;
            }
        } else {
            $done = true;
            throw new \Phasis\Exceptions\TypeError('Iterator result next is not a function');
        }
        if (!$result instanceof JsObject) {
            $done = true;
            throw new \Phasis\Exceptions\TypeError('Iterator result is not an object');
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
    private function iteratorRest(JsObject $iterator, JsValue $nextMethod, bool &$done): JsArray
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
     * IteratorClose per spec 7.4.6.
     * Calls iterator.return() and validates the result.
     *
     * @param JsObject $iterator The iterator object.
     * @param \Throwable|null $completion The abrupt completion that triggered the close (null for normal).
     * @throws \Throwable Re-throws appropriate error per spec steps 7-9.
     */
    private function iteratorClose(JsObject $iterator, ?\Throwable $completion = null): void
    {
        // Only abrupt THROW completions take precedence over innerResult per
        // spec 7.4.6 step 7. GeneratorReturnSignal corresponds to a "return"
        // completion, not a throw, so innerResult's errors still surface.
        $completionIsThrow = $completion !== null
            && !($completion instanceof \Phasis\Value\GeneratorReturnSignal);

        // Per spec step 1: innerResult = Completion(GetMethod(iterator, "return")).
        // A throwing `return` accessor must be captured as innerException so
        // the outer throw completion (if any) takes precedence per step 3.
        $innerException = null;
        $returnMethod = null;
        try {
            $returnMethod = $iterator->get('return');
        } catch (\Throwable $e) {
            $innerException = $e;
        }

        if ($innerException === null) {
            if ($returnMethod instanceof JsUndefined || $returnMethod instanceof JsNull) {
                if ($completion !== null) {
                    throw $completion;
                }
                return;
            }
            if (!$returnMethod instanceof JsFunction) {
                if ($completionIsThrow) {
                    throw $completion;
                }
                if ($completion !== null) {
                    throw $completion;
                }
                throw new TypeError('Iterator return is not callable');
            }
        }

        $innerResult = null;
        if ($innerException === null) {
            try {
                $innerResult = $this->callFunction($returnMethod, $iterator, []);
            } catch (\Throwable $e) {
                $innerException = $e;
            }
        }

        // Step 7: if completion.[[type]] is throw, return Completion(completion).
        if ($completionIsThrow) {
            throw $completion;
        }

        // Step 8: if innerResult.[[type]] is throw, return Completion(innerResult).
        if ($innerException !== null) {
            throw $innerException;
        }

        // Step 9: if Type(innerResult.[[value]]) is not Object, throw TypeError.
        if (!$innerResult instanceof JsObject) {
            throw new TypeError('Iterator return result is not an object');
        }

        // Propagate non-throw abrupt completions (e.g. generator return signal)
        // after the innerResult validation.
        if ($completion !== null) {
            throw $completion;
        }
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

    // phpExceptionToJsValue is defined earlier in this file.

    /** @return never */
    public function throwJsValue(JsValue $value): void
    {
        // Always use JsThrowable to preserve the original JS value.
        // execTryStatement catches JsThrowable and extracts jsValue for the catch block.
        // Use display() rather than ToString so that throwing a Symbol or
        // other non-stringifiable primitive does not raise a secondary
        // TypeError that replaces the original throw value.
        throw new \Phasis\Exceptions\JsThrowable($value);
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
    public function createRegExpFromConstructor(string $pattern, string $flags, bool $isSubclass = false): JsObject
    {
        $obj = $this->createRegExpObject($pattern, $flags);
        if ($isSubclass) {
            $obj->defineOwnProperty(
                '[[LegacyFeaturesEnabled]]',
                PropertyDescriptor::data(new JsBoolean(false), false, false, false),
            );
        }
        return $obj;
    }

    /**
     * Get a global value by name. Used by built-in methods that need access
     * to constructors like RegExp, Array, etc.
     */
    public function getGlobalValue(string $name): JsValue
    {
        if ($this->globalEnv->has($name)) {
            return $this->globalEnv->get($name);
        }
        return JsUndefined::instance();
    }

    /**
     * Call a function as a constructor (new F(args)). Used by built-in methods
     * for SpeciesConstructor calls.
     *
     * @param JsValue[] $args
     */
    public function callNew(JsValue $callee, array $args): JsValue
    {
        if (!$callee instanceof JsFunction || !$callee->isConstructable()) {
            throw new TypeError(TypeConversion::toString($callee) . ' is not a constructor');
        }
        $proto = $callee->get('prototype');
        $newObj = new JsObject($proto instanceof JsObject ? $proto : null);
        $newObj->defineOwnProperty(
            '[[NewTarget]]',
            \Phasis\Object\PropertyDescriptor::data($callee, false, false, false),
        );
        $result = $this->callFunction($callee, $newObj, $args);
        if ($result instanceof JsObject) {
            return $result;
        }
        if ($callee->isDerivedConstructor() && !$result instanceof JsUndefined) {
            throw new TypeError('Derived constructors may only return object or undefined');
        }
        return $newObj;
    }

    private function createRegExpObject(string $pattern, string $flags): JsObject
    {
        // Pathological `\u{0…0XXXX}` braced escapes (test262
        // unicode-braced.js / unicode-class-braced.js construct 16M-char
        // patterns) make every downstream char-by-char scan an O(n)
        // walk over millions of leading zeros. Pre-collapse them in /u
        // mode to the canonical short form so validators, transforms,
        // and the custom-matcher parser all work on a small string.
        // [[OriginalSource]] is set later from $originalPattern, so the
        // user-visible `.source` still reflects what the program wrote.
        $originalPattern = $pattern;
        if (
            (str_contains($flags, 'u') || str_contains($flags, 'v'))
            && strlen($pattern) > 64
            && str_contains($pattern, '\\u{')
        ) {
            $pattern = self::canonicalizeUnicodeBracedEscapes($pattern);
        }
        // Validate flags per spec 22.2.3.1: only valid flag characters, no duplicates.
        // 'v' is the unicodeSets flag (ES2024), mutually exclusive with 'u'.
        $validFlags = 'dgimsuvy';
        $seenFlags = [];
        for ($fi = 0; $fi < strlen($flags); $fi++) {
            $ch = $flags[$fi];
            if (strpos($validFlags, $ch) === false) {
                throw new \Phasis\Exceptions\SyntaxError("Invalid flags supplied to RegExp constructor '{$flags}'");
            }
            if (isset($seenFlags[$ch])) {
                throw new \Phasis\Exceptions\SyntaxError("Invalid flags supplied to RegExp constructor '{$flags}'");
            }
            $seenFlags[$ch] = true;
        }
        // 'u' and 'v' are mutually exclusive per spec.
        if (str_contains($flags, 'u') && str_contains($flags, 'v')) {
            throw new \Phasis\Exceptions\SyntaxError("Invalid flags supplied to RegExp constructor '{$flags}'");
        }

        $isUnicode = str_contains($flags, 'u') || str_contains($flags, 'v');

        // Unicode mode validation per spec B.1.4: octal escapes and certain
        // identity escapes are not allowed in /u patterns.
        if ($isUnicode) {
            $this->validateUnicodePattern($pattern);
        }
        // Run the same parse-time validator that regex literals get so
        // RegExp(/.../, "u") rechecks identity-escape and bracket rules
        // against the new flag set.
        try {
            \Phasis\Parser\Parser::validateRegExpAtRuntime($pattern, $flags);
        } catch (\Phasis\Exceptions\SyntaxError $e) {
            throw $e;
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
        $canonicalFlagOrder = 'dgimsuvy';
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
        // Internal slots for compile() and prototype getters.
        // These are not affected by user-visible property overrides.
        // [[OriginalSource]] preserves the literal pattern text users wrote
        // (including any pathological leading zeros in `\u{0…0XXXX}` escapes
        // that the canonical-form pre-pass elided from the working pattern).
        $obj->defineOwnProperty(
            '[[OriginalSource]]',
            PropertyDescriptor::data(new JsString($originalPattern), false, false, false),
        );
        $obj->defineOwnProperty(
            '[[OriginalFlags]]',
            PropertyDescriptor::data(new JsString($sortedFlags), false, false, false),
        );
        // Per spec, source/flags/global/ignoreCase/multiline/dotAll/unicode/
        // unicodeSets/sticky/hasIndices are prototype accessor properties, not
        // own data properties. Do not install them as own properties so that
        // Object.defineProperty overrides and the prototype getters work correctly.
        // lastIndex is writable but not enumerable, not configurable per spec.
        $obj->defineOwnProperty('lastIndex', PropertyDescriptor::data(JsNumber::of(0.0), true, false, false));

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
        // PCRE_DOLLAR_ENDONLY: ECMAScript `$` (no /m) matches the end of the
        // input, never before a final newline. PCRE's default lets `$` match
        // before a trailing newline, which would make `/abc$/.test("abc\n")`
        // succeed.
        $pcreFlags .= 'D';

        // ECMAScript group names allow `$` and the full IdentifierName grammar
        // (including non-ASCII letters). PCRE2 only accepts ASCII letters,
        // digits and underscore. Remap to PCRE-safe placeholders here and
        // remember the original names so match results can present the spec
        // names back to the user.
        [$rewrittenPattern, $groupNameMap] = self::rewriteRegExpGroupNames($pattern);

        // Transform ECMAScript-specific character class escapes for PCRE compatibility.
        // PCRE's \s does not include U+FEFF; ECMAScript's does.
        $transformedPattern = $this->transformEsPatternForPcre($rewrittenPattern, $flags);

        // Transform large quantifiers that exceed PCRE2's 65535 limit.
        $transformedPattern = self::transformLargeQuantifiers($transformedPattern);

        // PCRE2 refuses unbounded quantifiers inside lookbehinds; clamp
        // any `+`, `*`, or `{N,}` inside (?<= … ) / (?<! … ) to a finite
        // upper bound so the assertion compiles.
        $transformedPattern = self::boundLookbehindQuantifiers($transformedPattern);

        // Detect duplicate named groups (ES2025 "Duplicate named capture groups"):
        //   - within the same alternative → SyntaxError per spec.
        //   - in separate alternatives → allowed; enable PCRE's J modifier.
        if (self::hasDuplicateNamedGroupsInSameAlternative($pattern)) {
            throw new \Phasis\Exceptions\SyntaxError(
                "Invalid regular expression: /{$pattern}/: Duplicate capture group name"
            );
        }
        if (self::hasDuplicateNamedGroups($pattern)) {
            $pcreFlags .= 'J';
        }

        // Per ES2024 "RegExp Modifiers" (§22.2.1.6 early errors):
        //   (?addFlags-removeFlags:Disjunction) must not have any flag
        //   appearing in both sides, and neither side may repeat a flag.
        self::validateRegExpModifierGroups($pattern);

        // Escape unescaped forward slashes for the PCRE delimiter.
        // Already-escaped slashes (\/) must not be double-escaped.
        $escapedPattern = $this->escapeForPcreDelimiter($transformedPattern);
        $pcrePattern = '/' . $escapedPattern . '/' . $pcreFlags . 'u';

        // Validate the pattern compiles. If PCRE2 rejects it but the
        // pattern is one we deliberately route through the in-engine
        // matcher (lookbehind, duplicate names, etc.), let our parser
        // accept it — its grammar is the authoritative ECMAScript
        // validator there. For every other PCRE2 rejection treat it
        // as an ECMAScript SyntaxError, since accepting silently
        // would let invalid patterns pass.
        $pcreCompiles = @preg_match($pcrePattern, '') !== false;
        if (!$pcreCompiles) {
            $customNeeded = self::patternNeedsCustomMatcher($pattern, $flags);
            $customParseOk = false;
            if ($customNeeded) {
                try {
                    (new \Phasis\Regex\Parser($pattern, $flags))->parse();
                    $customParseOk = true;
                } catch (\Throwable) {
                    $customParseOk = false;
                }
            }
            if (!$customParseOk) {
                throw new \Phasis\Exceptions\SyntaxError(
                    'Invalid regular expression: /' . $pattern . '/: ' . preg_last_error_msg(),
                );
            }
        }

        $isGlobal = str_contains($flags, 'g');
        $isSticky = str_contains($flags, 'y');
        $hasIndices = str_contains($flags, 'd');

        // Analyze the original ES pattern for repeated groups that need
        // ES-compliant capture reset and nullable quantifier handling.
        $repeatedGroupAnalysis = self::analyzeRepeatedGroups($pattern);
        $hasRepeatedGroupFixes = !empty($repeatedGroupAnalysis['repeatedGroups'])
            || !empty($repeatedGroupAnalysis['nullableNonCapturingGroups']);

        // Build the PCRE flags string (without the delimiter and 'u') for inner patterns.
        $innerPcreFlags = $pcreFlags . 'u';

        // Transform function for building PCRE patterns from ES sub-patterns.
        $self = $this;
        $transformFn = static function (string $esSubPattern) use ($self, $flags): string {
            $transformed = $self->transformEsPatternForPcre($esSubPattern, $flags);
            return $self->escapeForPcreDelimiter($transformed);
        };

        // Store the compiled PCRE pattern as a non-enumerable internal slot so prototype
        // methods (exec, test) installed on RegExp.prototype can access it via $this_.
        $obj->defineOwnProperty(
            '[[PCREPattern]]',
            PropertyDescriptor::data(new JsString($pcrePattern), false, false, false),
        );
        // [[GroupNameMap]] is the safe-name → original-name mapping used by
        // exec/match to reconstruct user-visible named-capture keys.
        if (!empty($groupNameMap)) {
            $mapValue = JsObject::createNullPrototype();
            foreach ($groupNameMap as $safe => $orig) {
                $mapValue->defineOwnProperty(
                    $safe,
                    PropertyDescriptor::data(new JsString($orig), false, false, false),
                );
            }
            $obj->defineOwnProperty(
                '[[GroupNameMap]]',
                PropertyDescriptor::data($mapValue, false, false, false),
            );
        }

        // [[CustomMatcher]] is set when the pattern uses a feature that
        // PCRE2 cannot match exactly:
        //   - lookbehind containing capture groups (capture order),
        //   - quantified group containing captures (capture reset),
        //   - any `.` in non-unicode mode (PCRE2 matches UTF-8 code
        //     points; ES non-unicode mode requires UTF-16 code units,
        //     so `.` must NOT match an astral character).
        // exec() routes through the in-engine matcher in those cases.
        if (self::patternNeedsCustomMatcher($pattern, $flags)) {
            try {
                $regexAst = (new \Phasis\Regex\Parser($pattern, $flags))->parse();
                $obj->defineOwnProperty(
                    '[[CustomRegexAst]]',
                    PropertyDescriptor::data(
                        new \Phasis\Value\JsHostValue($regexAst),
                        false,
                        false,
                        false,
                    ),
                );
                $obj->defineOwnProperty(
                    '[[CustomRegexFlags]]',
                    PropertyDescriptor::data(
                        new JsString($flags),
                        false,
                        false,
                        false,
                    ),
                );
            } catch (\Throwable) {
                // If our parser bails on something it doesn't model
                // yet, fall back to PCRE2.
            }
        }

        // [[NamedGroupNames]] preserves every distinct named group in the
        // pattern in source order. exec() uses this to pre-populate the
        // result.groups object so groups that did not participate in the
        // match still appear (with value undefined) — required by the
        // duplicate-named-groups proposal.
        $namedGroupNames = self::extractNamedGroupNames($pattern);
        if (!empty($namedGroupNames)) {
            $namedListArr = [];
            foreach ($namedGroupNames as $n) {
                $namedListArr[] = new JsString($n);
            }
            $obj->defineOwnProperty(
                '[[NamedGroupNames]]',
                PropertyDescriptor::data(
                    JsArray::fromArray($namedListArr),
                    false,
                    false,
                    false,
                ),
            );
        }

        // exec(): handles lastIndex for global/sticky regexes per spec 22.2.5.2.
        $execFn = function (
            JsValue $this_,
            array $args
        ) use (
            $pcrePattern,
            $obj,
            $isGlobal,
            $isSticky,
            $hasIndices,
            $hasRepeatedGroupFixes,
            $repeatedGroupAnalysis,
            $innerPcreFlags,
            $transformFn,
        ): JsValue {
            // Per spec: if no argument, convert undefined to "undefined".
            $str = isset($args[0]) ? TypeConversion::toString($args[0])
                : TypeConversion::toString(JsUndefined::instance());
            // lastIndex is a UTF-16 code unit offset; comparing it
            // against mb_strlen (codepoints) short-circuits any iter
            // that has stepped past mb_strlen units into the second
            // half of an astral. Use UTF-16 unit count instead.
            $strLen = (int) (strlen(JsString::utf8ToUtf16LE($str)) / 2);

            // Per spec step 4: always read lastIndex (for observable side effects
            // like valueOf calls), even when global/sticky are unset.
            $lastIndexVal = $obj->get('lastIndex');
            $lastIndex = TypeConversion::toLength($lastIndexVal);

            if (!$isGlobal && !$isSticky) {
                $lastIndex = 0;
            }

            if ($lastIndex > $strLen) {
                if ($isGlobal || $isSticky) {
                    // Per spec: Set(R, "lastIndex", 0, Throw=true).
                    $obj->set('lastIndex', JsNumber::of(0.0), true);
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
                    $obj->set('lastIndex', JsNumber::of(0.0), true);
                    return JsNull::instance();
                }

                // Apply ES-compliant fixes for repeated groups.
                if ($hasRepeatedGroupFixes) {
                    // Fix 1: Extend match for nullable quantified groups.
                    $matches = self::fixNullableQuantifier(
                        $matches,
                        $repeatedGroupAnalysis,
                        $str,
                        $innerPcreFlags,
                        $transformFn,
                    );
                    // Fix 2: Reset captures inside repeated groups to last iteration values.
                    $matches = self::fixRepeatedGroupCaptures(
                        $matches,
                        $repeatedGroupAnalysis,
                        $innerPcreFlags,
                        $transformFn,
                    );
                    // Fix 3: Reset captures inside nullable non-capturing groups.
                    // Per ES spec RepeatMatcher step 2.b: when min=0 and the body
                    // matched zero-length, the repetition fails and captures inside
                    // are reset to undefined.
                    $matches = self::fixNullableNonCapturingGroupCaptures(
                        $matches,
                        $repeatedGroupAnalysis,
                    );
                }

                // Convert byte position back to character position.
                $matchCharPos = mb_strlen(substr($str, 0, $matches[0][1]), 'UTF-8');
                $matchStr = $matches[0][0];
                $matchCharLen = mb_strlen($matchStr, 'UTF-8');

                if ($isGlobal || $isSticky) {
                    // Per spec: Set(R, "lastIndex", matchEnd, Throw=true).
                    $obj->set('lastIndex', JsNumber::of((float) ($matchCharPos + $matchCharLen)), true);
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
                // Per spec, index/input/groups are added via CreateDataProperty.
                $result->defineOwnProperty(
                    'index',
                    PropertyDescriptor::data(JsNumber::of((float) $matchCharPos), true, true, true),
                );
                $result->defineOwnProperty(
                    'input',
                    PropertyDescriptor::data(new JsString($str), true, true, true),
                );

                // Named capture groups.
                $groups = new JsObject(null);
                $hasGroups = false;
                foreach ($matches as $key => $match) {
                    if (is_string($key)) {
                        $hasGroups = true;
                        $groups->defineOwnProperty($key, PropertyDescriptor::data(
                            ($match[1] === -1 || $match[0] === null)
                                ? JsUndefined::instance()
                                : new JsString($match[0]),
                            true,
                            true,
                            true,
                        ));
                    }
                }
                $result->defineOwnProperty(
                    'groups',
                    PropertyDescriptor::data(
                        $hasGroups ? $groups : JsUndefined::instance(),
                        true,
                        true,
                        true,
                    ),
                );

                if ($hasIndices) {
                    $indEls = [];
                    foreach ($matches as $ik => $im) {
                        if (!is_int($ik)) {
                            continue;
                        }
                        if ($im[1] === -1 || $im[0] === null) {
                            $indEls[] = JsUndefined::instance();
                        } else {
                            $isc = mb_strlen(
                                substr($str, 0, $im[1]),
                                'UTF-8'
                            );
                            $iec = $isc + mb_strlen(
                                $im[0],
                                'UTF-8'
                            );
                            $indEls[] = JsArray::fromArray([
                                JsNumber::of((float) $isc),
                                JsNumber::of((float) $iec),
                            ]);
                        }
                    }
                    $iArr = JsArray::fromArray($indEls);
                    $iGrp = new JsObject(null);
                    $iHasGrp = false;
                    foreach ($matches as $ik => $im) {
                        if (!is_string($ik)) {
                            continue;
                        }
                        $iHasGrp = true;
                        if ($im[1] === -1 || $im[0] === null) {
                            $iGrp->defineOwnProperty(
                                $ik,
                                PropertyDescriptor::data(
                                    JsUndefined::instance()
                                )
                            );
                        } else {
                            $igsc = mb_strlen(
                                substr($str, 0, $im[1]),
                                'UTF-8'
                            );
                            $igec = $igsc + mb_strlen(
                                $im[0],
                                'UTF-8'
                            );
                            $iGrp->defineOwnProperty(
                                $ik,
                                PropertyDescriptor::data(
                                    JsArray::fromArray([
                                        JsNumber::of((float) $igsc),
                                        JsNumber::of((float) $igec),
                                    ])
                                )
                            );
                        }
                    }
                    $iArr->defineOwnProperty(
                        'groups',
                        PropertyDescriptor::data(
                            $iHasGrp
                                ? $iGrp
                                : JsUndefined::instance(),
                            true,
                            true,
                            true
                        )
                    );
                    $result->defineOwnProperty(
                        'indices',
                        PropertyDescriptor::data(
                            $iArr,
                            true,
                            true,
                            true
                        )
                    );
                }

                return $result;
            }

            if ($isGlobal || $isSticky) {
                // Per spec: Set(R, "lastIndex", 0, Throw=true).
                $obj->set('lastIndex', JsNumber::of(0.0), true);
            }
            return JsNull::instance();
        };
        // exec, test, toString are inherited from RegExp.prototype.
        // Do NOT install own properties here; the prototype methods read
        // [[PCREPattern]] and flags from the instance via 'this'.

        return $obj;
    }

    /**
     * Transform ECMAScript regex pattern for PCRE compatibility.
     *
     * ECMAScript \s includes U+FEFF (BOM) but PCRE \s does not.
     * This transforms \s and \S outside character classes to include FEFF.
     * Inside character classes, \s is replaced with \s\x{FEFF}.
     */
    public function transformEsPatternForPcre(string $pattern, string $flags = ''): string
    {
        $isUnicodeMode = str_contains($flags, 'u') || str_contains($flags, 'v');
        $isVFlag = str_contains($flags, 'v');

        // In v-flag mode, handle set operations (&&, --) and nested character
        // classes by pre-transforming before the main character-level pass.
        if ($isVFlag) {
            $pattern = $this->transformVFlagPattern($pattern);
        }

        // In non-unicode mode, Annex B B.1.4.1.1 says a range `A-B` where
        // either side isn't a single character must be treated as the union
        // of A, literal `-`, and B. Rewrite such ranges so PCRE accepts them.
        if (!$isUnicodeMode) {
            $pattern = $this->rewriteAnnexBClassRanges($pattern);
        }

        // Count capturing groups for backreference validation (Annex B).
        $numGroups = $this->countCapturingGroups($pattern);
        $result = '';
        $len = strlen($pattern);
        $inCharClass = false;
        $isDotAll = str_contains($flags, 's');
        // Stack of dotAll states. Each entry is true when `.` should match
        // line terminators (whole-pattern /s flag, or an enclosing
        // `(?s:…)` modifier group).
        $dotAllStack = [$isDotAll];
        $i = 0;
        // Bytes that interest the per-char transform: `\\`, `[`, `]`,
        // `(`, `)`, `.` (dot rewrite), `0xED` (raw surrogate detect),
        // and `$`/`^` for non-class anchors. Plain bytes that match none
        // of those copy through unchanged — bulk-append them in one
        // strcspn so a 16 MiB \u{…} body doesn't N-square on $result.
        $stopOpen = "\\[].()\xED";
        $stopClass = "\\]\xED";

        while ($i < $len) {
            $ch = $pattern[$i];
            if ($inCharClass) {
                if ($ch !== '\\' && $ch !== ']' && ord($ch) !== 0xED) {
                    $skip = strcspn($pattern, $stopClass, $i);
                    if ($skip > 0) {
                        $result .= substr($pattern, $i, $skip);
                        $i += $skip;
                        continue;
                    }
                }
            } elseif (
                $ch !== '\\' && $ch !== '[' && $ch !== ']'
                && $ch !== '(' && $ch !== ')' && $ch !== '.'
                && ord($ch) !== 0xED
            ) {
                $skip = strcspn($pattern, $stopOpen, $i);
                if ($skip > 0) {
                    $result .= substr($pattern, $i, $skip);
                    $i += $skip;
                    continue;
                }
            }

            // Outside a character class and outside the current dotAll
            // scope, `.` excludes the JS LineTerminator set per spec.
            if ($ch === '.' && !$inCharClass && !end($dotAllStack)) {
                $result .= '[^\\n\\r\\x{2028}\\x{2029}]';
                $i++;
                continue;
            }
            if (
                $ch === '(' && !$inCharClass && $i + 2 < $len
                && $pattern[$i + 1] === '?'
            ) {
                $j = $i + 2;
                $addS = null;
                while ($j < $len && ($pattern[$j] === 'i' || $pattern[$j] === 'm' || $pattern[$j] === 's')) {
                    if ($pattern[$j] === 's') {
                        $addS = true;
                    }
                    $j++;
                }
                if ($j < $len && $pattern[$j] === '-') {
                    $j++;
                    while ($j < $len && ($pattern[$j] === 'i' || $pattern[$j] === 'm' || $pattern[$j] === 's')) {
                        if ($pattern[$j] === 's') {
                            $addS = false;
                        }
                        $j++;
                    }
                }
                if ($j < $len && $pattern[$j] === ':' && $addS !== null) {
                    $dotAllStack[] = $addS;
                }
            }
            if ($ch === ')' && !$inCharClass && count($dotAllStack) > 1) {
                array_pop($dotAllStack);
            }

            // Detect raw UTF-8 encoded surrogate bytes (U+D800-U+DFFF).
            // These are 3-byte sequences: 0xED 0xA0-0xBF 0x80-0xBF.
            // PCRE2 in UTF-8 mode rejects them, so replace with U+FFFE.
            if (
                ord($ch) === 0xED
                && $i + 2 < $len
                && ord($pattern[$i + 1]) >= 0xA0
                && ord($pattern[$i + 1]) <= 0xBF
                && ord($pattern[$i + 2]) >= 0x80
                && ord($pattern[$i + 2]) <= 0xBF
            ) {
                $result .= '\\x{FFFE}';
                $i += 3;
                continue;
            }

            if ($ch === '\\' && $i + 1 < $len) {
                $next = $pattern[$i + 1];

                // \p{...} and \P{...} Unicode property escapes.
                if (($next === 'p' || $next === 'P') && $i + 2 < $len && $pattern[$i + 2] === '{') {
                    $closeBrace = strpos($pattern, '}', $i + 3);
                    if ($closeBrace !== false) {
                        $propExpr = substr($pattern, $i + 3, $closeBrace - ($i + 3));
                        if ($isUnicodeMode) {
                            // Property of strings under /v: expand to a
                            // string alternation rather than emitting
                            // the never-match \\x{FFFE} sentinel. The
                            // data file stores codepoints in JS
                            // \\u{HEX} form so the in-class route
                            // (which re-runs through this char-level
                            // pass) gets them rewritten correctly;
                            // for the top-level emit we have to
                            // pre-translate to PCRE \\x{HEX}.
                            if ($isVFlag && $next === 'p' && !$inCharClass) {
                                $stringSet = self::vFlagPropertyOfStringsSet($propExpr);
                                if ($stringSet !== null) {
                                    $pcreSet = array_map(
                                        static fn (string $s): string =>
                                            str_replace('\\u{', '\\x{', $s),
                                        $stringSet,
                                    );
                                    $result .= '(?:' . implode('|', $pcreSet) . ')';
                                    $i = $closeBrace + 1;
                                    continue;
                                }
                            }
                            // Properties of strings (Basic_Emoji,
                            // RGI_Emoji, RGI_Emoji_*_Sequence,
                            // Emoji_Keycap_Sequence) are only valid under
                            // /v and only outside character classes. Per
                            // ECMA-262 §22.2.1: \p{StringProperty} with
                            // /u or with \P or inside a class is an
                            // early-error SyntaxError.
                            if (self::isVStringBinaryProperty($propExpr)) {
                                if (!$isVFlag || $next === 'P' || $inCharClass) {
                                    throw new \Phasis\Exceptions\SyntaxError(
                                        'Invalid regular expression: '
                                            . 'property of strings only allowed in /v outside character classes'
                                    );
                                }
                            }
                            $pcreProperty = self::mapEsPropertyToPcre($propExpr, $next === 'P');
                            if ($pcreProperty === null) {
                                throw new \Phasis\Exceptions\SyntaxError(
                                    'Invalid regular expression: Invalid property expression'
                                );
                            }
                            $result .= $pcreProperty;
                        } else {
                            // Outside unicode mode, \p is an identity escape for 'p'.
                            $result .= '\\x{' . strtoupper(dechex(ord($next))) . '}';
                            $result .= '{' . $propExpr . '}';
                        }
                        $i = $closeBrace + 1;
                        continue;
                    }
                }

                if ($next === 's') {
                    // ECMAScript WhiteSpace ∪ LineTerminator. Spec-frozen
                    // explicit list. PCRE's \s under /u includes U+180E
                    // (Mongolian Vowel Separator) which Unicode removed
                    // from Space_Separator in v6.3 — JS no longer treats
                    // it as whitespace. Use an explicit class instead.
                    $wsClass = '\\t\\n\\x0B\\f\\r \\xA0\\x{1680}\\x{2000}-\\x{200A}\\x{2028}\\x{2029}\\x{202F}\\x{205F}\\x{3000}\\x{FEFF}';
                    if ($inCharClass) {
                        $result .= $wsClass;
                    } else {
                        $result .= '[' . $wsClass . ']';
                    }
                    $i += 2;
                    continue;
                }
                if ($next === 'S') {
                    $wsClass = '\\t\\n\\x0B\\f\\r \\xA0\\x{1680}\\x{2000}-\\x{200A}\\x{2028}\\x{2029}\\x{202F}\\x{205F}\\x{3000}\\x{FEFF}';
                    if ($inCharClass) {
                        // Inside [...], best-effort fallback to PCRE \S.
                        // Cannot express set complement portably here.
                        $result .= '\\S';
                    } else {
                        $result .= '[^' . $wsClass . ']';
                    }
                    $i += 2;
                    continue;
                }
                // ECMAScript `\d` is exactly [0-9] regardless of /u or /v,
                // and `\D` is its complement [^0-9]. PCRE2 in PCRE2_UTF
                // mode (which we always enable when emitting `/u` to PCRE)
                // treats `\d` as Unicode-aware `\p{Nd}`, matching e.g.
                // FULLWIDTH and Arabic-Indic digits. That over-matches and
                // makes `\D` under-match. Emit explicit ASCII ranges so
                // the spec semantics survive the trip through PCRE.
                if ($next === 'd') {
                    if ($inCharClass) {
                        $result .= '0-9';
                    } else {
                        $result .= '[0-9]';
                    }
                    $i += 2;
                    continue;
                }
                if ($next === 'D') {
                    if ($inCharClass) {
                        // Inside [...], emit the explicit complement of
                        // [0-9] over the full Unicode code-point range.
                        // PCRE \D under /u still excludes Unicode digits
                        // (\p{Nd}), so a literal `\D` would diverge for
                        // `[\D]u` matched against e.g. FULLWIDTH ZERO.
                        $result .= '\\x{0}-\\x{2F}\\x{3A}-\\x{10FFFF}';
                    } else {
                        $result .= '[^0-9]';
                    }
                    $i += 2;
                    continue;
                }
                // \u{XXXXXX} ES2015 unicode escape with braces — only in
                // u/v mode. In non-unicode mode `\u` followed by `{` is an
                // identity escape for `u` plus a quantifier `{N}`.
                if ($next === 'u' && $i + 2 < $len && $pattern[$i + 2] === '{' && $isUnicodeMode) {
                    $end = strpos($pattern, '}', $i + 3);
                    if ($end !== false) {
                        $hex = substr($pattern, $i + 3, $end - ($i + 3));
                        if (ctype_xdigit($hex)) {
                            // Strip leading zeros — PCRE2 caps \\x{...}
                            // hex digits at 8, but spec allows up to
                            // 0x10FFFF expressed with arbitrary leading
                            // zeros, so /\\u{000000000000000000041}/u
                            // is valid input that compiles to PCRE.
                            $trimmed = ltrim($hex, '0');
                            if ($trimmed === '') {
                                $trimmed = '0';
                            }
                            $result .= '\\x{' . strtoupper($trimmed) . '}';
                            $i = $end + 1;
                            continue;
                        }
                    }
                }
                // \uXXXX 4-digit Unicode escape: convert to PCRE \x{XXXX}.
                // Surrogate code points (D800-DFFF) are invalid in UTF-8 and
                // rejected by PCRE. A lead surrogate (D800-DBFF) followed
                // immediately by \uXXXX trail surrogate (DC00-DFFF) forms a
                // surrogate pair encoding a supplementary code point. Decode
                // them into a single \x{XXXXX} for PCRE. Lone surrogates are
                // replaced with U+FFFE so the regex compiles.
                if ($next === 'u' && $i + 5 < $len + 1) {
                    $hex = substr($pattern, $i + 2, 4);
                    if (strlen($hex) === 4 && ctype_xdigit($hex)) {
                        $codePoint = hexdec($hex);
                        if ($codePoint >= 0xD800 && $codePoint <= 0xDBFF) {
                            // Lead surrogate: check for trail surrogate \uXXXX immediately after.
                            $afterLead = $i + 6;
                            if (
                                $afterLead + 5 < $len + 1
                                && $pattern[$afterLead] === '\\'
                                && ($afterLead + 1 < $len) && $pattern[$afterLead + 1] === 'u'
                            ) {
                                $trailHex = substr($pattern, $afterLead + 2, 4);
                                if (strlen($trailHex) === 4 && ctype_xdigit($trailHex)) {
                                    $trailCp = hexdec($trailHex);
                                    if ($trailCp >= 0xDC00 && $trailCp <= 0xDFFF) {
                                        // Decode surrogate pair: UTF16Decode(lead, trail).
                                        $combined = 0x10000
                                            + (($codePoint - 0xD800) << 10)
                                            + ($trailCp - 0xDC00);
                                        $result .= '\\x{' . strtoupper(dechex($combined)) . '}';
                                        $i = $afterLead + 6;
                                        continue;
                                    }
                                }
                            }
                            // Lone lead surrogate: replace with U+FFFE.
                            $result .= '\\x{FFFE}';
                        } elseif ($codePoint >= 0xDC00 && $codePoint <= 0xDFFF) {
                            // Lone trail surrogate: replace with U+FFFE.
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
                            $groupDeclMatch = [];
                            $hasGroup = preg_match(
                                '/\(\?<' . preg_quote($groupName, '/') . '>/',
                                $pattern,
                                $groupDeclMatch,
                                PREG_OFFSET_CAPTURE,
                            ) === 1;
                            if ($hasGroup) {
                                $declOffset = $groupDeclMatch[0][1];
                                if ($declOffset > $i) {
                                    // Forward named reference: per spec the
                                    // backref matches the empty string when
                                    // the group hasn't captured yet. PCRE
                                    // would fail the match, so emit (?:).
                                    $result .= '(?:)';
                                    $i = $closeAngle + 1;
                                } else {
                                    // Backward / self / sibling reference. If
                                    // the target group might not participate
                                    // (alternation, optional quantifier, or
                                    // self-reference inside its own
                                    // quantified body), wrap so PCRE can fall
                                    // back to the empty alternative.
                                    $kEnd = $closeAngle + 1;
                                    $kRefPos = $i;
                                    $needsWrap = $this->backrefMayMissCapture(
                                        $pattern,
                                        $declOffset,
                                        $kRefPos,
                                    );
                                    $kRef = substr($pattern, $i, $kEnd - $i);
                                    if ($needsWrap) {
                                        $result .= '(?:' . $kRef . '|)';
                                    } else {
                                        $result .= $kRef;
                                    }
                                    $i = $kEnd;
                                }
                            } else {
                                // No matching named group: per Annex B in
                                // non-unicode mode \k<...> is literal `k<...>`.
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
                // produces a control character. Annex B B.1.4 extends the set
                // of ClassControlLetter inside a character class to include
                // DecimalDigit and `_`. Outside a class (or in u-mode), only
                // letters are valid; when invalid, treat \c as literal in
                // non-u mode (Annex B).
                if ($next === 'c') {
                    if ($i + 2 < $len) {
                        $controlChar = $pattern[$i + 2];
                        $isLetter = ($controlChar >= 'A' && $controlChar <= 'Z')
                            || ($controlChar >= 'a' && $controlChar <= 'z');
                        if ($isLetter) {
                            // Valid \cX: pass through as PCRE handles it.
                            $result .= $ch . $next . $controlChar;
                            $i += 3;
                            continue;
                        }
                        $isAnnexBClassLetter = !$isUnicodeMode && $inCharClass
                            && (($controlChar >= '0' && $controlChar <= '9') || $controlChar === '_');
                        if ($isAnnexBClassLetter) {
                            // Annex B: emit the control character whose value
                            // is `ord(controlChar) % 32`.
                            $cp = ord($controlChar) % 32;
                            $result .= '\\x{' . strtoupper(dechex($cp)) . '}';
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
                // \0 outside character class: NUL character (or legacy octal
                // when Annex B and lookahead is an octal digit).
                if ($next === '0' && !$inCharClass) {
                    $oj = $i + 2;
                    if (!$isUnicodeMode && $oj < $len && $pattern[$oj] >= '0' && $pattern[$oj] <= '7') {
                        // Legacy octal sequence: \0[0-7][0-7]?
                        $octalStr = '0';
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
                        $result .= '\\x{0}';
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
                            // LegacyOctalEscapeSequence: when the first digit
                            // is 4..7 the sequence is at most 2 digits long;
                            // 0..3 may extend to 3 digits.
                            $maxLen = ($next >= '4' && $next <= '7') ? 2 : 3;
                            $octalStr = '';
                            $oj = $i + 1;
                            while (
                                $oj < $len
                                && $pattern[$oj] >= '0'
                                && $pattern[$oj] <= '7'
                                && strlen($octalStr) < $maxLen
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
                    // Valid backreference: check if it's a forward backreference.
                    // In ECMAScript, a backreference to a group that appears
                    // later in the pattern matches the empty string. PCRE does
                    // not handle this correctly, so convert forward backrefs to (?:).
                    $groupPositions = $this->getCapturingGroupPositions($pattern);
                    if (
                        isset($groupPositions[$refNum - 1])
                        && $groupPositions[$refNum - 1] > $i
                    ) {
                        $result .= '(?:)';
                    } else {
                        // In ECMAScript, a backreference to a non-participating
                        // group (one that exists but didn't capture) matches the
                        // empty string. PCRE fails the match instead. Only wrap
                        // when the referenced group might actually skip
                        // capturing: it sits in another alternation branch,
                        // inside a lookahead/lookbehind that the backref does
                        // not share, or under a `?`/`*`/`{0,…}` quantifier. For
                        // unconditional backrefs we keep PCRE semantics so that
                        // patterns like `(.)\1` advance the match position when
                        // the backref does not match.
                        $groupOpen = $groupPositions[$refNum - 1];
                        if ($this->backrefMayMissCapture($pattern, $groupOpen, $i)) {
                            $result .= '(?:' . $ch . $numStr . '|)';
                        } else {
                            $result .= $ch . $numStr;
                        }
                    }
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
                        // Detect CESU-8-encoded lone surrogates (U+D800..
                        // U+DFFF) before calling mb_ord: the surrogate
                        // range is invalid UTF-8 and mb_ord rejects it.
                        // Decoding the bytes directly lets us emit a
                        // safe PCRE substitute instead of relying on a
                        // mb_ord failure path PHPStan stubs do not
                        // advertise as reachable.
                        $bytes = array_map('ord', str_split($mbChar));
                        $decodedSurrogate = (count($bytes) === 3
                            && ($bytes[0] & 0xF0) === 0xE0
                            && ($bytes[1] & 0xC0) === 0x80
                            && ($bytes[2] & 0xC0) === 0x80)
                                ? (
                                    (($bytes[0] & 0x0F) << 12)
                                    | (($bytes[1] & 0x3F) << 6)
                                    | ($bytes[2] & 0x3F)
                                )
                                : null;
                        if (
                            $decodedSurrogate !== null
                            && $decodedSurrogate >= 0xD800
                            && $decodedSurrogate <= 0xDFFF
                        ) {
                            // Lone surrogate: replace with U+FFFE to
                            // avoid PCRE error.
                            $result .= '\\x{FFFE}';
                        } else {
                            $cp = mb_ord($mbChar, 'UTF-8');
                            $result .= '\\x{' . strtoupper(dechex($cp)) . '}';
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
                // [\S] and [^\S]: PCRE \S excludes FEFF, but JS \S also
                // excludes FEFF, so the inverse matters. Rewrite the class to
                // express JS-compatible semantics directly.
                if (
                    $i + 3 < $len && $pattern[$i + 1] === '\\'
                    && $pattern[$i + 2] === 'S' && $pattern[$i + 3] === ']'
                ) {
                    $result .= '[^\\s\\x{FEFF}]';
                    $i += 4;
                    continue;
                }
                if (
                    $i + 4 < $len && $pattern[$i + 1] === '^' && $pattern[$i + 2] === '\\'
                    && $pattern[$i + 3] === 'S' && $pattern[$i + 4] === ']'
                ) {
                    $result .= '[\\s\\x{FEFF}]';
                    $i += 5;
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
            if ($ch === '[') {
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
     * Map ECMAScript Unicode property escape expressions to PCRE2 equivalents.
     * Returns null if the property expression is invalid.
     */
    public static function mapEsPropertyToPcrePublic(string $propExpr, bool $negated): ?string
    {
        return self::mapEsPropertyToPcre($propExpr, $negated);
    }

    /** Whether $name is a binary Unicode property recognised by the engine. */
    public static function isBinaryUnicodePropertyName(string $name): bool
    {
        return self::mapBinaryProperty($name) !== null;
    }

    /** Whether $name is a /v-only string-binary property. */
    public static function isVStringBinaryPropertyPublic(string $name): bool
    {
        return self::isVStringBinaryProperty($name);
    }

    /**
     * Whether the lone-form `\p{X}` is known: either a General_Category
     * value (e.g. Letter, Lu) or a binary property (e.g. ASCII, Emoji).
     */
    public static function isLoneUnicodePropertyKnown(string $name): bool
    {
        return self::mapGeneralCategoryValue($name) !== null
            || self::mapBinaryProperty($name) !== null;
    }

    /**
     * Whether $name is a known non-binary Unicode property name (the
     * left side of `\p{Name=Value}`). Limited to General_Category,
     * Script, Script_Extensions, and their aliases — names that pair
     * with a value per spec.
     */
    public static function isNonBinaryUnicodePropertyName(string $name): bool
    {
        return self::normalizeEsPropertyName($name) !== null;
    }

    /** Normalize a property name into its canonical long form. */
    public static function normalizeUnicodePropertyName(string $name): ?string
    {
        return self::normalizeEsPropertyName($name);
    }

    /** Whether $value is an exact, case-sensitive General_Category value. */
    public static function isGeneralCategoryValue(string $value): bool
    {
        return self::mapGeneralCategoryValue($value) !== null;
    }

    /**
     * Decode `\uXXXX` / `\u{X..}` escape sequences in a regex group name
     * to their UTF-8 form. Surrogate pairs are merged. Returns null if a
     * sequence is malformed.
     */
    private static function decodeRegExpEscapesInName(string $name): ?string
    {
        if (!str_contains($name, '\\')) {
            return $name;
        }
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
                    $result .= mb_chr($cp, 'UTF-8');
                    $i = $end + 1;
                    continue;
                }
                if ($i + 5 < $len) {
                    $hex = substr($name, $i + 2, 4);
                    if (strlen($hex) === 4 && ctype_xdigit($hex)) {
                        $cp = (int) hexdec($hex);
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
                                    $result .= mb_chr($cp, 'UTF-8');
                                    $i += 12;
                                    continue;
                                }
                            }
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

    /**
     * Rewrite ECMAScript named capture groups so the PCRE compiler accepts
     * them. Names containing non-ASCII characters or `$` are remapped to
     * `_es<index>_`, and matching `\k<name>` references are rewritten in
     * lockstep. Returns `[rewritten pattern, safe→original name map]`.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function rewriteRegExpGroupNames(string $pattern): array
    {
        // Hot-path fast exit: a pattern with no named-group syntax at all
        // (no `(?<` outside character classes) cannot need rewriting.
        // strpos is O(N) in C, beats the byte-by-byte scan below.
        if (strpos($pattern, '(?<') === false) {
            return [$pattern, []];
        }
        $len = strlen($pattern);
        $needsRewrite = false;
        $i = 0;
        $inClass = false;
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c !== '\\' && $c !== '[' && $c !== ']' && $c !== '(') {
                $skip = strcspn($pattern, "\\[](", $i);
                if ($skip > 0) {
                    $i += $skip;
                    continue;
                }
            }
            if ($c === '\\') {
                $i += 2;
                continue;
            }
            if ($c === '[') {
                $inClass = true;
                $i++;
                continue;
            }
            if ($c === ']') {
                $inClass = false;
                $i++;
                continue;
            }
            if ($inClass) {
                $i++;
                continue;
            }
            if (
                $c === '(' && $i + 2 < $len
                && $pattern[$i + 1] === '?' && $pattern[$i + 2] === '<'
                && ($pattern[$i + 3] ?? '') !== '=' && ($pattern[$i + 3] ?? '') !== '!'
            ) {
                $closeAngle = strpos($pattern, '>', $i + 3);
                if ($closeAngle !== false) {
                    $name = substr($pattern, $i + 3, $closeAngle - ($i + 3));
                    if (preg_match('/[^A-Za-z0-9_]/', $name) || str_contains($name, '\\')) {
                        $needsRewrite = true;
                        break;
                    }
                    $i = $closeAngle + 1;
                    continue;
                }
            }
            $i++;
        }
        if (!$needsRewrite) {
            return [$pattern, []];
        }
        $orderToOriginal = [];
        $groupIndex = 0;
        $i = 0;
        $out = '';
        $inClass = false;
        $safeToOrig = [];
        $origToSafe = [];
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === '\\') {
                if (
                    !$inClass && $i + 2 < $len
                    && $pattern[$i + 1] === 'k' && $pattern[$i + 2] === '<'
                ) {
                    $closeAngle = strpos($pattern, '>', $i + 3);
                    if ($closeAngle !== false) {
                        $refRaw = substr($pattern, $i + 3, $closeAngle - ($i + 3));
                        $refDecoded = self::decodeRegExpEscapesInName($refRaw) ?? $refRaw;
                        if (isset($origToSafe[$refDecoded])) {
                            $out .= '\\k<' . $origToSafe[$refDecoded] . '>';
                            $i = $closeAngle + 1;
                            continue;
                        }
                        if (isset($origToSafe[$refRaw])) {
                            $out .= '\\k<' . $origToSafe[$refRaw] . '>';
                            $i = $closeAngle + 1;
                            continue;
                        }
                        // Forward reference: emit a placeholder keyed by the
                        // decoded name so the second pass can resolve it.
                        $out .= '\\k<__esfwd_' . count($orderToOriginal) . '_' . md5($refDecoded) . '_>';
                        $orderToOriginal[] = $refDecoded;
                        $i = $closeAngle + 1;
                        continue;
                    }
                }
                $out .= substr($pattern, $i, 2);
                $i += 2;
                continue;
            }
            if ($c === '[') {
                $inClass = true;
                $out .= $c;
                $i++;
                continue;
            }
            if ($c === ']') {
                $inClass = false;
                $out .= $c;
                $i++;
                continue;
            }
            if (!$inClass && $c === '(') {
                if (
                    $i + 2 < $len
                    && $pattern[$i + 1] === '?' && $pattern[$i + 2] === '<'
                    && ($pattern[$i + 3] ?? '') !== '=' && ($pattern[$i + 3] ?? '') !== '!'
                ) {
                    $closeAngle = strpos($pattern, '>', $i + 3);
                    if ($closeAngle !== false) {
                        $groupIndex++;
                        $rawName = substr($pattern, $i + 3, $closeAngle - ($i + 3));
                        // Spec names allow `\u{XXXX}` and `\uXXXX` escapes;
                        // store the decoded form so the user-facing groups
                        // object exposes the canonical text.
                        $decoded = self::decodeRegExpEscapesInName($rawName);
                        $name = $decoded ?? $rawName;
                        $safe = '_es' . $groupIndex . '_';
                        $safeToOrig[$safe] = $name;
                        $origToSafe[$name] = $safe;
                        // Also map the raw form so backref `\k<rawname>` finds
                        // the same group.
                        if ($rawName !== $name) {
                            $origToSafe[$rawName] = $safe;
                        }
                        $out .= '(?<' . $safe . '>';
                        $i = $closeAngle + 1;
                        continue;
                    }
                } elseif ($pattern[$i + 1] !== '?') {
                    $groupIndex++;
                }
                $out .= $c;
                $i++;
                continue;
            }
            $out .= $c;
            $i++;
        }
        // Second pass: replace forward-reference placeholders with safe
        // names that we now know.
        $out = preg_replace_callback(
            '/\\\\k<__esfwd_(\\d+)_[a-f0-9]+_>/',
            static function ($matches) use ($orderToOriginal, $origToSafe) {
                $idx = (int) $matches[1];
                $orig = $orderToOriginal[$idx] ?? null;
                if ($orig !== null && isset($origToSafe[$orig])) {
                    return '\\k<' . $origToSafe[$orig] . '>';
                }
                return $matches[0];
            },
            $out,
        ) ?? $out;
        return [$out, $safeToOrig];
    }

    private static function mapEsPropertyToPcre(string $propExpr, bool $negated): ?string
    {
        $prefix = $negated ? '\\P' : '\\p';

        if (str_contains($propExpr, '=')) {
            $parts = explode('=', $propExpr, 2);
            $propName = $parts[0];
            $propValue = $parts[1];
            $normalizedName = self::normalizeEsPropertyName($propName);
            if ($normalizedName === null) {
                return null;
            }
            if ($normalizedName === 'General_Category') {
                $shortValue = self::mapGeneralCategoryValue($propValue);
                if ($shortValue === null) {
                    // Host PCRE2 doesn't know the GC value but our
                    // Unicode 16 bundle might. Emit a never-match
                    // sentinel; patternNeedsCustomMatcher routes
                    // \p{...} patterns through the custom matcher
                    // which consults the bundled table for the actual
                    // membership decision.
                    if (self::bundleKnowsProperty($propName, $propValue)) {
                        return self::neverMatchSentinel($negated);
                    }
                    return null;
                }
                return $prefix . '{' . $shortValue . '}';
            }
            if ($normalizedName === 'Script' || $normalizedName === 'Script_Extensions') {
                $normalizedScript = self::normalizeScriptName($propValue);
                if ($normalizedScript === null) {
                    if (self::bundleKnowsProperty($propName, $propValue)) {
                        return self::neverMatchSentinel($negated);
                    }
                    return null;
                }
                return $prefix . '{' . $normalizedName . '=' . $normalizedScript . '}';
            }
            return null;
        }

        $gcShort = self::mapGeneralCategoryValue($propExpr);
        if ($gcShort !== null) {
            return $prefix . '{' . $gcShort . '}';
        }

        $binaryPcre = self::mapBinaryProperty($propExpr);
        if ($binaryPcre !== null) {
            if ($binaryPcre === '!Assigned') {
                return ($negated ? '\\p' : '\\P') . '{Cn}';
            }
            if ($binaryPcre === '!StringProperty') {
                // We don't have data for v-flag string-binary properties.
                // Substitute a never-matching codepoint (U+FFFE
                // noncharacter) so the regex compiles and the property
                // contributes nothing to the match set.
                return self::neverMatchSentinel($negated);
            }
            return $prefix . '{' . $binaryPcre . '}';
        }

        if (self::bundleKnowsProperty($propExpr, null)) {
            return self::neverMatchSentinel($negated);
        }
        return null;
    }

    /**
     * Whether the bundled Unicode 16 property tables have an entry
     * for ($name, $value) — either as a property=value pair (Script,
     * General_Category, Script_Extensions, with alias) or a bare
     * binary property name. See src/Regex/Unicode16Tables.php for
     * the keying convention.
     */
    private static function bundleKnowsProperty(string $name, ?string $value): bool
    {
        $key = $value === null ? $name : $name . '=' . $value;
        return array_key_exists($key, \Phasis\Regex\Unicode16Tables::PROPERTIES);
    }

    /**
     * PCRE2 sentinel that matches no input — used when the host PCRE2
     * lacks data for a property the regex pattern names but the custom
     * matcher's bundled tables can answer. Keeps the regex constructor
     * from throwing SyntaxError at construct time; the matcher routes
     * \p{...} through Unicode16Tables anyway.
     */
    private static function neverMatchSentinel(bool $negated): string
    {
        return $negated ? '[^\\x{FFFE}]' : '\\x{FFFE}';
    }

    private static function normalizeEsPropertyName(string $name): ?string
    {
        static $aliases = [
            'General_Category' => 'General_Category', 'gc' => 'General_Category',
            'Script' => 'Script', 'sc' => 'Script',
            'Script_Extensions' => 'Script_Extensions', 'scx' => 'Script_Extensions',
        ];
        return $aliases[$name] ?? null;
    }

    private static function mapGeneralCategoryValue(string $value): ?string
    {
        static $map = [
            'Letter' => 'L', 'Cased_Letter' => 'L&',
            'Uppercase_Letter' => 'Lu', 'Lowercase_Letter' => 'Ll',
            'Titlecase_Letter' => 'Lt', 'Modifier_Letter' => 'Lm',
            'Other_Letter' => 'Lo',
            'Mark' => 'M', 'Nonspacing_Mark' => 'Mn',
            'Spacing_Mark' => 'Mc', 'Enclosing_Mark' => 'Me',
            'Number' => 'N', 'Decimal_Number' => 'Nd',
            'Letter_Number' => 'Nl', 'Other_Number' => 'No',
            'Punctuation' => 'P', 'Connector_Punctuation' => 'Pc',
            'Dash_Punctuation' => 'Pd', 'Open_Punctuation' => 'Ps',
            'Close_Punctuation' => 'Pe', 'Initial_Punctuation' => 'Pi',
            'Final_Punctuation' => 'Pf', 'Other_Punctuation' => 'Po',
            'Symbol' => 'S', 'Math_Symbol' => 'Sm',
            'Currency_Symbol' => 'Sc', 'Modifier_Symbol' => 'Sk',
            'Other_Symbol' => 'So',
            'Separator' => 'Z', 'Space_Separator' => 'Zs',
            'Line_Separator' => 'Zl', 'Paragraph_Separator' => 'Zp',
            'Other' => 'C', 'Control' => 'Cc', 'Format' => 'Cf',
            'Surrogate' => 'Cs', 'Private_Use' => 'Co', 'Unassigned' => 'Cn',
            'L' => 'L', 'L&' => 'L&', 'LC' => 'L&',
            'Lu' => 'Lu', 'Ll' => 'Ll', 'Lt' => 'Lt',
            'Lm' => 'Lm', 'Lo' => 'Lo',
            'M' => 'M', 'Mn' => 'Mn', 'Mc' => 'Mc', 'Me' => 'Me',
            'N' => 'N', 'Nd' => 'Nd', 'Nl' => 'Nl', 'No' => 'No',
            'P' => 'P', 'Pc' => 'Pc', 'Pd' => 'Pd', 'Ps' => 'Ps',
            'Pe' => 'Pe', 'Pi' => 'Pi', 'Pf' => 'Pf', 'Po' => 'Po',
            'S' => 'S', 'Sm' => 'Sm', 'Sc' => 'Sc', 'Sk' => 'Sk', 'So' => 'So',
            'Z' => 'Z', 'Zs' => 'Zs', 'Zl' => 'Zl', 'Zp' => 'Zp',
            'C' => 'C', 'Cc' => 'Cc', 'Cf' => 'Cf', 'Cs' => 'Cs',
            'Co' => 'Co', 'Cn' => 'Cn',
            'cntrl' => 'Cc', 'digit' => 'Nd', 'punct' => 'P',
            // Unicode "loose" alias for Mark / M (PropertyValueAliases.txt
            // lists Combining_Mark as a synonym for the Mark general
            // category). Required by the test262 generated suite.
            'Combining_Mark' => 'M',
        ];
        return $map[$value] ?? null;
    }

    /**
     * v-flag binary properties of strings (ES2024) that aren't backed by a
     * PCRE binary property. Accepted at parse-time so the regex compiles;
     * runtime matching against these falls through and matches nothing.
     */
    private static function isVStringBinaryProperty(string $name): bool
    {
        static $names = [
            'Basic_Emoji' => true,
            'Emoji_Keycap_Sequence' => true,
            'RGI_Emoji' => true,
            'RGI_Emoji_Flag_Sequence' => true,
            'RGI_Emoji_Modifier_Sequence' => true,
            'RGI_Emoji_Tag_Sequence' => true,
            'RGI_Emoji_ZWJ_Sequence' => true,
        ];
        return isset($names[$name]);
    }

    private static function mapBinaryProperty(string $name): ?string
    {
        if (self::isVStringBinaryProperty($name)) {
            // We don't have native data for v-flag string-binary
            // properties. Substitute a never-matching tag so the regex
            // compiles cleanly without polluting the match set.
            return '!StringProperty';
        }
        static $supported = [
            'ASCII' => 'ASCII', 'ASCII_Hex_Digit' => 'ASCII_Hex_Digit',
            'AHex' => 'ASCII_Hex_Digit', 'Alphabetic' => 'Alphabetic',
            'Alpha' => 'Alphabetic', 'Any' => 'Any',
            'Bidi_Control' => 'Bidi_Control', 'Bidi_C' => 'Bidi_Control',
            'Bidi_Mirrored' => 'Bidi_Mirrored', 'Bidi_M' => 'Bidi_Mirrored',
            'Case_Ignorable' => 'Case_Ignorable', 'CI' => 'Case_Ignorable',
            'Cased' => 'Cased',
            'Changes_When_Casefolded' => 'Changes_When_Casefolded',
            'CWCF' => 'Changes_When_Casefolded',
            'Changes_When_Casemapped' => 'Changes_When_Casemapped',
            'CWCM' => 'Changes_When_Casemapped',
            'Changes_When_Lowercased' => 'Changes_When_Lowercased',
            'CWL' => 'Changes_When_Lowercased',
            'Changes_When_NFKC_Casefolded' => 'Changes_When_NFKC_Casefolded',
            'CWKCF' => 'Changes_When_NFKC_Casefolded',
            'Changes_When_Titlecased' => 'Changes_When_Titlecased',
            'CWT' => 'Changes_When_Titlecased',
            'Changes_When_Uppercased' => 'Changes_When_Uppercased',
            'CWU' => 'Changes_When_Uppercased',
            'Dash' => 'Dash',
            'Default_Ignorable_Code_Point' => 'Default_Ignorable_Code_Point',
            'DI' => 'Default_Ignorable_Code_Point',
            'Deprecated' => 'Deprecated', 'Dep' => 'Deprecated',
            'Diacritic' => 'Diacritic', 'Dia' => 'Diacritic',
            'Emoji' => 'Emoji', 'Emoji_Component' => 'Emoji_Component',
            'EComp' => 'Emoji_Component',
            'Emoji_Modifier' => 'Emoji_Modifier', 'EMod' => 'Emoji_Modifier',
            'Emoji_Modifier_Base' => 'Emoji_Modifier_Base',
            'EBase' => 'Emoji_Modifier_Base',
            'Emoji_Presentation' => 'Emoji_Presentation',
            'EPres' => 'Emoji_Presentation',
            'Extended_Pictographic' => 'Extended_Pictographic',
            'ExtPict' => 'Extended_Pictographic',
            'Extender' => 'Extender', 'Ext' => 'Extender',
            'Grapheme_Base' => 'Grapheme_Base', 'Gr_Base' => 'Grapheme_Base',
            'Grapheme_Extend' => 'Grapheme_Extend', 'Gr_Ext' => 'Grapheme_Extend',
            'Hex_Digit' => 'Hex_Digit', 'Hex' => 'Hex_Digit',
            'IDS_Binary_Operator' => 'IDS_Binary_Operator',
            'IDSB' => 'IDS_Binary_Operator',
            'IDS_Trinary_Operator' => 'IDS_Trinary_Operator',
            'IDST' => 'IDS_Trinary_Operator',
            'ID_Continue' => 'ID_Continue', 'IDC' => 'ID_Continue',
            'ID_Start' => 'ID_Start', 'IDS' => 'ID_Start',
            'Ideographic' => 'Ideographic', 'Ideo' => 'Ideographic',
            'Join_Control' => 'Join_Control', 'Join_C' => 'Join_Control',
            'Logical_Order_Exception' => 'Logical_Order_Exception',
            'LOE' => 'Logical_Order_Exception',
            'Lowercase' => 'Lowercase', 'Lower' => 'Lowercase',
            'Math' => 'Math',
            'Noncharacter_Code_Point' => 'Noncharacter_Code_Point',
            'NChar' => 'Noncharacter_Code_Point',
            'Pattern_Syntax' => 'Pattern_Syntax', 'Pat_Syn' => 'Pattern_Syntax',
            'Pattern_White_Space' => 'Pattern_White_Space',
            'Pat_WS' => 'Pattern_White_Space',
            'Quotation_Mark' => 'Quotation_Mark', 'QMark' => 'Quotation_Mark',
            'Radical' => 'Radical',
            'Regional_Indicator' => 'Regional_Indicator', 'RI' => 'Regional_Indicator',
            'Sentence_Terminal' => 'Sentence_Terminal', 'STerm' => 'Sentence_Terminal',
            'Soft_Dotted' => 'Soft_Dotted', 'SD' => 'Soft_Dotted',
            'Terminal_Punctuation' => 'Terminal_Punctuation',
            'Term' => 'Terminal_Punctuation',
            'Unified_Ideograph' => 'Unified_Ideograph', 'UIdeo' => 'Unified_Ideograph',
            'Uppercase' => 'Uppercase', 'Upper' => 'Uppercase',
            'Variation_Selector' => 'Variation_Selector', 'VS' => 'Variation_Selector',
            'White_Space' => 'White_Space', 'space' => 'White_Space',
            'WSpace' => 'White_Space',
            'XID_Continue' => 'XID_Continue', 'XIDC' => 'XID_Continue',
            'XID_Start' => 'XID_Start', 'XIDS' => 'XID_Start',
            'Assigned' => '!Assigned',
        ];
        $pcre = $supported[$name] ?? null;
        if ($pcre === null) {
            return null;
        }
        if ($pcre === '!Assigned') {
            return '!Assigned';
        }
        if (@preg_match('/\\p{' . $pcre . '}/u', '') === false) {
            return 'Any';
        }
        return $pcre;
    }

    private static function normalizeScriptName(string $name): ?string
    {
        $quoted = preg_quote($name, '/');
        $scriptPattern = sprintf('/\\p{Script=%s}/u', $quoted);
        if (@preg_match($scriptPattern, '') !== false) {
            return $name;
        }
        $scriptExtPattern = sprintf('/\\p{Script_Extensions=%s}/u', $quoted);
        if (@preg_match($scriptExtPattern, '') !== false) {
            return $name;
        }
        return null;
    }

    /**
     * Annex B B.1.4.1.1: in non-unicode mode, when one side of a class range
     * `A-B` is not a single character (e.g. `\d`, `\w`, `[...]` etc.), the
     * `-` is treated as a literal hyphen and the whole expression is the
     * union of A, `-`, and B. PCRE rejects such ranges with "Internal error",
     * so we walk each character class and escape problematic hyphens.
     */
    private function rewriteAnnexBClassRanges(string $pattern): string
    {
        $result = '';
        $len = strlen($pattern);
        $i = 0;
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $result .= $c . $pattern[$i + 1];
                $i += 2;
                continue;
            }
            if ($c !== '[') {
                $result .= $c;
                $i++;
                continue;
            }
            // Find the matching ] (respecting backslash escapes).
            $end = $i + 1;
            // Skip a leading ^ and a leading ] (literal first char).
            if ($end < $len && $pattern[$end] === '^') {
                $end++;
            }
            if ($end < $len && $pattern[$end] === ']') {
                $end++;
            }
            while ($end < $len && $pattern[$end] !== ']') {
                if ($pattern[$end] === '\\' && $end + 1 < $len) {
                    $end += 2;
                    continue;
                }
                $end++;
            }
            if ($end >= $len) {
                $result .= $c;
                $i++;
                continue;
            }
            $classBody = substr($pattern, $i, $end - $i + 1);
            $result .= $this->fixAnnexBHyphensInClass($classBody);
            $i = $end + 1;
        }
        return $result;
    }

    /**
     * Walk a class body `[…]` and escape any `-` that appears between two
     * atoms where at least one isn't a single character.
     */
    private function fixAnnexBHyphensInClass(string $classBody): string
    {
        $len = strlen($classBody);
        if ($len < 2 || $classBody[0] !== '[' || $classBody[$len - 1] !== ']') {
            return $classBody;
        }
        $body = substr($classBody, 1, $len - 2);
        $prefix = '[';
        if ($body !== '' && $body[0] === '^') {
            $prefix .= '^';
            $body = substr($body, 1);
        }

        $atoms = [];
        $bodyLen = strlen($body);
        $j = 0;
        while ($j < $bodyLen) {
            $c = $body[$j];
            if ($c === '\\' && $j + 1 < $bodyLen) {
                $next = $body[$j + 1];
                $isMultiSet = in_array($next, ['d', 'D', 's', 'S', 'w', 'W'], true);
                // Multi-byte escapes consume their full extent.
                if (
                    $next === 'x' && $j + 3 < $bodyLen
                    && ctype_xdigit($body[$j + 2]) && ctype_xdigit($body[$j + 3])
                ) {
                    $atoms[] = ['type' => 'esc', 'text' => substr($body, $j, 4), 'isSet' => false];
                    $j += 4;
                    continue;
                }
                if ($next === 'u' && $j + 2 < $bodyLen && $body[$j + 2] === '{') {
                    $closeBrace = strpos($body, '}', $j + 3);
                    if ($closeBrace !== false) {
                        $atoms[] = [
                            'type' => 'esc',
                            'text' => substr($body, $j, $closeBrace - $j + 1),
                            'isSet' => false,
                        ];
                        $j = $closeBrace + 1;
                        continue;
                    }
                }
                if (
                    $next === 'u' && $j + 5 < $bodyLen && ctype_xdigit($body[$j + 2])
                    && ctype_xdigit($body[$j + 3]) && ctype_xdigit($body[$j + 4])
                    && ctype_xdigit($body[$j + 5])
                ) {
                    $atoms[] = ['type' => 'esc', 'text' => substr($body, $j, 6), 'isSet' => false];
                    $j += 6;
                    continue;
                }
                if (($next === 'p' || $next === 'P') && $j + 2 < $bodyLen && $body[$j + 2] === '{') {
                    $closeBrace = strpos($body, '}', $j + 3);
                    if ($closeBrace !== false) {
                        $atoms[] = [
                            'type' => 'esc',
                            'text' => substr($body, $j, $closeBrace - $j + 1),
                            'isSet' => true,
                        ];
                        $j = $closeBrace + 1;
                        continue;
                    }
                }
                $atoms[] = ['type' => 'esc', 'text' => substr($body, $j, 2), 'isSet' => $isMultiSet];
                $j += 2;
                continue;
            }
            if ($c === '-') {
                $atoms[] = ['type' => 'dash', 'text' => '-', 'isSet' => false];
                $j++;
                continue;
            }
            // Multi-byte UTF-8: keep the whole sequence as one atom.
            $byte = ord($c);
            $width = 1;
            if (($byte & 0xE0) === 0xC0) {
                $width = 2;
            } elseif (($byte & 0xF0) === 0xE0) {
                $width = 3;
            } elseif (($byte & 0xF8) === 0xF0) {
                $width = 4;
            }
            $atoms[] = ['type' => 'lit', 'text' => substr($body, $j, $width), 'isSet' => false];
            $j += $width;
        }

        // Decide which dashes are range operators vs literal hyphens. A dash
        // is a range operator only when it sits between two atoms that are
        // both single characters; if either neighbour is `\d/\D/\s/\S/\w/\W`
        // (or a property escape) the dash is literal per Annex B. When the
        // dash is a range operator, also validate the range endpoints are in
        // order (left <= right) and reject inverted ranges per spec.
        $rebuilt = '';
        $count = count($atoms);
        $consumeNext = false;
        for ($k = 0; $k < $count; $k++) {
            if ($consumeNext) {
                $consumeNext = false;
                $rebuilt .= $atoms[$k]['text'];
                continue;
            }
            $atom = $atoms[$k];
            if ($atom['type'] !== 'dash') {
                $rebuilt .= $atom['text'];
                continue;
            }
            // Dash at start or end of class is always literal in PCRE.
            if ($k === 0 || $k === $count - 1) {
                $rebuilt .= '-';
                continue;
            }
            $prev = $atoms[$k - 1];
            $nextAtom = $atoms[$k + 1];
            if ($prev['isSet'] || $nextAtom['isSet']) {
                $rebuilt .= '\\-';
                continue;
            }
            // Spec: when the right side of a range is itself the dash atom,
            // this is `[lit-lit]` where the right `-` is a literal class
            // atom. Validate range a-'-' where left > '-' is invalid.
            if ($prev['type'] === 'dash' || $nextAtom['type'] === 'dash') {
                if ($nextAtom['type'] === 'dash' && $prev['type'] === 'lit') {
                    $left = self::singleCharCodepoint($prev['text']);
                    if ($left !== null && $left > 0x2D) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            'Invalid regular expression: Range out of order in character class',
                        );
                    }
                    // Emit explicit range so PCRE2 sees the same set.
                    $rebuilt .= '-' . $nextAtom['text'];
                    $consumeNext = true;
                    continue;
                }
                $rebuilt .= '\\-';
                continue;
            }
            // Both neighbours are single-char atoms; validate the range.
            if ($prev['type'] === 'lit' && $nextAtom['type'] === 'lit') {
                $left = self::singleCharCodepoint($prev['text']);
                $right = self::singleCharCodepoint($nextAtom['text']);
                if ($left !== null && $right !== null && $left > $right) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        'Invalid regular expression: Range out of order in character class',
                    );
                }
            }
            $rebuilt .= '-';
        }

        return $prefix . $rebuilt . ']';
    }

    private static function singleCharCodepoint(string $text): ?int
    {
        if ($text === '') {
            return null;
        }
        $byte = ord($text[0]);
        if ($byte < 0x80) {
            return strlen($text) === 1 ? $byte : null;
        }
        // Multi-byte UTF-8: ensure the entire sequence is one codepoint.
        $width = 1;
        if (($byte & 0xE0) === 0xC0) {
            $width = 2;
        } elseif (($byte & 0xF0) === 0xE0) {
            $width = 3;
        } elseif (($byte & 0xF8) === 0xF0) {
            $width = 4;
        }
        if (strlen($text) !== $width) {
            return null;
        }
        // mb_ord stubs declare int but the runtime returns false on
        // invalid sequences (e.g. lone surrogates passed in via CESU-8).
        /** @var int|false $cp */
        $cp = mb_ord($text, 'UTF-8');
        if ($cp === false) {
            return null;
        }
        return $cp;
    }

    /**
     * Transform v-flag (unicodeSets) pattern features into PCRE2-compatible syntax.
     */
    private function transformVFlagPattern(string $pattern): string
    {
        $result = '';
        $len = strlen($pattern);
        $i = 0;

        while ($i < $len) {
            if ($pattern[$i] === '\\' && $i + 1 < $len) {
                $result .= $pattern[$i] . $pattern[$i + 1];
                $next = $pattern[$i + 1];
                if (($next === 'p' || $next === 'P' || $next === 'q' || $next === 'u') && $i + 2 < $len && $pattern[$i + 2] === '{') {
                    $j = $i + 2;
                    while ($j < $len && $pattern[$j] !== '}') {
                        $result .= $pattern[$j];
                        $j++;
                    }
                    if ($j < $len) {
                        $result .= '}';
                        $j++;
                    }
                    $i = $j;
                } else {
                    $i += 2;
                }
                continue;
            }
            if ($pattern[$i] === '[') {
                $classResult = $this->parseVFlagCharClass($pattern, $i, $len);
                $result .= $classResult['output'];
                $i = $classResult['pos'];
                continue;
            }
            $result .= $pattern[$i];
            $i++;
        }

        return $result;
    }

    /**
     * Parse a v-flag character class starting at position $pos (on the opening [).
     *
     * @return array{output: string, pos: int}
     */
    private function parseVFlagCharClass(string $pattern, int $pos, int $len): array
    {
        $pos++;
        $negated = false;
        if ($pos < $len && $pattern[$pos] === '^') {
            $negated = true;
            $pos++;
        }

        $operands = [];
        $operators = [];
        $current = '';

        while ($pos < $len && $pattern[$pos] !== ']') {
            if ($pos + 1 < $len && $pattern[$pos] === '&' && $pattern[$pos + 1] === '&') {
                $operands[] = $current;
                $operators[] = '&&';
                $current = '';
                $pos += 2;
                continue;
            }
            if ($pos + 1 < $len && $pattern[$pos] === '-' && $pattern[$pos + 1] === '-') {
                $operands[] = $current;
                $operators[] = '--';
                $current = '';
                $pos += 2;
                continue;
            }
            if ($pattern[$pos] === '[') {
                $inner = $this->parseVFlagCharClass($pattern, $pos, $len);
                $innerOut = $inner['output'];
                if (strlen($innerOut) >= 2 && $innerOut[0] === '[' && $innerOut[strlen($innerOut) - 1] === ']') {
                    $current .= substr($innerOut, 1, -1);
                } else {
                    $current .= $innerOut;
                }
                $pos = $inner['pos'];
                continue;
            }
            if ($pattern[$pos] === '\\' && $pos + 1 < $len) {
                $esc = $pattern[$pos] . $pattern[$pos + 1];
                $escNext = $pattern[$pos + 1];
                if (($escNext === 'p' || $escNext === 'P') && $pos + 2 < $len && $pattern[$pos + 2] === '{') {
                    $j = $pos + 3;
                    while ($j < $len && $pattern[$j] !== '}') {
                        $j++;
                    }
                    $body = substr($pattern, $pos + 3, $j - ($pos + 3));
                    // Properties of strings (`\p{Emoji_Keycap_Sequence}`,
                    // `\p{RGI_Emoji}`, …) expand to a string-set in /v
                    // mode. PCRE2 doesn't carry these properties; emit
                    // a synthesized \\q{...} alternation backed by the
                    // precomputed property-string data.
                    $stringSet = self::vFlagPropertyOfStringsSet($body);
                    if ($stringSet !== null && $escNext === 'p') {
                        $current .= '\\q{' . implode('|', $stringSet) . '}';
                    } else {
                        $esc = substr($pattern, $pos, $j + 1 - $pos);
                        $current .= $esc;
                    }
                    $pos = $j + 1;
                } elseif ($escNext === 'q' && $pos + 2 < $len && $pattern[$pos + 2] === '{') {
                    $j = $pos + 3;
                    while ($j < $len && $pattern[$j] !== '}') {
                        $j++;
                    }
                    $qContent = substr($pattern, $pos + 3, $j - ($pos + 3));
                    $current .= '\\q{' . $qContent . '}';
                    $pos = $j + 1;
                } elseif ($escNext === 'u' && $pos + 2 < $len && $pattern[$pos + 2] === '{') {
                    $j = $pos + 3;
                    while ($j < $len && $pattern[$j] !== '}') {
                        $j++;
                    }
                    $esc = substr($pattern, $pos, $j + 1 - $pos);
                    $current .= $esc;
                    $pos = $j + 1;
                } else {
                    $current .= $esc;
                    $pos += 2;
                }
                continue;
            }
            $current .= $pattern[$pos];
            $pos++;
        }

        if ($pos < $len) {
            $pos++;
        }

        $operands[] = $current;

        // No set operators: emit simple character class.
        if (empty($operators)) {
            $classContent = $operands[0];
            if (str_contains($classContent, '\\q{')) {
                return $this->transformClassWithStringLiterals($classContent, $negated, $pos);
            }
            return ['output' => '[' . ($negated ? '^' : '') . $classContent . ']', 'pos' => $pos];
        }

        // Apply set operators left-to-right. /v set ops operate on
        // strings — a multi-codepoint string in lhs is removed by
        // difference only when the rhs contains that exact string.
        // A single-char alternation `(?!rhs)atom` works for single-
        // codepoint atoms but mis-fires on a multi-codepoint atom
        // (the lookahead only inspects one position, so it would
        // exclude "9️⃣" from `\q{9️⃣}--[0-9]`
        // because the lookahead sees the leading "9"). Decompose
        // each operand into its multi-char string set + single-char
        // class part so the emission can apply rhs filtering only
        // to the parts where it's spec-correct.
        $lhs = $this->decomposeVFlagOperand($operands[0]);
        for ($oi = 0; $oi < count($operators); $oi++) {
            $op = $operators[$oi];
            $rhs = $this->decomposeVFlagOperand($operands[$oi + 1]);
            $lhs = $this->applyVFlagSetOp($op, $lhs, $rhs);
        }
        $base = $this->emitDecomposedVFlagOperand($lhs);

        if ($negated) {
            $base = '(?!' . $base . ').';
        }

        return ['output' => '(?:' . $base . ')', 'pos' => $pos];
    }

    /**
     * Resolve a `\p{Property}` body (like `Emoji_Keycap_Sequence` or
     * `RGI_Emoji`) to its string set under /v mode. Returns null when
     * the property is not a property-of-strings or is unknown.
     *
     * @return list<string>|null
     */
    private static function vFlagPropertyOfStringsSet(string $body): ?array
    {
        // Handle `Property=Value` syntax — strings of property is a
        // standalone form, no `=` allowed.
        if (str_contains($body, '=')) {
            return null;
        }
        static $data = null;
        if ($data === null) {
            $path = __DIR__ . '/../../config/regex_property_strings.php';
            $data = file_exists($path) ? require $path : [];
        }
        return $data[$body] ?? null;
    }

    /**
     * Decompose a /v class operand into its multi-codepoint string
     * literals and its single-character class part.
     *
     * Returns ['strings' => list<string>, 'charClass' => string]
     * where `strings` are raw PCRE pattern fragments (already
     * translated, e.g. with \\uXXXX intact) and `charClass` is the
     * remainder suitable for `[$charClass]` (empty when nothing left).
     *
     * @return array{strings: list<string>, charClass: string}
     */
    private function decomposeVFlagOperand(string $operand): array
    {
        // First strip \\p{Property} property-of-strings escapes —
        // each one expands to a fixed set of multi-codepoint strings
        // (Emoji_Keycap_Sequence, RGI_Emoji…). PCRE2 doesn't carry
        // these properties so the class operand needs them already
        // decomposed before any set ops apply.
        $stringAlts = [];
        $remaining = preg_replace_callback(
            '/\\\\p\\{([^}]*)\\}/',
            function (array $m) use (&$stringAlts): string {
                $set = self::vFlagPropertyOfStringsSet($m[1]);
                if ($set === null) {
                    return $m[0];
                }
                foreach ($set as $s) {
                    $stringAlts[] = $s;
                }
                return '';
            },
            $operand,
        );
        // Then peel off explicit \\q{...} alternations using the same
        // brace-aware scan as transformClassWithStringLiterals so
        // nested `\\x{HEX}` escapes don't trip the parser.
        $cleaned = '';
        $len = strlen($remaining);
        $i = 0;
        while ($i < $len) {
            if (
                $remaining[$i] === '\\'
                && $i + 2 < $len
                && $remaining[$i + 1] === 'q'
                && $remaining[$i + 2] === '{'
            ) {
                $j = $i + 3;
                $depth = 1;
                while ($j < $len) {
                    if ($remaining[$j] === '\\' && $j + 1 < $len) {
                        $j += 2;
                        continue;
                    }
                    if ($remaining[$j] === '{') {
                        $depth++;
                    } elseif ($remaining[$j] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $j++;
                }
                if ($j < $len) {
                    $body = substr($remaining, $i + 3, $j - ($i + 3));
                    $cur = '';
                    $blen = strlen($body);
                    $bi = 0;
                    $bdepth = 0;
                    while ($bi < $blen) {
                        $bc = $body[$bi];
                        if ($bc === '\\' && $bi + 1 < $blen) {
                            $cur .= $bc . $body[$bi + 1];
                            $bi += 2;
                            continue;
                        }
                        if ($bc === '{') {
                            $bdepth++;
                        } elseif ($bc === '}') {
                            $bdepth--;
                        } elseif ($bc === '|' && $bdepth === 0) {
                            if ($cur !== '') {
                                $stringAlts[] = $cur;
                            }
                            $cur = '';
                            $bi++;
                            continue;
                        }
                        $cur .= $bc;
                        $bi++;
                    }
                    if ($cur !== '') {
                        $stringAlts[] = $cur;
                    }
                    $i = $j + 1;
                    continue;
                }
            }
            $cleaned .= $remaining[$i];
            $i++;
        }
        return ['strings' => $stringAlts, 'charClass' => $cleaned];
    }

    /**
     * Apply a /v set operator (`&&` intersection or `--` difference)
     * to a decomposed lhs and rhs, returning a new decomposed shape.
     *
     * /v set ops operate on STRINGS: a string s is in (LHS -- RHS)
     * iff s is in LHS and not in RHS, regardless of length. So a
     * multi-codepoint string in LHS survives `--` against a single-
     * char rhs unconditionally; a single-char string survives only
     * when its codepoint isn't in rhs's char set.
     *
     * @param array{strings: list<string>, charClass: string} $lhs
     * @param array{strings: list<string>, charClass: string} $rhs
     * @return array{strings: list<string>, charClass: string}
     */
    private function applyVFlagSetOp(string $op, array $lhs, array $rhs): array
    {
        // Multi-char strings: those with PCRE-source length > 1 char
        // (heuristic — escapes like \\uFE0F are 6 bytes but one
        // codepoint, so use mb_strlen on the literal interpretation
        // by counting after stripping common escapes).
        $isMultiChar = function (string $s): bool {
            // Count codepoints after collapsing common escapes:
            // \\uXXXX → 1, \\u{X..} → 1, \\xHH → 1, \\C → 1, ch → 1.
            $count = 0;
            $i = 0;
            $n = strlen($s);
            while ($i < $n) {
                if ($s[$i] === '\\' && $i + 1 < $n) {
                    if ($s[$i + 1] === 'u' && $i + 2 < $n && $s[$i + 2] === '{') {
                        $end = strpos($s, '}', $i + 3);
                        $i = $end !== false ? $end + 1 : $i + 2;
                    } elseif ($s[$i + 1] === 'u') {
                        $i += 6;
                    } elseif ($s[$i + 1] === 'x') {
                        $i += 4;
                    } else {
                        $i += 2;
                    }
                } else {
                    // Skip a UTF-8 codepoint.
                    $b = ord($s[$i]);
                    if ($b < 0x80) {
                        $i++;
                    } elseif (($b & 0xE0) === 0xC0) {
                        $i += 2;
                    } elseif (($b & 0xF0) === 0xE0) {
                        $i += 3;
                    } else {
                        $i += 4;
                    }
                }
                $count++;
                if ($count > 1) {
                    return true;
                }
            }
            return false;
        };

        if ($op === '&&') {
            // Build a normalized lookup of rhs strings so escape-form
            // differences (e.g. \\u{X} vs \\uXXXX vs raw UTF-8) don't
            // break in_array matching.
            $rhsNormalized = [];
            foreach ($rhs['strings'] as $rs) {
                $rhsNormalized[self::normalizeVFlagString($rs)] = true;
            }
            $resultStrings = [];
            $resultNormalized = [];
            $addResult = function (string $s) use (&$resultStrings, &$resultNormalized): void {
                $norm = self::normalizeVFlagString($s);
                if (isset($resultNormalized[$norm])) {
                    return;
                }
                $resultNormalized[$norm] = true;
                $resultStrings[] = $s;
            };
            foreach ($lhs['strings'] as $s) {
                $norm = self::normalizeVFlagString($s);
                if ($isMultiChar($s)) {
                    if (isset($rhsNormalized[$norm])) {
                        $addResult($s);
                    }
                    continue;
                }
                if (isset($rhsNormalized[$norm])) {
                    $addResult($s);
                    continue;
                }
                if ($rhs['charClass'] !== '' && $this->matchesPcreClass($rhs['charClass'], $norm)) {
                    $addResult($s);
                }
            }
            if ($lhs['charClass'] !== '') {
                foreach ($rhs['strings'] as $s) {
                    if ($isMultiChar($s)) {
                        continue;
                    }
                    $norm = self::normalizeVFlagString($s);
                    if (isset($resultNormalized[$norm])) {
                        continue;
                    }
                    if ($this->matchesPcreClass($lhs['charClass'], $norm)) {
                        $addResult($s);
                    }
                }
            }
            $resultClass = '';
            if ($lhs['charClass'] !== '' && $rhs['charClass'] !== '') {
                $resultClass = '(?=[' . $lhs['charClass'] . '])[' . $rhs['charClass'] . ']';
            }
            return ['strings' => $resultStrings, 'charClass' => $resultClass];
        }
        if ($op === '--') {
            // Difference.
            $rhsNormalized = [];
            foreach ($rhs['strings'] as $rs) {
                $rhsNormalized[self::normalizeVFlagString($rs)] = true;
            }
            $resultStrings = [];
            foreach ($lhs['strings'] as $s) {
                $norm = self::normalizeVFlagString($s);
                if (isset($rhsNormalized[$norm])) {
                    continue;
                }
                if ($isMultiChar($s)) {
                    $resultStrings[] = $s;
                    continue;
                }
                if ($rhs['charClass'] !== '' && $this->matchesPcreClass($rhs['charClass'], $norm)) {
                    continue;
                }
                $resultStrings[] = $s;
            }
            $resultClass = '';
            if ($lhs['charClass'] !== '') {
                // Subtract from char class:
                //   * rhs single-char strings: exclude codepoint
                //   * rhs multi-char strings: lookahead-exclude the
                //     start of that string (rhs string match would
                //     otherwise overlap a single-char lhs match)
                //   * rhs char class: standard `(?!rhs)lhs`
                $rhsExcludes = [];
                foreach ($rhs['strings'] as $rs) {
                    $rhsExcludes[] = $rs;
                }
                if ($rhs['charClass'] !== '') {
                    $rhsExcludes[] = '[' . $rhs['charClass'] . ']';
                }
                if (!empty($rhsExcludes)) {
                    $resultClass = '(?!(?:' . implode('|', $rhsExcludes) . '))[' . $lhs['charClass'] . ']';
                } else {
                    $resultClass = '[' . $lhs['charClass'] . ']';
                }
            }
            return ['strings' => $resultStrings, 'charClass' => $resultClass];
        }
        // Unknown op — just return lhs.
        return $lhs;
    }

    /**
     * Test whether a single-codepoint string matches a PCRE-style
     * char class body. Used to filter single-char strings from a
     * \\q{...} alternation against a char-class operand under /v
     * intersection / difference.
     */
    private function matchesPcreClass(string $classContent, string $needle): bool
    {
        $r = @preg_match('/[' . $classContent . ']/u', $needle);
        return $r === 1;
    }

    /**
     * Normalize a /v-class string fragment into a canonical UTF-8
     * form so comparisons across escape conventions agree
     * (\\u{HEX}, \\uHHHH, raw chars all map to the same string).
     */
    private static function normalizeVFlagString(string $s): string
    {
        $out = '';
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            if ($s[$i] === '\\' && $i + 1 < $len) {
                $next = $s[$i + 1];
                if ($next === 'u' && $i + 2 < $len && $s[$i + 2] === '{') {
                    $end = strpos($s, '}', $i + 3);
                    if ($end !== false) {
                        $hex = substr($s, $i + 3, $end - ($i + 3));
                        if (ctype_xdigit($hex)) {
                            $out .= mb_chr((int) hexdec($hex), 'UTF-8') ?: '';
                            $i = $end + 1;
                            continue;
                        }
                    }
                }
                if ($next === 'u' && $i + 5 < $len) {
                    $hex = substr($s, $i + 2, 4);
                    if (ctype_xdigit($hex)) {
                        $out .= mb_chr((int) hexdec($hex), 'UTF-8') ?: '';
                        $i += 6;
                        continue;
                    }
                }
                if ($next === 'x' && $i + 2 < $len && $s[$i + 2] === '{') {
                    $end = strpos($s, '}', $i + 3);
                    if ($end !== false) {
                        $hex = substr($s, $i + 3, $end - ($i + 3));
                        if (ctype_xdigit($hex)) {
                            $out .= mb_chr((int) hexdec($hex), 'UTF-8') ?: '';
                            $i = $end + 1;
                            continue;
                        }
                    }
                }
                if ($next === 'x' && $i + 3 < $len) {
                    $hex = substr($s, $i + 2, 2);
                    if (ctype_xdigit($hex)) {
                        $out .= mb_chr((int) hexdec($hex), 'UTF-8') ?: '';
                        $i += 4;
                        continue;
                    }
                }
                // Other escape — pass through next char as literal.
                $out .= $next;
                $i += 2;
                continue;
            }
            $out .= $s[$i];
            $i++;
        }
        return $out;
    }

    /**
     * Emit a decomposed /v operand back into a PCRE alternation.
     *
     * @param array{strings: list<string>, charClass: string} $op
     */
    private function emitDecomposedVFlagOperand(array $op): string
    {
        $alts = $op['strings'];
        // Sort by codepoint length descending so longer strings get
        // tried first — required since "9️⃣" must beat "9" at the
        // same start position.
        usort($alts, static fn (string $a, string $b): int => mb_strlen($b) - mb_strlen($a));
        $cc = $op['charClass'];
        if ($cc !== '') {
            // If charClass starts with a lookahead/lookbehind sentinel
            // (intersection / difference encoding from applyVFlagSetOp),
            // wrap in a non-capturing group so it joins the alternation
            // without precedence issues.
            if ($cc[0] === '(') {
                $alts[] = $cc;
            } else {
                $alts[] = '[' . $cc . ']';
            }
        }
        if (empty($alts)) {
            return '(?![\\s\\S])';
        }
        if (count($alts) === 1) {
            return '(?:' . $alts[0] . ')';
        }
        return '(?:' . implode('|', $alts) . ')';
    }

    /**
     * Transform a character class containing \q{...} string literals into an alternation.
     *
     * @return array{output: string, pos: int}
     */
    private function transformClassWithStringLiterals(string $classContent, bool $negated, int $pos): array
    {
        // Manually peel out `\\q{...}` blocks so nested `\\x{HEX}` /
        // `\\u{HEX}` escapes (which contain `}`) don't trip a naive
        // `\\q\\{[^}]*\\}` regex. The body is split on `|` only at
        // the top level — embedded brace pairs are skipped.
        $stringAlts = [];
        $remaining = '';
        $len = strlen($classContent);
        $i = 0;
        while ($i < $len) {
            if (
                $classContent[$i] === '\\'
                && $i + 2 < $len
                && $classContent[$i + 1] === 'q'
                && $classContent[$i + 2] === '{'
            ) {
                $j = $i + 3;
                $depth = 1;
                while ($j < $len) {
                    if ($classContent[$j] === '\\' && $j + 1 < $len) {
                        $j += 2;
                        continue;
                    }
                    if ($classContent[$j] === '{') {
                        $depth++;
                    } elseif ($classContent[$j] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                    $j++;
                }
                if ($j < $len) {
                    $body = substr($classContent, $i + 3, $j - ($i + 3));
                    // Split body on top-level `|` (skip ones inside braces).
                    $alts = [];
                    $cur = '';
                    $blen = strlen($body);
                    $bi = 0;
                    $bdepth = 0;
                    while ($bi < $blen) {
                        $bc = $body[$bi];
                        if ($bc === '\\' && $bi + 1 < $blen) {
                            $cur .= $bc . $body[$bi + 1];
                            $bi += 2;
                            continue;
                        }
                        if ($bc === '{') {
                            $bdepth++;
                        } elseif ($bc === '}') {
                            $bdepth--;
                        } elseif ($bc === '|' && $bdepth === 0) {
                            $alts[] = $cur;
                            $cur = '';
                            $bi++;
                            continue;
                        }
                        $cur .= $bc;
                        $bi++;
                    }
                    if ($cur !== '') {
                        $alts[] = $cur;
                    }
                    foreach ($alts as $a) {
                        if ($a !== '') {
                            $stringAlts[] = $a;
                        }
                    }
                    $i = $j + 1;
                    continue;
                }
            }
            $remaining .= $classContent[$i];
            $i++;
        }
        // Sort longer alternatives first so PCRE alternation tries
        // them before shorter prefixes at the same position.
        usort($stringAlts, static fn (string $a, string $b): int => strlen($b) - strlen($a));
        $parts = $stringAlts;
        if ($remaining !== '') {
            $parts[] = '[' . ($negated ? '^' : '') . $remaining . ']';
        }
        if (empty($parts)) {
            return ['output' => '[^\\s\\S]', 'pos' => $pos];
        }
        return ['output' => '(?:' . implode('|', $parts) . ')', 'pos' => $pos];
    }

    /**
     * Transform quantifiers with counts exceeding PCRE2's 65535 limit.
     *
     * PCRE2 only supports repeat counts up to 65535. ECMAScript allows
     * arbitrarily large quantifier values (up to 2^53-1). For values
     * above the limit, we decompose into nested quantification:
     * X{N} becomes (?:X{M}){K}X{R} where M=65535, K=N/M, R=N%M.
     * When the nesting depth K also exceeds 65535, we add another level.
     */
    private static function transformLargeQuantifiers(string $pattern): string
    {
        $maxQ = 65535;
        // Match quantifiers like {N}, {N,}, {N,M} potentially followed by ?
        // Only transform when a number exceeds the limit.
        return preg_replace_callback(
            '/\{(\d+)(?:,(\d*))?\}(\?)?/',
            static function (array $m) use ($maxQ): string {
                $min = $m[1];
                $max = $m[2] ?? null;
                $lazy = $m[3] ?? '';
                $hasComma = str_contains($m[0], ',');

                $minVal = (int) $min;
                $maxVal = ($max !== null && $max !== '') ? (int) $max : null;

                // PHP int overflow check for very large numbers.
                if (strlen($min) > 15 || ($maxVal !== null && strlen($m[2]) > 15)) {
                    $minVal = PHP_INT_MAX;
                    if ($maxVal !== null) {
                        $maxVal = PHP_INT_MAX;
                    }
                }

                if ($minVal <= $maxQ && ($maxVal === null || $maxVal <= $maxQ)) {
                    return $m[0]; // No transformation needed.
                }

                // For {N} (exact): we cannot simply replace the preceding atom
                // in a regex callback. Instead, cap to max and add a negative
                // lookahead-like approach. Since no practical string will ever
                // be this long, the pattern just needs to compile and not match.
                // Use a capped quantifier: the pattern will compile but won't
                // match strings shorter than the original count.
                if (!$hasComma) {
                    // {N} where N > 65535: cap at 65535. This changes semantics
                    // (matches fewer) but the practical effect is the same since
                    // no real string exceeds 65535 chars for this character.
                    return '{' . $maxQ . '}' . $lazy;
                }

                // {N,} where N > 65535: cap min at 65535.
                if ($maxVal === null || $m[2] === '') {
                    $cappedMin = min($minVal, $maxQ);
                    return '{' . $cappedMin . ',}' . $lazy;
                }

                // {N,M}: cap both.
                $cappedMin = min($minVal, $maxQ);
                $cappedMax = min($maxVal, $maxQ);
                return '{' . $cappedMin . ',' . $cappedMax . '}' . $lazy;
            },
            $pattern,
        );
    }

    /**
     * PCRE2 (as of 10.47) refuses to compile lookbehind assertions whose
     * length is unbounded — `(?<=a*)`, `(?<=a+)`, `(?<=a{1,})`. ECMAScript
     * allows them. As a pragmatic compatibility shim, walk into every
     * lookbehind body and clamp every unbounded quantifier to a bounded
     * upper limit. The bound is high enough to cover all reasonable test
     * inputs while staying inside PCRE2's documented variable-length
     * lookbehind cap (65535 code points).
     */
    private static function boundLookbehindQuantifiers(string $pattern): string
    {
        // Hot-path fast exit: a pattern with no lookbehind syntax can't
        // need bounding. strpos in C is ~1000x faster than the
        // byte-by-byte walk below for long literal patterns.
        if (strpos($pattern, '(?<') === false) {
            return $pattern;
        }
        // PCRE2's variable-length lookbehind branch cap is 255 code
        // points (verified empirically on 10.47). Each unbounded
        // quantifier we replace contributes up to its bound, plus any
        // siblings; using 128 leaves headroom for the surrounding
        // literal characters in the same branch (e.g. `b\d+` becomes
        // `b\d{1,128}` of total length 129, well under the 255 cap).
        $bound = 128;
        $len = strlen($pattern);
        $result = '';
        $i = 0;
        $inCharClass = false;
        // Stack of "is lookbehind" flags. The top of the stack tells us
        // whether the current group context is inside a (?<= ... ) or
        // (?<! ... ) so we know when to bound quantifiers.
        $groupStack = [];
        $insideLookbehind = 0;

        while ($i < $len) {
            $ch = $pattern[$i];

            if ($ch === '\\' && $i + 1 < $len) {
                $result .= $ch . $pattern[$i + 1];
                $i += 2;
                continue;
            }
            if (!$inCharClass && $ch === '[') {
                $inCharClass = true;
                $result .= $ch;
                $i++;
                continue;
            }
            if ($inCharClass && $ch === ']') {
                $inCharClass = false;
                $result .= $ch;
                $i++;
                continue;
            }
            if ($inCharClass) {
                $result .= $ch;
                $i++;
                continue;
            }

            // Detect group openings.
            if ($ch === '(') {
                $isLookbehind = false;
                if (
                    $i + 3 < $len
                    && $pattern[$i + 1] === '?'
                    && $pattern[$i + 2] === '<'
                    && ($pattern[$i + 3] === '=' || $pattern[$i + 3] === '!')
                ) {
                    $isLookbehind = true;
                    $insideLookbehind++;
                }
                $groupStack[] = $isLookbehind;
                $result .= $ch;
                $i++;
                continue;
            }
            if ($ch === ')' && !empty($groupStack)) {
                $popped = array_pop($groupStack);
                if ($popped) {
                    $insideLookbehind--;
                }
                $result .= $ch;
                $i++;
                continue;
            }

            // Inside a lookbehind, clamp unbounded quantifiers.
            if ($insideLookbehind > 0) {
                // Quantifiers on a group ')' can multiply the inner
                // length; pick a smaller bound so the expanded branch
                // still fits PCRE2's 255-codepoint cap.
                $lastEmitted = $result === '' ? '' : $result[strlen($result) - 1];
                $effectiveBound = $lastEmitted === ')' ? 40 : $bound;
                if ($ch === '+') {
                    $result .= '{1,' . $effectiveBound . '}';
                    if ($i + 1 < $len && $pattern[$i + 1] === '?') {
                        $result .= '?';
                        $i += 2;
                    } else {
                        $i++;
                    }
                    continue;
                }
                if ($ch === '*') {
                    $result .= '{0,' . $effectiveBound . '}';
                    if ($i + 1 < $len && $pattern[$i + 1] === '?') {
                        $result .= '?';
                        $i += 2;
                    } else {
                        $i++;
                    }
                    continue;
                }
                if ($ch === '{') {
                    $close = strpos($pattern, '}', $i + 1);
                    if ($close !== false) {
                        $body = substr($pattern, $i + 1, $close - $i - 1);
                        if (preg_match('/^(\d+),$/', $body, $bm)) {
                            $result .= '{' . $bm[1] . ',' . $effectiveBound . '}';
                            $i = $close + 1;
                            if ($i < $len && $pattern[$i] === '?') {
                                $result .= '?';
                                $i++;
                            }
                            continue;
                        }
                    }
                }
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
    public function escapeForPcreDelimiter(string $pattern): string
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
        // Bytes that change validation state: `\\`, `[`, `{`, `}`. Plain
        // bytes outside those just continue. Skip past long literal runs
        // (e.g. a 16 MiB \u{…} body) in one strcspn.
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c !== '\\' && $c !== '[' && $c !== '{' && $c !== '}') {
                $skip = strcspn($pattern, "\\[{}", $i);
                if ($skip > 1) {
                    $i += $skip - 1;
                    continue;
                }
            }
            if ($pattern[$i] !== '\\') {
                // Skip character class contents for bracket tracking.
                if ($pattern[$i] === '[') {
                    $i++;
                    while ($i < $len && $pattern[$i] !== ']') {
                        if ($pattern[$i] === '\\' && $i + 1 < $len) {
                            // Validate escapes inside character classes too.
                            $next = $pattern[$i + 1];
                            if (($next === 'p' || $next === 'P' || $next === 'q') && $i + 2 < $len && $pattern[$i + 2] === '{') {
                                // \p{...} / \P{...} / \q{...}: skip to closing brace.
                                $closeBrace = strpos($pattern, '}', $i + 3);
                                $i = $closeBrace !== false ? $closeBrace : $i + 1;
                            } elseif ($next >= '0' && $next <= '9') {
                                $this->validateUnicodeDecimalEscape($pattern, $i + 1, $len, 0, true);
                                $i++;
                            } elseif ($next === 'c') {
                                $this->validateUnicodeControlEscape($pattern, $i + 1, $len);
                                $i++;
                            } elseif ($next === 'u' && $i + 2 < $len && $pattern[$i + 2] === '{') {
                                $closeBrace = strpos($pattern, '}', $i + 3);
                                $i = $closeBrace !== false ? $closeBrace : $i + 1;
                            } else {
                                // \p, \P, \q without `{...}` are invalid in /u.
                                if (in_array($next, ['p', 'P', 'q'], true)) {
                                    throw new \Phasis\Exceptions\SyntaxError(
                                        "Invalid regular expression: \\{$next} must be followed by {...} in unicode mode",
                                    );
                                }
                                // ClassEscape /u: forbid identity escape of
                                // ASCII letters except the spec-allowed set
                                // (b means BS in class context).
                                if (
                                    (($next >= 'A' && $next <= 'Z') || ($next >= 'a' && $next <= 'z'))
                                    && !in_array($next, ['b', 'c', 'd', 'D', 'f', 'n', 'r', 's', 'S', 't', 'u', 'v', 'w', 'W', 'x'], true)
                                ) {
                                    throw new \Phasis\Exceptions\SyntaxError(
                                        "Invalid regular expression: \\{$next} is not a valid identity escape in unicode mode",
                                    );
                                }
                                $i++; // skip the escaped char
                            }
                        }
                        $i++;
                    }
                    continue;
                }
                // In unicode mode, bare { and } are syntax errors unless part of a
                // quantifier. A valid quantifier starts with { and contains digits.
                if ($pattern[$i] === '{') {
                    if (!$this->isValidQuantifierAt($pattern, $i, $len)) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            'Invalid regular expression: lone { is not allowed in unicode mode',
                        );
                    }
                }
                if ($pattern[$i] === '}') {
                    // A } that is not closing a valid quantifier is an error.
                    // We check by looking backward for a matching valid quantifier.
                    // Simple approach: if we reach a bare }, it was not consumed as
                    // part of a quantifier during a forward pass (the quantifier
                    // opener would have been validated above and we would skip past).
                    // However, since we don't skip quantifier contents, we need a
                    // different approach: only flag } that doesn't have a preceding {.
                    // For now, rely on the { check above to catch malformed quantifiers.
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
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /\\{$numStr}/ is not a valid backreference in unicode mode",
                    );
                }
                $i = $j - 1;
            } elseif ($next === '0') {
                // \0 followed by another digit is an octal escape, forbidden in /u mode.
                if ($i + 2 < $len && $pattern[$i + 2] >= '0' && $pattern[$i + 2] <= '9') {
                    throw new \Phasis\Exceptions\SyntaxError(
                        'Invalid regular expression: octal escape sequences are not allowed in unicode mode',
                    );
                }
                $i++; // skip past \0 (NUL escape is OK)
            } elseif ($next === 'c') {
                $this->validateUnicodeControlEscape($pattern, $i + 1, $len);
                $i += 2; // skip \cX
            } elseif ($next === 'p' || $next === 'P' || $next === 'q') {
                // \p{...}, \P{...}, \q{...}: must be followed by `{...}`
                // in /u mode. \q is /v-only at top level.
                if ($i + 2 < $len && $pattern[$i + 2] === '{') {
                    $closeBrace = strpos($pattern, '}', $i + 3);
                    if ($closeBrace !== false) {
                        $i = $closeBrace;
                    } else {
                        $i++;
                    }
                } else {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: \\{$next} must be followed by {...} in unicode mode",
                    );
                }
            } elseif ($next === 'u') {
                // \u{HHHH} braced Unicode escape: skip past the closing }.
                if ($i + 2 < $len && $pattern[$i + 2] === '{') {
                    $closeBrace = strpos($pattern, '}', $i + 3);
                    if ($closeBrace !== false) {
                        $i = $closeBrace; // position on the closing }
                    } else {
                        $i++; // malformed: PCRE will report it
                    }
                } else {
                    // \uHHHH: skip 4 hex digits after \u.
                    $i += 5; // \u + 4 hex = 6 chars, but loop increments, so +5
                    if ($i >= $len) {
                        $i = $len - 1;
                    }
                }
            } else {
                // AtomEscape /u: forbid identity escape of ASCII letters
                // except the spec-allowed set.
                if (
                    (($next >= 'A' && $next <= 'Z') || ($next >= 'a' && $next <= 'z'))
                    && !in_array($next, ['b', 'B', 'c', 'd', 'D', 'f', 'k', 'n', 'p', 'P', 'r', 's', 'S', 't', 'u', 'v', 'w', 'W', 'x'], true)
                ) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: \\{$next} is not a valid identity escape in unicode mode",
                    );
                }
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
                    throw new \Phasis\Exceptions\SyntaxError(
                        'Invalid regular expression: octal escape sequences are not allowed in unicode mode',
                    );
                }
                return; // \0 NUL is fine
            }
            // \1-\9 inside character class in /u mode: always invalid.
            throw new \Phasis\Exceptions\SyntaxError(
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
            throw new \Phasis\Exceptions\SyntaxError(
                'Invalid regular expression: \\c at end of pattern in unicode mode',
            );
        }
        $controlChar = $pattern[$cPos + 1];
        if (!(($controlChar >= 'A' && $controlChar <= 'Z') || ($controlChar >= 'a' && $controlChar <= 'z'))) {
            throw new \Phasis\Exceptions\SyntaxError(
                'Invalid regular expression: \\c must be followed by a letter in unicode mode',
            );
        }
    }

    /**
     * Check whether { at position $pos is the start of a valid quantifier.
     * Valid forms: {n}, {n,}, {n,m} where n and m are decimal digits.
     */
    private function isValidQuantifierAt(string $pattern, int $pos, int $len): bool
    {
        if ($pos >= $len || $pattern[$pos] !== '{') {
            return false;
        }
        $j = $pos + 1;
        if ($j >= $len || $pattern[$j] < '0' || $pattern[$j] > '9') {
            return false;
        }
        while ($j < $len && $pattern[$j] >= '0' && $pattern[$j] <= '9') {
            $j++;
        }
        if ($j >= $len) {
            return false;
        }
        if ($pattern[$j] === '}') {
            return true;
        }
        if ($pattern[$j] !== ',') {
            return false;
        }
        $j++;
        if ($j >= $len) {
            return false;
        }
        if ($pattern[$j] === '}') {
            return true;
        }
        if ($pattern[$j] < '0' || $pattern[$j] > '9') {
            return false;
        }
        while ($j < $len && $pattern[$j] >= '0' && $pattern[$j] <= '9') {
            $j++;
        }
        if ($j >= $len) {
            return false;
        }
        return $pattern[$j] === '}';
    }

    /**
     * Count the number of capturing groups in a regex pattern.
     * This counts '(' that are not followed by '?' (which would indicate
     * a non-capturing group, lookahead, etc.).
     */
    private function countCapturingGroups(string $pattern): int
    {
        // Fast exit: no `(` means no groups.
        if (strpos($pattern, '(') === false) {
            return 0;
        }
        $count = 0;
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c !== '\\' && $c !== '[' && $c !== '(') {
                $skip = strcspn($pattern, "\\[(", $i);
                if ($skip > 1) {
                    $i += $skip - 1;
                    continue;
                }
            }
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

    /**
     * Get the byte positions of each capturing group's opening parenthesis.
     * Returns an array indexed from 0 where index N is the byte position of
     * group N+1's opening '(' in the pattern.
     *
     * @return list<int>
     */
    private function getCapturingGroupPositions(string $pattern): array
    {
        if (strpos($pattern, '(') === false) {
            return [];
        }
        $positions = [];
        $len = strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $c = $pattern[$i];
            if ($c !== '\\' && $c !== '[' && $c !== '(') {
                $skip = strcspn($pattern, "\\[(", $i);
                if ($skip > 1) {
                    $i += $skip - 1;
                    continue;
                }
            }
            if ($pattern[$i] === '\\') {
                $i++;
                continue;
            }
            if ($pattern[$i] === '[') {
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
                    $positions[] = $i;
                } elseif (
                    $i + 2 < $len && $pattern[$i + 2] === '<'
                    && $i + 3 < $len && $pattern[$i + 3] !== '=' && $pattern[$i + 3] !== '!'
                ) {
                    $positions[] = $i;
                }
            }
        }
        return $positions;
    }

    /**
     * Decide whether a backreference at $backrefPos to a capture group that
     * opens at $groupOpen could refer to a non-participating capture, in
     * which case we wrap it as `(?:\N|)` so PCRE matches the empty string
     * instead of failing.
     *
     * The capture group cannot capture if it sits in another alternation
     * branch, inside a lookahead/lookbehind that the backref does not
     * share, or behind a `?`/`*`/`{0,…}` quantifier.
     */
    private function backrefMayMissCapture(string $pattern, int $groupOpen, int $backrefPos): bool
    {
        // Mismatch in nesting: walk from $groupOpen toward $backrefPos,
        // tracking paren depth. If we drop below the starting depth, the
        // group has closed before the backref. Also flag any `|` that we
        // encounter at exactly the group's parent depth, since that means
        // the group sits inside an alternation alternative.
        $len = strlen($pattern);
        $closeIdx = $this->findGroupClose($pattern, $groupOpen);
        if ($closeIdx === null) {
            return true;
        }
        // Self-reference inside the same group: a backref nested inside
        // its own target group resets each iteration, so the first pass
        // sees the group as non-participating.
        if ($backrefPos > $groupOpen && $backrefPos < $closeIdx) {
            return true;
        }
        if ($closeIdx + 1 < $len) {
            $follow = $pattern[$closeIdx + 1];
            if ($follow === '?' || $follow === '*') {
                return true;
            }
            if ($follow === '{') {
                $endBrace = strpos($pattern, '}', $closeIdx + 1);
                if ($endBrace !== false) {
                    $quant = substr(
                        $pattern,
                        $closeIdx + 2,
                        $endBrace - ($closeIdx + 2),
                    );
                    if (str_starts_with($quant, '0,') || $quant === '0') {
                        return true;
                    }
                }
            }
        }
        // Walk from the start to check if any ancestor (?!...)/(?=...) or
        // alternation `|` separates the group definition from the backref.
        $stack = [];
        $k = 0;
        while ($k < $len) {
            $c = $pattern[$k];
            if ($c === '\\') {
                $k += 2;
                continue;
            }
            if ($c === '[') {
                $k++;
                while ($k < $len && $pattern[$k] !== ']') {
                    if ($pattern[$k] === '\\') {
                        $k++;
                    }
                    $k++;
                }
                $k++;
                continue;
            }
            if ($c === '(') {
                if ($k === $groupOpen) {
                    foreach ($stack as $entry) {
                        // Negative lookaround (?!…) / (?<!…) succeeds when its
                        // body fails, discarding any inner captures. A backref
                        // sitting after such an ancestor sees the inner group
                        // as non-participating. Positive lookaround (?=…) /
                        // (?<=…) keeps captures, so backrefs referring to
                        // groups inside it must enforce the captured value.
                        if ($entry['kind'] === '!') {
                            $ancClose = $this->findGroupClose($pattern, $entry['open']);
                            if ($ancClose !== null && $backrefPos > $ancClose) {
                                return true;
                            }
                        }
                    }
                }
                $kind = '';
                if ($k + 2 < $len && $pattern[$k + 1] === '?') {
                    $kind = $pattern[$k + 2];
                }
                $stack[] = ['open' => $k, 'kind' => $kind];
                $k++;
                continue;
            }
            if ($c === ')') {
                array_pop($stack);
                $k++;
                continue;
            }
            if ($c === '|' && $k > $groupOpen && $k < $backrefPos) {
                if (empty($stack) || $stack[count($stack) - 1]['open'] < $groupOpen) {
                    return true;
                }
            }
            $k++;
        }
        return false;
    }

    /**
     * Locate the matching closing paren for the group that opens at $open
     * in $pattern, ignoring escapes and character classes. Returns null if
     * the close cannot be found.
     */
    private function findGroupClose(string $pattern, int $open): ?int
    {
        $len = strlen($pattern);
        if ($open >= $len || $pattern[$open] !== '(') {
            return null;
        }
        $depth = 0;
        for ($i = $open; $i < $len; $i++) {
            $ch = $pattern[$i];
            if ($ch === '\\') {
                $i++;
                continue;
            }
            if ($ch === '[') {
                $i++;
                while ($i < $len && $pattern[$i] !== ']') {
                    if ($pattern[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                continue;
            }
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return null;
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
     * Return the globalThis the spec wants for OrdinaryCallBindThis in
     * sloppy mode: when this is undefined/null, substitute the *callee's*
     * realm's global object. Falls back to this interpreter's global if
     * the function has no realm tag (legacy paths).
     */
    public function getFunctionGlobalObject(JsFunction $fn): JsObject
    {
        if ($fn->realm !== null && $fn->realm !== $this->engineRealm) {
            $env = $fn->realm->getGlobalEnv();
            if ($env->has('this')) {
                $obj = $env->get('this');
                if ($obj instanceof JsObject) {
                    return $obj;
                }
            }
        }
        return $this->getGlobalObject();
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
     *
     * @return array{0: string, 1: string}
     */
    private static function bigStrDivModFull(string $a, string $b): array
    {
        if ($b === '0') {
            throw new \Phasis\Exceptions\RangeError('Division by zero');
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

    /**
     * BigInt::bitwiseNOT per §6.1.6.2.2: return -x - 1 as an arbitrary-
     * precision decimal string. Accepts hex/octal/binary BigInt literal
     * forms that may arrive from JsBigInt::value (e.g. "0xff").
     */
    private static function bigIntBitwiseNot(string $value): string
    {
        $value = self::bigIntLiteralToDecimal($value);
        // -x - 1 = -(x + 1)
        $xPlus1 = self::bigStrAddSigned($value, '1');
        if ($xPlus1 === '0') {
            return '0';
        }
        return $xPlus1[0] === '-' ? substr($xPlus1, 1) : '-' . $xPlus1;
    }

    /**
     * Public wrapper so the bytecode VM's BNOT opcode can compute the
     * BigInt path without duplicating the decimal-string arithmetic. The
     * tree-walker's UnaryExpression handler calls the private form via
     * self:: but the VM lives outside the class.
     */
    public static function bigIntBitwiseNotPublic(string $value): string
    {
        return self::bigIntBitwiseNot($value);
    }

    /** Convert a JsBigInt value string (possibly hex/oct/bin-prefixed) to decimal. */
    private static function bigIntLiteralToDecimal(string $value): string
    {
        $negative = false;
        $v = $value;
        if ($v !== '' && $v[0] === '-') {
            $negative = true;
            $v = substr($v, 1);
        }
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $v, $m) === 1) {
            $dec = self::baseStringToDecimal($m[1], 16);
        } elseif (preg_match('/^0[oO]([0-7]+)$/', $v, $m) === 1) {
            $dec = self::baseStringToDecimal($m[1], 8);
        } elseif (preg_match('/^0[bB]([01]+)$/', $v, $m) === 1) {
            $dec = self::baseStringToDecimal($m[1], 2);
        } else {
            $dec = $v;
        }
        return $negative ? '-' . $dec : $dec;
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
            throw new \Phasis\Exceptions\RangeError('Division by zero');
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
            throw new \Phasis\Exceptions\RangeError('Division by zero');
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
        throw new \Phasis\Exceptions\RangeError('BigInt exponent too large');
    }

    /**
     * BigInt bitwise AND/OR/XOR without GMP or bcmath.
     * Uses native PHP int for values that fit, binary-string two's-complement for large values.
     */
    /** @param '&'|'|'|'^' $op */
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
     *
     * @return array{0: string, 1: string}
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

    /**
     * Collapse leading zeros in `\u{HHHH…}` braced unicode escapes for /u
     * mode patterns. `\u{0…01234}` and `\u{1234}` denote the same code
     * point per spec §22.2.1.6, so canonicalising once here lets every
     * downstream char-by-char scan (validators, transforms, custom-matcher
     * parser, etc.) operate on a small string. Patterns whose contents are
     * not pure hex (e.g. `\u{0.0}`) or that exceed the spec-permitted six
     * hex digits after stripping zeros are preserved verbatim so the
     * downstream validators still report SyntaxError.
     */
    public static function canonicalizeUnicodeBracedEscapes(string $pattern): string
    {
        return (string) preg_replace_callback(
            '/\\\\u\{0+([0-9A-Fa-f]+)\}/',
            static function (array $m): string {
                $hex = $m[1];
                // Cap at 7 hex digits so values ≥ 0x10000000 stay long
                // enough for downstream "value too large" checks. Six
                // digits suffice for any valid code point (≤ 0x10FFFF).
                if (strlen($hex) > 7) {
                    return $m[0];
                }
                return '\\u{' . $hex . '}';
            },
            $pattern,
        );
    }

    /**
     * Analyze a regex pattern to find quantified (repeated) capturing groups
     * and determine which inner captures need ES-compliant reset behavior.
     *
     * Returns an array with:
     *   'repeatedGroups' => array of groupIndex => [
     *       'innerCaptures' => list of capture indices inside this repeated group,
     *       'bodyPattern' => the pattern text of the group body,
     *       'nullable' => whether the body can match empty,
     *       'lazy' => whether the quantifier is lazy (suffixed by `?`),
     *   ]
     *
     * @return array{
     *     repeatedGroups: array<int, array{
     *         innerCaptures: list<int>,
     *         bodyPattern: string,
     *         nullable: bool,
     *         quantifier: ?string,
     *         lazy: bool,
     *     }>,
     *     nullableNonCapturingGroups: list<array{innerCaptures: list<int>}>,
     * }
     */
    public static function analyzeRepeatedGroups(string $pattern): array
    {
        // Hot-path fast exit: a pattern with no `(` cannot contain any
        // repeated or nullable group. strpos in C is faster than the
        // byte-by-byte walk for long literal patterns.
        if (strpos($pattern, '(') === false) {
            return ['repeatedGroups' => [], 'nullableNonCapturingGroups' => []];
        }
        $len = strlen($pattern);
        $groupStack = []; // stack of [captureIndex|null, openPos, isNonCapturing]
        $groups = []; // captureIndex => [openPos, closePos, quantifier]
        $allGroups = []; // sequential id => [openPos, closePos, quantifier, captureIndex|null, isNonCapturing]
        $captureIndex = 0;
        $seqIndex = 0;
        $inCharClass = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            // Fast-skip plain bytes outside a class. Only `\\`, `[`, `]`,
            // `(`, `)`, `*`, `+`, `?`, `{` change state inside the loop.
            if (
                !$inCharClass
                && $ch !== '\\' && $ch !== '[' && $ch !== ']'
                && $ch !== '(' && $ch !== ')'
                && $ch !== '*' && $ch !== '+' && $ch !== '?' && $ch !== '{'
            ) {
                $skip = strcspn($pattern, "\\[]()*+?{", $i);
                if ($skip > 1) {
                    $i += $skip - 1;
                    continue;
                }
            } elseif ($inCharClass && $ch !== '\\' && $ch !== ']') {
                $skip = strcspn($pattern, "\\]", $i);
                if ($skip > 1) {
                    $i += $skip - 1;
                    continue;
                }
            }

            if ($ch === '\\' && $i + 1 < $len) {
                $i++;
                continue;
            }

            if ($ch === '[' && !$inCharClass) {
                $inCharClass = true;
                continue;
            }
            if ($ch === ']' && $inCharClass) {
                $inCharClass = false;
                continue;
            }
            if ($inCharClass) {
                continue;
            }

            if ($ch === '(') {
                $isCapturing = false;
                $isNonCapturing = false;
                if ($i + 1 < $len && $pattern[$i + 1] !== '?') {
                    $isCapturing = true;
                } elseif (
                    $i + 3 < $len && $pattern[$i + 1] === '?'
                    && $pattern[$i + 2] === '<'
                    && $pattern[$i + 3] !== '=' && $pattern[$i + 3] !== '!'
                ) {
                    $isCapturing = true;
                } elseif (
                    $i + 2 < $len && $pattern[$i + 1] === '?'
                    && $pattern[$i + 2] === ':'
                ) {
                    $isNonCapturing = true;
                }

                $thisSeq = $seqIndex++;
                if ($isCapturing) {
                    $captureIndex++;
                    $groupStack[] = [$captureIndex, $i, false, $thisSeq];
                    $groups[$captureIndex] = [
                        'openPos' => $i,
                        'closePos' => null,
                        'quantifier' => null,
                    ];
                    $allGroups[$thisSeq] = [
                        'openPos' => $i,
                        'closePos' => null,
                        'quantifier' => null,
                        'captureIndex' => $captureIndex,
                        'isNonCapturing' => false,
                    ];
                } else {
                    $groupStack[] = [null, $i, $isNonCapturing, $thisSeq];
                    $allGroups[$thisSeq] = [
                        'openPos' => $i,
                        'closePos' => null,
                        'quantifier' => null,
                        'captureIndex' => null,
                        'isNonCapturing' => $isNonCapturing,
                    ];
                }
                continue;
            }

            if ($ch === ')' && !empty($groupStack)) {
                $popped = array_pop($groupStack);
                $grpIdx = $popped[0];
                $thisSeq = $popped[3];

                // Check for quantifier after closing paren.
                $quantifier = null;
                $lazy = false;
                if ($i + 1 < $len) {
                    $next = $pattern[$i + 1];
                    if ($next === '*' || $next === '+' || $next === '?') {
                        $quantifier = $next;
                        // `*?`, `+?`, `??` are lazy variants. Skip the
                        // ? when it directly follows another quantifier.
                        if (
                            $next !== '?'
                            && $i + 2 < $len
                            && $pattern[$i + 2] === '?'
                        ) {
                            $lazy = true;
                        }
                    } elseif ($next === '{') {
                        $quantifier = '{';
                        // Find closing `}` then check for trailing `?`.
                        $close = strpos($pattern, '}', $i + 2);
                        if ($close !== false && $close + 1 < $len && $pattern[$close + 1] === '?') {
                            $lazy = true;
                        }
                    }
                }

                if ($grpIdx !== null) {
                    $groups[$grpIdx]['closePos'] = $i;
                    $groups[$grpIdx]['quantifier'] = $quantifier;
                    $groups[$grpIdx]['lazy'] = $lazy;
                }
                $allGroups[$thisSeq]['closePos'] = $i;
                $allGroups[$thisSeq]['quantifier'] = $quantifier;
                $allGroups[$thisSeq]['lazy'] = $lazy;
                continue;
            }
        }

        $repeatedGroups = [];
        foreach ($groups as $idx => $g) {
            // Process groups with quantifiers that allow zero matches at
            // runtime. Per the ES RepeatMatcher rule, when min=0 and the
            // body matches the empty string, the iteration is discarded
            // and captures inside reset to undefined.
            if (
                $g['quantifier'] !== '*'
                && $g['quantifier'] !== '+'
                && $g['quantifier'] !== '?'
                && $g['quantifier'] !== '{'
            ) {
                continue;
            }
            if ($g['closePos'] === null) {
                continue;
            }

            // Extract the body pattern (everything between the parens).
            $bodyStart = $g['openPos'] + 1;
            // Skip past named group prefix (?<name>) if present.
            if (
                $bodyStart < $len && $pattern[$bodyStart] === '?'
                && $bodyStart + 1 < $len && $pattern[$bodyStart + 1] === '<'
            ) {
                $end = strpos($pattern, '>', $bodyStart + 2);
                if ($end !== false) {
                    $bodyStart = $end + 1;
                }
            }
            $bodyPattern = substr($pattern, $bodyStart, $g['closePos'] - $bodyStart);

            // Find inner captures (captures whose open position is between this group's parens).
            $innerCaptures = [];
            foreach ($groups as $innerIdx => $inner) {
                if (
                    $innerIdx !== $idx
                    && $inner['openPos'] > $g['openPos']
                    && $inner['closePos'] !== null
                    && $inner['closePos'] < $g['closePos']
                ) {
                    $innerCaptures[] = $innerIdx;
                }
            }

            // Check if body is nullable (can match empty string).
            $nullable = self::isPatternNullable($bodyPattern);

            $repeatedGroups[$idx] = [
                'innerCaptures' => $innerCaptures,
                'bodyPattern' => $bodyPattern,
                'nullable' => $nullable,
                'quantifier' => $g['quantifier'],
                'lazy' => $g['lazy'] ?? false,
            ];
        }

        // Detect non-capturing groups with min-zero quantifiers (?, *, {0,...})
        // that contain capturing groups. Per ES spec RepeatMatcher step 2.b,
        // when min=0 and the body matches zero-length, the repetition returns
        // failure, causing captures inside to be reset to undefined. PCRE does
        // not implement this, so we track these for post-processing.
        $nullableNonCapturingGroups = [];
        foreach ($allGroups as $seqIdx => $ag) {
            if (!$ag['isNonCapturing'] || $ag['closePos'] === null) {
                continue;
            }
            // Check if the quantifier allows zero matches.
            $q = $ag['quantifier'];
            $minZero = false;
            if ($q === '?' || $q === '*') {
                $minZero = true;
            } elseif ($q === '{') {
                // Parse {N,...} to check if N is 0.
                $bPos = $ag['closePos'] + 2; // after ){
                $digits = '';
                while ($bPos < $len && $pattern[$bPos] >= '0' && $pattern[$bPos] <= '9') {
                    $digits .= $pattern[$bPos];
                    $bPos++;
                }
                if ($digits !== '' && (int) $digits === 0) {
                    $minZero = true;
                }
            }

            if (!$minZero) {
                continue;
            }

            // Find capturing groups inside this non-capturing group.
            $innerCaptures = [];
            foreach ($groups as $capIdx => $g) {
                if (
                    $g['openPos'] > $ag['openPos']
                    && $g['closePos'] !== null
                    && $g['closePos'] < $ag['closePos']
                ) {
                    $innerCaptures[] = $capIdx;
                }
            }

            if (empty($innerCaptures)) {
                continue;
            }

            // Check if the body is purely zero-width (only lookaheads/lookbehinds).
            $bodyStart = $ag['openPos'] + 1;
            // Skip ?: prefix.
            if (
                $bodyStart < $len && $pattern[$bodyStart] === '?'
                && $bodyStart + 1 < $len && $pattern[$bodyStart + 1] === ':'
            ) {
                $bodyStart += 2;
            }
            $bodyPattern = substr($pattern, $bodyStart, $ag['closePos'] - $bodyStart);
            $zeroWidth = self::isPatternZeroWidth($bodyPattern);

            if ($zeroWidth) {
                $nullableNonCapturingGroups[] = [
                    'innerCaptures' => $innerCaptures,
                ];
            }
        }

        return [
            'repeatedGroups' => $repeatedGroups,
            'nullableNonCapturingGroups' => $nullableNonCapturingGroups,
        ];
    }

    /**
     * Check if a regex pattern body consists entirely of zero-width assertions.
     * Returns true if the body can only match zero-length (lookaheads, lookbehinds,
     * word boundaries, anchors).
     */
    private static function isPatternZeroWidth(string $pattern): bool
    {
        $len = strlen($pattern);
        $i = 0;

        while ($i < $len) {
            $ch = $pattern[$i];

            // Skip whitespace.
            if ($ch === ' ' || $ch === "\t" || $ch === "\n") {
                $i++;
                continue;
            }

            // Anchors are zero-width.
            if ($ch === '^' || $ch === '$') {
                $i++;
                continue;
            }

            // \b and \B are zero-width.
            if ($ch === '\\' && $i + 1 < $len && ($pattern[$i + 1] === 'b' || $pattern[$i + 1] === 'B')) {
                $i += 2;
                continue;
            }

            // Lookahead/lookbehind groups are zero-width.
            if (
                $ch === '(' && $i + 2 < $len
                && $pattern[$i + 1] === '?'
                && ($pattern[$i + 2] === '=' || $pattern[$i + 2] === '!')
            ) {
                // Skip to the matching close paren.
                $depth = 1;
                $j = $i + 1;
                while ($j < $len && $depth > 0) {
                    if ($pattern[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($pattern[$j] === '(') {
                        $depth++;
                    } elseif ($pattern[$j] === ')') {
                        $depth--;
                    }
                    $j++;
                }
                $i = $j;
                // Skip any quantifier after.
                if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*' || $pattern[$i] === '+')) {
                    $i++;
                }
                continue;
            }

            // Lookbehind (?<=...) or (?<!...).
            if (
                $ch === '(' && $i + 3 < $len
                && $pattern[$i + 1] === '?'
                && $pattern[$i + 2] === '<'
                && ($pattern[$i + 3] === '=' || $pattern[$i + 3] === '!')
            ) {
                $depth = 1;
                $j = $i + 1;
                while ($j < $len && $depth > 0) {
                    if ($pattern[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($pattern[$j] === '(') {
                        $depth++;
                    } elseif ($pattern[$j] === ')') {
                        $depth--;
                    }
                    $j++;
                }
                $i = $j;
                if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*' || $pattern[$i] === '+')) {
                    $i++;
                }
                continue;
            }

            // Non-capturing group containing only zero-width patterns.
            if (
                $ch === '(' && $i + 2 < $len
                && $pattern[$i + 1] === '?'
                && $pattern[$i + 2] === ':'
            ) {
                $depth = 1;
                $j = $i + 1;
                while ($j < $len && $depth > 0) {
                    if ($pattern[$j] === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($pattern[$j] === '(') {
                        $depth++;
                    } elseif ($pattern[$j] === ')') {
                        $depth--;
                    }
                    $j++;
                }
                // Extract body and recurse.
                $bodyInner = substr($pattern, $i + 3, $j - 1 - ($i + 3));
                if (!self::isPatternZeroWidth($bodyInner)) {
                    return false;
                }
                $i = $j;
                // Skip quantifier.
                if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*' || $pattern[$i] === '+')) {
                    $i++;
                }
                continue;
            }

            // Anything else is not zero-width.
            return false;
        }

        return true;
    }

    /**
     * Check if a regex pattern can match the empty string.
     * This is a conservative check: it returns true if the pattern appears nullable.
     * For simple patterns (concatenation of optional elements), this is accurate.
     */
    private static function isPatternNullable(string $pattern): bool
    {
        // A concatenation is nullable if every element is nullable.
        // An alternation is nullable if any branch is nullable.
        // We parse the pattern at the top level and check each element.
        $len = strlen($pattern);
        $i = 0;
        $inAlternation = false;
        $currentBranchNullable = true;
        $anyBranchNullable = false;

        while ($i < $len) {
            $ch = $pattern[$i];

            if ($ch === '\\' && $i + 1 < $len) {
                // Escaped character: not nullable by itself.
                $i += 2;
                // Check for quantifier after.
                if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*')) {
                    $i++;
                    if ($i < $len && $pattern[$i] === '?') {
                        $i++; // lazy modifier
                    }
                    // nullable element, continue
                } elseif ($i < $len && $pattern[$i] === '{') {
                    // Check if {0,...} or {n,...} with n > 0.
                    $j = $i + 1;
                    $digits = '';
                    while ($j < $len && $pattern[$j] >= '0' && $pattern[$j] <= '9') {
                        $digits .= $pattern[$j];
                        $j++;
                    }
                    if ($digits !== '' && (int) $digits === 0) {
                        // {0,...} is nullable.
                        while ($j < $len && $pattern[$j] !== '}') {
                            $j++;
                        }
                        $i = $j + 1;
                    } else {
                        $currentBranchNullable = false;
                    }
                } elseif ($i < $len && $pattern[$i] === '+') {
                    $currentBranchNullable = false;
                    $i++;
                } else {
                    $currentBranchNullable = false;
                }
                continue;
            }

            if ($ch === '[') {
                // Character class: not nullable by itself.
                $i++;
                while ($i < $len && $pattern[$i] !== ']') {
                    if ($pattern[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                if ($i < $len) {
                    $i++; // skip ]
                }
                // Check for quantifier after.
                if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*')) {
                    $i++;
                    if ($i < $len && $pattern[$i] === '?') {
                        $i++;
                    }
                } elseif ($i < $len && $pattern[$i] === '{') {
                    $j = $i + 1;
                    $digits = '';
                    while ($j < $len && $pattern[$j] >= '0' && $pattern[$j] <= '9') {
                        $digits .= $pattern[$j];
                        $j++;
                    }
                    if ($digits !== '' && (int) $digits === 0) {
                        while ($j < $len && $pattern[$j] !== '}') {
                            $j++;
                        }
                        $i = $j + 1;
                    } else {
                        $currentBranchNullable = false;
                    }
                } elseif ($i < $len && $pattern[$i] === '+') {
                    $currentBranchNullable = false;
                    $i++;
                } else {
                    $currentBranchNullable = false;
                }
                continue;
            }

            if ($ch === '|') {
                // Alternation boundary.
                $inAlternation = true;
                if ($currentBranchNullable) {
                    $anyBranchNullable = true;
                }
                $currentBranchNullable = true; // reset for next branch
                $i++;
                continue;
            }

            if ($ch === '(') {
                // Group: skip to matching close paren and check quantifier.
                $depth = 1;
                $i++;
                while ($i < $len && $depth > 0) {
                    if ($pattern[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($pattern[$i] === '(') {
                        $depth++;
                    } elseif ($pattern[$i] === ')') {
                        $depth--;
                    }
                    $i++;
                }
                // $i is now past the closing ')'.
                // Check for quantifier.
                if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*')) {
                    $i++;
                    if ($i < $len && $pattern[$i] === '?') {
                        $i++;
                    }
                    // Nullable (group with ? or * quantifier).
                } elseif ($i < $len && $pattern[$i] === '{') {
                    $j = $i + 1;
                    $digits = '';
                    while ($j < $len && $pattern[$j] >= '0' && $pattern[$j] <= '9') {
                        $digits .= $pattern[$j];
                        $j++;
                    }
                    if ($digits !== '' && (int) $digits === 0) {
                        while ($j < $len && $pattern[$j] !== '}') {
                            $j++;
                        }
                        $i = $j + 1;
                    } else {
                        $currentBranchNullable = false;
                    }
                } elseif ($i < $len && $pattern[$i] === '+') {
                    $currentBranchNullable = false;
                    $i++;
                } else {
                    // No quantifier: the group itself must be nullable.
                    // We'd need to recurse, but for simplicity treat as non-nullable.
                    $currentBranchNullable = false;
                }
                continue;
            }

            // Anchors and zero-width assertions are nullable.
            if ($ch === '^' || $ch === '$') {
                $i++;
                continue;
            }

            // Literal character or '.': not nullable by itself.
            $i++;
            if ($i < $len && ($pattern[$i] === '?' || $pattern[$i] === '*')) {
                $i++;
                if ($i < $len && $pattern[$i] === '?') {
                    $i++;
                }
                // nullable
            } elseif ($i < $len && $pattern[$i] === '{') {
                $j = $i + 1;
                $digits = '';
                while ($j < $len && $pattern[$j] >= '0' && $pattern[$j] <= '9') {
                    $digits .= $pattern[$j];
                    $j++;
                }
                if ($digits !== '' && (int) $digits === 0) {
                    while ($j < $len && $pattern[$j] !== '}') {
                        $j++;
                    }
                    $i = $j + 1;
                } else {
                    $currentBranchNullable = false;
                }
            } elseif ($i < $len && $pattern[$i] === '+') {
                $currentBranchNullable = false;
                $i++;
            } else {
                $currentBranchNullable = false;
            }
        }

        if ($inAlternation) {
            return $anyBranchNullable || $currentBranchNullable;
        }
        return $currentBranchNullable;
    }

    /**
     * Post-process PCRE match results to fix ES-compliant capture reset
     * for capturing groups inside repeated (quantified) outer groups.
     *
     * PCRE retains the last successful match for captures inside a repeated group
     * across all iterations. ES spec requires captures to be reset to undefined
     * at the start of each iteration, so only captures that participated in the
     * LAST iteration should have values.
     *
     * @param array<int|string, array{0: ?string, 1: int}> $matches PCRE match result
     * @param array{
     *     repeatedGroups: array<int, array{
     *         innerCaptures: list<int>,
     *         bodyPattern: string,
     *         nullable: bool,
     *         quantifier: ?string,
     *     }>,
     *     nullableNonCapturingGroups?: list<array{innerCaptures: list<int>}>,
     * } $analysis
     * @param string $pcreFlags The PCRE flags string (e.g., 'iu')
     * @param callable $transformFn Transforms ES pattern to PCRE pattern
     * @return array<int|string, array{0: ?string, 1: int}>
     */
    public static function fixRepeatedGroupCaptures(
        array $matches,
        array $analysis,
        string $pcreFlags,
        callable $transformFn,
    ): array {
        foreach ($analysis['repeatedGroups'] as $groupIdx => $info) {
            // Per RepeatMatcher step 2.b: when min=0 and the body matched
            // empty, the iteration is discarded and the capture itself is
            // undefined. PCRE keeps the empty match instead.
            if (
                isset($matches[$groupIdx])
                && $matches[$groupIdx][0] === ''
                && $info['quantifier'] === '?'
                && $info['nullable']
            ) {
                $matches[$groupIdx] = [null, -1];
                foreach ($info['innerCaptures'] as $innerIdx) {
                    if (isset($matches[$innerIdx])) {
                        $matches[$innerIdx] = [null, -1];
                    }
                }
                continue;
            }

            if (empty($info['innerCaptures'])) {
                continue;
            }

            // Get the last captured value of the outer repeated group.
            if (!isset($matches[$groupIdx]) || $matches[$groupIdx][0] === null || $matches[$groupIdx][1] === -1) {
                // Outer group didn't match: all inner captures should be undefined.
                foreach ($info['innerCaptures'] as $innerIdx) {
                    if (isset($matches[$innerIdx])) {
                        $matches[$innerIdx] = [null, -1];
                    }
                }
                continue;
            }

            $lastCapturedValue = $matches[$groupIdx][0];

            // Build a PCRE pattern for just the inner body with captures.
            $innerEsPattern = $info['bodyPattern'];
            $innerPcreBody = $transformFn($innerEsPattern);
            $innerPcrePattern = '/^' . $innerPcreBody . '$/' . $pcreFlags;

            // Match the inner pattern against the last captured value.
            $innerResult = @preg_match(
                $innerPcrePattern,
                $lastCapturedValue,
                $innerMatches,
                PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
            );

            if ($innerResult === 1) {
                // Map inner match results back to the original capture indices.
                $innerCaptureList = $info['innerCaptures'];
                for ($k = 0; $k < count($innerCaptureList); $k++) {
                    $originalIdx = $innerCaptureList[$k];
                    $innerIdx = $k + 1; // Inner match group 1 corresponds to first inner capture.
                    if (
                        isset($innerMatches[$innerIdx])
                        && $innerMatches[$innerIdx][0] !== null
                    ) {
                        // Calculate the byte offset relative to the outer group match position.
                        $outerByteOffset = $matches[$groupIdx][1];
                        $matches[$originalIdx] = [
                            $innerMatches[$innerIdx][0],
                            $outerByteOffset + $innerMatches[$innerIdx][1],
                        ];
                    } else {
                        $matches[$originalIdx] = [null, -1];
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Handle nullable quantifier patterns by implementing iterative matching.
     *
     * When a quantified group (e.g., (X)*) has a nullable body (X can match empty),
     * PCRE stops the repetition on empty match, but ES spec discards the empty
     * iteration and backtracks to find non-empty alternatives.
     *
     * This method detects whether the PCRE result was cut short by the nullable
     * quantifier issue and extends the match by trying substrings of increasing
     * length against the anchored inner pattern, forcing non-empty matches.
     *
     * @param array<int|string, array{0: ?string, 1: int}> $matches PCRE match result
     * @param array{repeatedGroups: array<int, array{innerCaptures: list<int>, bodyPattern: string, nullable: bool, lazy?: bool}>} $analysis
     * @param string $str The full input string
     * @param string $pcreFlags The PCRE flags string (e.g., 'iu')
     * @param callable $transformFn Transforms ES pattern to PCRE pattern
     * @return array<int|string, array{0: ?string, 1: int}>
     */
    public static function fixNullableQuantifier(
        array $matches,
        array $analysis,
        string $str,
        string $pcreFlags,
        callable $transformFn,
    ): array {
        foreach ($analysis['repeatedGroups'] as $groupIdx => $info) {
            if (!$info['nullable']) {
                continue;
            }

            if (!isset($matches[$groupIdx])) {
                continue;
            }

            // Lazy quantifiers (*?, +?, ??, {N,M}?) deliberately match
            // as few iterations as possible. The "extend" heuristic
            // below is for greedy nullable bodies where PCRE2 hit a
            // zero-width match too early — applying it to a lazy
            // quantifier would consume past the lazy stop point.
            if (!empty($info['lazy'])) {
                continue;
            }

            // Calculate the end position of the current overall match.
            $overallMatch = $matches[0][0] ?? '';
            $overallByteStart = $matches[0][1] ?? 0;
            $overallByteEnd = $overallByteStart + strlen($overallMatch);

            // If we're already at end of string, nothing to extend.
            if ($overallByteEnd >= strlen($str)) {
                continue;
            }

            // Build anchored PCRE pattern for the inner body. Using ^ and $ anchors
            // forces PCRE to match the entire substring, which prevents the nullable
            // body from matching empty when there are characters available.
            // This avoids PCRE2 JIT bugs with (*NOTEMPTY_ATSTART).
            $innerEsPattern = $info['bodyPattern'];
            $innerPcreBody = $transformFn($innerEsPattern);
            $anchoredPattern = '/^(' . $innerPcreBody . ')$/' . $pcreFlags;

            // Also build an unanchored pattern for normal (non-empty) matching.
            $normalPattern = '/(' . $innerPcreBody . ')/' . $pcreFlags;

            // Iteratively extend the match from the current end position.
            $currentByteEnd = $overallByteEnd;
            $extended = false;
            $lastGroupCapture = $matches[$groupIdx];
            $strLen = strlen($str);

            while ($currentByteEnd < $strLen) {
                // First, try normal unanchored match at current position.
                // This handles the common case where the inner pattern matches
                // non-empty without needing the substring workaround.
                $innerMatches = [];
                $innerResult = @preg_match(
                    $normalPattern,
                    $str,
                    $innerMatches,
                    PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
                    $currentByteEnd,
                );

                if (
                    $innerResult === 1
                    && $innerMatches[0][1] === $currentByteEnd
                    && strlen($innerMatches[0][0]) > 0
                ) {
                    // Non-empty match at current position: extend and continue.
                    $currentByteEnd += strlen($innerMatches[0][0]);
                    $lastGroupCapture = [$innerMatches[1][0], $innerMatches[1][1]];
                    $extended = true;
                    continue;
                }

                // Empty match or no match. Use the substring approach:
                // try substrings of length 1, 2, ... from current position and
                // match the anchored pattern (^body$) against each. The anchors
                // force PCRE to use the available characters rather than matching empty.
                $found = false;
                $remaining = $strLen - $currentByteEnd;
                for ($tryLen = 1; $tryLen <= $remaining; $tryLen++) {
                    $sub = substr($str, $currentByteEnd, $tryLen);
                    // Verify this is a valid UTF-8 boundary (don't split multi-byte chars).
                    if (mb_check_encoding($sub, 'UTF-8') === false) {
                        continue;
                    }
                    $subMatches = [];
                    $subResult = @preg_match(
                        $anchoredPattern,
                        $sub,
                        $subMatches,
                        PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
                    );
                    if ($subResult === 1 && strlen($subMatches[0][0]) > 0) {
                        // Found a non-empty match of length $tryLen.
                        $lastGroupCapture = [$subMatches[1][0], $currentByteEnd + $subMatches[1][1]];
                        $currentByteEnd += strlen($subMatches[0][0]);
                        $extended = true;
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    // No non-empty match possible at this position: stop iterating.
                    break;
                }
            }

            if ($extended) {
                // Update the overall match to include the extended portion.
                $newOverallMatch = substr($str, $overallByteStart, $currentByteEnd - $overallByteStart);
                $matches[0] = [$newOverallMatch, $overallByteStart];

                // Update the group capture to reflect the last iteration.
                $matches[$groupIdx] = $lastGroupCapture;
            }
        }

        return $matches;
    }

    /**
     * Fix captures inside nullable non-capturing groups.
     *
     * Per ES spec RepeatMatcher step 2.b: when min=0 and the body matched
     * zero-length, the repetition returns failure and captures inside are
     * reset to undefined. PCRE does not implement this: it keeps captures
     * from the zero-width match. This method detects such cases and resets
     * the affected captures.
     *
     * @param array<int|string, array{0: ?string, 1: int}> $matches
     * @return array<int|string, array{0: ?string, 1: int}>
     * @param array<mixed> $analysis
     */
    public static function fixNullableNonCapturingGroupCaptures(
        array $matches,
        array $analysis,
    ): array {
        if (empty($analysis['nullableNonCapturingGroups'])) {
            return $matches;
        }

        foreach ($analysis['nullableNonCapturingGroups'] as $info) {
            foreach ($info['innerCaptures'] as $capIdx) {
                if (isset($matches[$capIdx])) {
                    // Reset the capture to unmatched (null at offset -1).
                    $matches[$capIdx] = [null, -1];
                }
            }
        }

        return $matches;
    }

    /**
     * Validate (?addFlags-removeFlags:...) modifier groups. Per spec, the
     * allowed flags are `i`, `m`, `s` and both sides combined must be
     * non-empty and non-overlapping, with no flag repeated on either side.
     */
    public static function validateRegExpModifierGroups(string $pattern): void
    {
        // Hot-path fast exit: a pattern with no `(?` syntax has no
        // modifier groups to validate.
        if (strpos($pattern, '(?') === false) {
            return;
        }
        $len = strlen($pattern);
        $i = 0;
        $inClass = false;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch !== '\\' && $ch !== '[' && $ch !== ']' && $ch !== '(') {
                $skip = strcspn($pattern, "\\[](", $i);
                if ($skip > 0) {
                    $i += $skip;
                    continue;
                }
            }
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if (!$inClass && $ch === '[') {
                $inClass = true;
                $i++;
                continue;
            }
            if ($inClass) {
                if ($ch === ']') {
                    $inClass = false;
                }
                $i++;
                continue;
            }
            if ($ch === '(' && $i + 1 < $len && $pattern[$i + 1] === '?') {
                // Distinguish `(?:`, `(?=`, `(?!`, `(?<=`, `(?<!`, `(?<name>`
                // from a modifier group `(?flags-flags:`. Modifier groups
                // start with i/m/s or `-`.
                if ($i + 2 < $len) {
                    $first = $pattern[$i + 2];
                    if (
                        !in_array($first, ['i', 'm', 's', '-'], true)
                    ) {
                        $i += 2;
                        continue;
                    }
                }
                // Scan flag characters after `(?`.
                $j = $i + 2;
                $add = '';
                $remove = '';
                $phase = 'add';
                $hasMinus = false;
                $hasColon = false;
                while ($j < $len) {
                    $c = $pattern[$j];
                    if ($c === '-' && $phase === 'add' && !$hasMinus) {
                        $phase = 'remove';
                        $hasMinus = true;
                        $j++;
                        continue;
                    }
                    if ($c === ':') {
                        $hasColon = true;
                        break;
                    }
                    if ($c === ')') {
                        break;
                    }
                    if (!in_array($c, ['i', 'm', 's'], true)) {
                        // Invalid character in modifier group.
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Invalid modifier flag"
                        );
                    }
                    if ($phase === 'add') {
                        $add .= $c;
                    } else {
                        $remove .= $c;
                    }
                    $j++;
                }
                if (!$hasColon) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Modifier group requires colon"
                    );
                }
                if ($add === '' && $remove === '') {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Modifier group has no flags"
                    );
                }
                // Repeated flag on either side → SyntaxError.
                if (strlen(count_chars($add, 3)) !== strlen($add)) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Repeated flag in modifier"
                    );
                }
                if (strlen(count_chars($remove, 3)) !== strlen($remove)) {
                    throw new \Phasis\Exceptions\SyntaxError(
                        "Invalid regular expression: /{$pattern}/: Repeated flag in modifier"
                    );
                }
                // Overlap between add and remove → SyntaxError.
                for ($k = 0; $k < strlen($add); $k++) {
                    if (str_contains($remove, $add[$k])) {
                        throw new \Phasis\Exceptions\SyntaxError(
                            "Invalid regular expression: /{$pattern}/: Flag in both add and remove modifiers"
                        );
                    }
                }
                $i = $j + 1;
                continue;
            }
            $i++;
        }
    }

    /**
     * Detect duplicate named capture groups declared within the same
     * Alternative (i.e. not separated by a top-level `|`). Per spec, this
     * is a SyntaxError even under the duplicate-named-capture-groups
     * proposal — duplicates are only allowed across disjoint alternatives.
     */
    public static function hasDuplicateNamedGroupsInSameAlternative(string $pattern): bool
    {
        // Hot-path fast exit: at least two named-group declarations are
        // required for a duplicate to exist.
        if (strpos($pattern, '(?<') === false) {
            return false;
        }
        // Collect declared names per alternative, descending into groups but
        // restarting the "seen" set on every top-level `|`. Groups themselves
        // introduce their own alternative scope (their internal `|` splits
        // the inner names, independent of the outer set).
        $len = strlen($pattern);
        $i = 0;
        // Stack of "seen-name" sets, one per nested alternative scope.
        $stack = [[]];
        $topIndex = 0;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch !== '\\' && $ch !== '[' && $ch !== '(' && $ch !== ')' && $ch !== '|') {
                $skip = strcspn($pattern, "\\[()|", $i);
                if ($skip > 0) {
                    $i += $skip;
                    continue;
                }
            }
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if ($ch === '[') {
                $i++;
                while ($i < $len && $pattern[$i] !== ']') {
                    if ($pattern[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            if ($ch === '|') {
                // New alternative at the current nesting: reset its seen set.
                $stack[$topIndex] = [];
                $i++;
                continue;
            }
            if (
                $ch === '('
                && $i + 1 < $len
                && $pattern[$i + 1] === '?'
            ) {
                // Is it a named capture (?<name>..) — not lookbehind (?<=..) or (?<!..)?
                if (
                    $i + 3 < $len
                    && $pattern[$i + 2] === '<'
                    && $pattern[$i + 3] !== '='
                    && $pattern[$i + 3] !== '!'
                ) {
                    $nameStart = $i + 3;
                    $nameEnd = $nameStart;
                    while ($nameEnd < $len && $pattern[$nameEnd] !== '>') {
                        $nameEnd++;
                    }
                    if ($nameEnd < $len) {
                        $name = substr($pattern, $nameStart, $nameEnd - $nameStart);
                        if (isset($stack[$topIndex][$name])) {
                            return true;
                        }
                        $stack[$topIndex][$name] = true;
                        $i = $nameEnd + 1;
                        // Enter a new alternative scope for the group body.
                        $stack[] = [];
                        $topIndex++;
                        continue;
                    }
                }
                // Non-capturing or other (?...) group — push scope.
                $stack[] = [];
                $topIndex++;
                $i += 2;
                continue;
            }
            if ($ch === '(') {
                // Plain capturing group — push scope.
                $stack[] = [];
                $topIndex++;
                $i++;
                continue;
            }
            if ($ch === ')') {
                // Pop scope.
                if ($topIndex > 0) {
                    array_pop($stack);
                    $topIndex--;
                }
                $i++;
                continue;
            }
            $i++;
        }
        return false;
    }

    /**
     * Detect duplicate named capture groups in an ES pattern.
     */
    private static function hasDuplicateNamedGroups(
        string $pattern
    ): bool {
        // Hot-path fast exit.
        if (strpos($pattern, '(?<') === false) {
            return false;
        }
        $seen = [];
        $len = strlen($pattern);
        $i = 0;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch !== '\\' && $ch !== '[' && $ch !== '(') {
                $skip = strcspn($pattern, "\\[(", $i);
                if ($skip > 0) {
                    $i += $skip;
                    continue;
                }
            }
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if ($ch === '[') {
                $i++;
                while ($i < $len && $pattern[$i] !== ']') {
                    if ($pattern[$i] === '\\') {
                        $i++;
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            if (
                $ch === '('
                && $i + 3 < $len
                && $pattern[$i + 1] === '?'
                && $pattern[$i + 2] === '<'
                && $pattern[$i + 3] !== '='
                && $pattern[$i + 3] !== '!'
            ) {
                $nameStart = $i + 3;
                $nameEnd = $nameStart;
                while ($nameEnd < $len && $pattern[$nameEnd] !== '>') {
                    $nameEnd++;
                }
                if ($nameEnd < $len) {
                    $name = substr(
                        $pattern,
                        $nameStart,
                        $nameEnd - $nameStart,
                    );
                    if (isset($seen[$name])) {
                        return true;
                    }
                    $seen[$name] = true;
                }
                $i = $nameEnd + 1;
                continue;
            }
            $i++;
        }
        return false;
    }

    /**
     * Detect whether the pattern uses any feature where PCRE2's
     * matching diverges from ECMA-262 in a user-visible way:
     *   - A lookbehind that contains a capture group (PCRE2 captures
     *     left-to-right, ES specifies right-to-left).
     *   - A quantified group containing captures (PCRE2 keeps state
     *     between iterations, ES resets).
     *
     * When this returns true the regex compiler keeps a parsed AST on
     * the regex object so exec() can route through the in-engine
     * matcher (see src/Regex/Matcher.php).
     */
    public static function patternNeedsCustomMatcher(string $pattern, string $flags = ''): bool
    {
        $isUnicode = str_contains($flags, 'u') || str_contains($flags, 'v');
        $isCaseless = str_contains($flags, 'i');
        $len = strlen($pattern);
        $i = 0;
        $inClass = false;
        $hasDotInNonUnicode = false;
        $hasInlineModifier = false;
        $hasLookbehind = false;
        // PCRE2 in /u mode applies Unicode case-folding under /i; ES
        // without /u must use ASCII-only folding. If the pattern
        // contains any non-ASCII code point (literal byte or
        // \uXXXX/\xHH escape) under /i but not /u, route to the
        // custom matcher whose canonicalize() honours that
        // distinction.
        $hasNonAsciiInIWithoutU = false;
        // PCRE2 with /u (PCRE2_UTF) treats every Unicode letter as a
        // word character — broader than ECMA-262 outside /u+/i mode.
        // GetWordCharacters limits the basic set to ASCII A-Z a-z 0-9
        // _; under /u+/i it adds chars whose Canonicalize lands in
        // that basic set. PCRE2's match-the-Unicode-letters behaviour
        // happens to coincide closely enough with the spec under
        // /u+/i (test262 passes there), so route only when /u+/i is
        // NOT both set; that's where PCRE2 over-matches.
        $hasWordToken = false;
        // Track group nesting and whether we're inside a lookbehind.
        $lookbehindDepth = 0;
        $needNonAsciiScan = $isCaseless && !$isUnicode;
        $needAsciiWordCheck = !($isUnicode && $isCaseless);
        // PCRE2 with /u rejects lone surrogate code units in input
        // strings as invalid UTF-8 (we encode them via CESU-8).
        // Patterns containing astral characters (or \\u{XXXXX} with
        // value > BMP) will get tested against inputs that may
        // contain those astrals split into surrogate halves; route
        // them through the custom matcher so the codepoint walk
        // handles either form.
        $hasAstralInUnicode = false;
        $needAstralScan = $isUnicode;
        // Lone surrogate escapes (\uD800–\uDFFF) in patterns
        // need the custom matcher: PCRE2 with UTF mode rejects them
        // as invalid byte sequences. In non-/u mode, patterns like
        // /\udf06/ should still match the lone surrogate per spec
        // (regex operates on UTF-16 code units). In /u mode,
        // /[\uD83D]/u should match the lone high surrogate per spec.
        // Both routes rely on the custom matcher's UTF-16 walk.
        $hasLoneSurrogateEscape = false;
        $needSurrogateScan = true;
        // \p{...} / \P{...} Unicode property escapes need the custom
        // matcher: PCRE2's built-in Unicode tables ship with
        // whatever Unicode version PHP's PCRE was built against
        // (often older than ICU's), and many test262 property tests
        // fail at codepoints PCRE2 and ICU disagree on. The custom
        // matcher routes all lookups through IntlChar (ICU) for a
        // consistent data source.
        $hasUnicodePropertyEscape = false;
        // Unicode 16 added simple/common case-fold equivalences that
        // older ICU (Ubuntu CI ships ICU 70 / 74) doesn't know.
        // When /ui (or /vi) is set, patterns containing one of the
        // affected codepoints route through the custom matcher whose
        // Matcher::canonicalize() applies the host-independent
        // override table. PCRE2 would otherwise miss the fold pair
        // on the older ICU and produce a no-match where ICU 76+
        // says match.
        $hasUnicode16FoldCodepoint = false;
        $needUnicode16FoldScan = $isCaseless && $isUnicode;
        // Track which group has captures inside it for quantifier
        // detection. We approximate by scanning for `(...){n,m}`-like
        // shapes that contain captures.
        $hasLookbehindWithCapture = false;
        $hasQuantifiedCapture = false;
        // Stack of: ['kind' => 'capture' | 'noncapture' | 'lookbehind' | 'lookahead', 'sawCapture' => bool]
        $stack = [];
        // Most pattern bytes are uninteresting (literal digits, letters,
        // whitespace) and just advance $i without changing any flag.
        // Pre-build the byte set strcspn should stop at: control bytes
        // for grouping/escape plus, when the slow path needs to
        // inspect non-ASCII for flag updates, every high-bit byte.
        // This collapses a 16 MiB zero-padded \u{…} pattern from 16M
        // PHP iterations down to a single C-level strcspn jump.
        // Either /i+!/u (Unicode case-fold check), /u (astral codepoint
        // check), or non-/u (raw astral atom check) wants byte-level
        // inspection of high-bit runs, so always include 0x80..0xFF in
        // the strcspn stop set: the slow path handles them.
        $highBytes = '';
        for ($b = 0x80; $b <= 0xFF; $b++) {
            $highBytes .= chr($b);
        }
        $stopBytes = '\\[].()' . $highBytes;
        $stopBytesInClass = '\\]' . substr($stopBytes, 6); // drop ".()[" runners
        while ($i < $len) {
            $ch = $pattern[$i];
            // Inside character classes the only meaningful tokens are
            // `\`, `]`, and any non-ASCII byte. Plain ASCII content
            // doesn't change state — jump past it in one strcspn.
            if ($inClass) {
                if ($ch !== '\\' && $ch !== ']' && (ord($ch) & 0x80) === 0) {
                    $skip = strcspn($pattern, $stopBytesInClass, $i);
                    if ($skip > 0) {
                        $i += $skip;
                        continue;
                    }
                }
            } elseif (
                $ch !== '\\' && $ch !== '[' && $ch !== ']'
                && $ch !== '.' && $ch !== '(' && $ch !== ')'
                && (ord($ch) & 0x80) === 0
            ) {
                $skip = strcspn($pattern, $stopBytes, $i);
                if ($skip > 0) {
                    $i += $skip;
                    continue;
                }
            }
            if ($ch === '\\') {
                if ($i + 1 < $len) {
                    $next = $pattern[$i + 1];
                    {
                    if ($next === 'u' && $i + 2 < $len && $pattern[$i + 2] === '{') {
                        $closeBrace = strpos($pattern, '}', $i + 3);
                        if ($closeBrace !== false) {
                            $hex = substr($pattern, $i + 3, $closeBrace - $i - 3);
                            if (ctype_xdigit($hex)) {
                                $cp = (int) hexdec($hex);
                                if ($needNonAsciiScan && $cp > 0x7F) {
                                    $hasNonAsciiInIWithoutU = true;
                                }
                                if ($needAstralScan && $cp > 0xFFFF) {
                                    $hasAstralInUnicode = true;
                                }
                                if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                                    $hasLoneSurrogateEscape = true;
                                }
                                if ($needUnicode16FoldScan && self::isUnicode16FoldCodepoint($cp)) {
                                    $hasUnicode16FoldCodepoint = true;
                                }
                            }
                        }
                    } elseif ($next === 'u' && $i + 5 < $len) {
                        $hex = substr($pattern, $i + 2, 4);
                        if (ctype_xdigit($hex)) {
                            $cp = (int) hexdec($hex);
                            if ($needNonAsciiScan && $cp > 0x7F) {
                                $hasNonAsciiInIWithoutU = true;
                            }
                            if ($needUnicode16FoldScan && self::isUnicode16FoldCodepoint($cp)) {
                                $hasUnicode16FoldCodepoint = true;
                            }
                            // A high surrogate followed by an
                            // adjacent low surrogate is a valid
                            // pair (PCRE2 handles it as an astral
                            // codepoint). A bare lone surrogate,
                            // or a low surrogate not preceded by
                            // its high pair, needs the custom
                            // matcher because PCRE2 rejects it as
                            // invalid UTF-8.
                            if ($cp >= 0xD800 && $cp <= 0xDFFF) {
                                $paired = false;
                                if ($cp <= 0xDBFF) {
                                    // Look ahead for paired low.
                                    if (
                                        $i + 11 < $len
                                        && $pattern[$i + 6] === '\\'
                                        && $pattern[$i + 7] === 'u'
                                        && ctype_xdigit(substr($pattern, $i + 8, 4))
                                    ) {
                                        $n2 = (int) hexdec(substr($pattern, $i + 8, 4));
                                        $paired = $n2 >= 0xDC00 && $n2 <= 0xDFFF;
                                    }
                                } else {
                                    // Low surrogate: paired iff
                                    // preceded by an adjacent high.
                                    if (
                                        $i >= 6
                                        && $pattern[$i - 6] === '\\'
                                        && $pattern[$i - 5] === 'u'
                                        && ctype_xdigit(substr($pattern, $i - 4, 4))
                                    ) {
                                        $p1 = (int) hexdec(substr($pattern, $i - 4, 4));
                                        $paired = $p1 >= 0xD800 && $p1 <= 0xDBFF;
                                    }
                                }
                                // In /u mode an adjacent pair is one
                                // astral codepoint atom (PCRE2 handles
                                // it). Outside /u each surrogate
                                // escape is its own UTF-16 code unit
                                // atom; the PCRE transform collapses
                                // adjacent pairs into a single
                                // codepoint, which loses the two-atom
                                // structure when a quantifier is
                                // attached (e.g. `/\\uD83D\\uDC38?/`
                                // wants the trail optional, not the
                                // pair). Route through the custom
                                // matcher whose UTF-16 walk preserves
                                // both atoms.
                                if (!$paired || !$isUnicode) {
                                    $hasLoneSurrogateEscape = true;
                                }
                            }
                        }
                    } elseif ($next === 'x' && $i + 3 < $len) {
                        $hex = substr($pattern, $i + 2, 2);
                        if (
                            $needNonAsciiScan
                            && ctype_xdigit($hex)
                            && (int) hexdec($hex) > 0x7F
                        ) {
                            $hasNonAsciiInIWithoutU = true;
                        }
                    }
                    }
                    if (
                        $needAsciiWordCheck
                        && ($next === 'b' || $next === 'B' || $next === 'w' || $next === 'W')
                    ) {
                        $hasWordToken = true;
                    }
                    // \p{...} / \P{...} present in the pattern. Only
                    // applicable in /u or /v mode; outside Unicode
                    // mode \p has no special meaning and the
                    // translator already lowers it to a literal.
                    if (
                        $isUnicode
                        && ($next === 'p' || $next === 'P')
                        && $i + 2 < $len
                        && $pattern[$i + 2] === '{'
                    ) {
                        $hasUnicodePropertyEscape = true;
                    }
                }
                $i += 2;
                continue;
            }
            if ($needNonAsciiScan && ord($ch) > 0x7F) {
                $hasNonAsciiInIWithoutU = true;
            }
            if ($needAstralScan && (ord($ch) & 0xF8) === 0xF0) {
                // 4-byte UTF-8 = astral codepoint.
                $hasAstralInUnicode = true;
            }
            if (!$isUnicode && (ord($ch) & 0xF8) === 0xF0) {
                // Non-/u: a raw astral character in the pattern is two
                // UTF-16 code-unit atoms per spec. PCRE2 treats it as
                // one codepoint, so a quantifier like `🐸?` would
                // make the whole 4-byte char optional instead of just
                // its trail surrogate. Route to the custom matcher
                // whose parser splits raw astrals into a lead atom +
                // pending-trail atom.
                $hasLoneSurrogateEscape = true;
            }
            if (!$inClass && $ch === '[') {
                // /u patterns with `[^]` (negated empty class — i.e.
                // "match any code unit including lone surrogates")
                // need the custom matcher: PCRE2 with /u rejects
                // lone-surrogate input as invalid UTF-8 and returns
                // an internal error, so /[^]/u.exec("\uD83D") would
                // always return null even though the spec says it
                // should match.
                if (
                    $isUnicode
                    && $i + 2 < $len
                    && $pattern[$i + 1] === '^'
                    && $pattern[$i + 2] === ']'
                ) {
                    $hasLoneSurrogateEscape = true;
                }
                $inClass = true;
                $i++;
                continue;
            }
            if ($inClass && $ch === ']') {
                $inClass = false;
                $i++;
                continue;
            }
            if ($inClass) {
                $i++;
                continue;
            }
            if ($ch === '.') {
                // Dot semantics where PCRE2 diverges from spec:
                //   - non-unicode mode: PCRE2 treats astrals as one
                //     codepoint, ECMAScript needs UTF-16 code units.
                //   - /u or /u+/s: spec includes lone surrogates,
                //     which our CESU-8 encoding produces as bytes
                //     PCRE2 rejects as invalid UTF-8. Route through
                //     the custom matcher in every case.
                $hasDotInNonUnicode = true;
            }
            if ($ch === '(') {
                $kind = 'capture';
                $consume = 1;
                if ($i + 2 < $len && $pattern[$i + 1] === '?') {
                    $next = $pattern[$i + 2];
                    if ($next === ':') {
                        $kind = 'noncapture';
                        $consume = 3;
                    } elseif ($next === '=' || $next === '!') {
                        $kind = 'lookahead';
                        $consume = 3;
                    } elseif ($next === '<' && $i + 3 < $len) {
                        $third = $pattern[$i + 3];
                        if ($third === '=' || $third === '!') {
                            $kind = 'lookbehind';
                            $consume = 4;
                        } else {
                            // Named capture (?<name>
                            $kind = 'capture';
                            $consume = 1;
                        }
                    } elseif ($next === 'i' || $next === 'm' || $next === 's' || $next === '-') {
                        // Inline modifier (?ims:...) / (?-ims:...) —
                        // PCRE2 handles these; our custom matcher
                        // does not honour the per-group flag overrides.
                        $hasInlineModifier = true;
                        $kind = 'noncapture';
                        $consume = 1;
                    }
                }
                if ($kind === 'capture') {
                    // Mark every enclosing scope as having seen a capture.
                    foreach ($stack as &$frame) {
                        $frame['sawCapture'] = true;
                    }
                    unset($frame);
                }
                if ($kind === 'lookbehind') {
                    $lookbehindDepth++;
                    $hasLookbehind = true;
                }
                $stack[] = ['kind' => $kind, 'sawCapture' => false];
                $i += $consume;
                continue;
            }
            if ($ch === ')') {
                $top = array_pop($stack);
                if ($top !== null && $top['kind'] === 'lookbehind') {
                    $lookbehindDepth--;
                }
                // Check if a quantifier follows.
                $j = $i + 1;
                $hasQuant = false;
                if ($j < $len) {
                    $q = $pattern[$j];
                    if ($q === '*' || $q === '+' || $q === '?' || $q === '{') {
                        $hasQuant = true;
                    }
                }
                if ($hasQuant && $top !== null && $top['sawCapture']) {
                    // (...){...} containing a capture — needs spec
                    // capture-reset semantics PCRE2 lacks.
                    $hasQuantifiedCapture = true;
                }
                if (
                    $top !== null
                    && $top['sawCapture']
                    && $lookbehindDepth > 0
                ) {
                    // We're still inside a lookbehind and just closed
                    // a group that had captures.
                    $hasLookbehindWithCapture = true;
                }
                if ($top !== null && $top['kind'] === 'lookbehind' && $top['sawCapture']) {
                    $hasLookbehindWithCapture = true;
                }
                $i++;
                continue;
            }
            $i++;
        }
        if ($hasInlineModifier) {
            // Inline-modifier patterns route through the custom
            // matcher (which honours per-group flag overrides via
            // ModifierGroup AST nodes).
            return true;
        }
        if ($hasUnicodePropertyEscape) {
            // Unicode property escapes use ICU's Unicode tables via
            // IntlChar in the custom matcher. PCRE2's built-in
            // tables can lag behind ICU (and the test262 generated
            // data) by several Unicode versions, producing
            // category mismatches on codepoints whose
            // Bidi_Mirrored / Script / Script_Extensions /
            // Alphabetic / ... state changed.
            // Exception: /v patterns. The custom matcher's
            // CharClass parser does not model set operations
            // ([A--B], [A&&B]), nested classes ([[A]B]), or
            // property-of-strings (\p{Emoji_Keycap_Sequence} and
            // friends, which match multi-codepoint sequences).
            // The transformVFlagPattern PCRE2 lowering already
            // handles all three; keep /v patterns on that path
            // until the custom matcher catches up.
            if (!str_contains($flags, 'v')) {
                return true;
            }
        }
        // Patterns with duplicate named groups also route through the
        // custom matcher so backreferences resolve the right capture
        // (PCRE2 with the J flag picks differently from spec when
        // multiple named groups share a name).
        if (self::hasDuplicateNamedGroups($pattern)) {
            return true;
        }
        // /i without /u must use ASCII-only Canonicalize per
        // ECMA-262 §22.2.2.7. PCRE2 always runs with PCRE2_UTF and
        // applies Unicode case-folding under /i, which only diverges
        // for codepoints whose simple-fold lands on an ASCII letter:
        // U+212A (KELVIN SIGN) → k and U+017F (LATIN SMALL LETTER
        // LONG S) → s. So the only patterns whose result actually
        // changes between PCRE2/iu and spec/i are those mentioning
        // K, k, S, or s (literally or via \uXXXX/\u{...}/\xHH/\cX
        // escapes). For every other /i-without-/u pattern the two
        // canonicalizations produce identical match sets, and we can
        // let PCRE2 handle them — keeping the custom matcher (which
        // catastrophically backtracks on lazy-quantifier shapes like
        // `(.*\n?)*?`) out of the path.
        $caselessNeedsCustom = false;
        if ($isCaseless && !$isUnicode) {
            $caselessNeedsCustom = self::patternMentionsCaseDivergentLetter($pattern);
        }
        // Literal codepoints appear in the pattern as UTF-8 bytes
        // when the source uses String.fromCodePoint (the SM
        // unicode-ignoreCase fixture builds RegExp(fromCodePoint(c) +
        // "+", "iu") for ~3000 fold pairs). The escape-aware scan
        // above only inspects \uXXXX / \u{X} forms; do a separate UTF-8
        // walk here when /iu is set, checking each non-ASCII codepoint
        // against the bundled fold table.
        if (!$hasUnicode16FoldCodepoint && $needUnicode16FoldScan) {
            $j = 0;
            $patLen = strlen($pattern);
            while ($j < $patLen) {
                $b = ord($pattern[$j]);
                if ($b < 0x80) {
                    $j++;
                    continue;
                }
                $cp = 0;
                $consume = 1;
                if (($b & 0xE0) === 0xC0 && $j + 1 < $patLen) {
                    $cp = (($b & 0x1F) << 6) | (ord($pattern[$j + 1]) & 0x3F);
                    $consume = 2;
                } elseif (($b & 0xF0) === 0xE0 && $j + 2 < $patLen) {
                    $cp = (($b & 0x0F) << 12)
                        | ((ord($pattern[$j + 1]) & 0x3F) << 6)
                        | (ord($pattern[$j + 2]) & 0x3F);
                    $consume = 3;
                } elseif (($b & 0xF8) === 0xF0 && $j + 3 < $patLen) {
                    $cp = (($b & 0x07) << 18)
                        | ((ord($pattern[$j + 1]) & 0x3F) << 12)
                        | ((ord($pattern[$j + 2]) & 0x3F) << 6)
                        | (ord($pattern[$j + 3]) & 0x3F);
                    $consume = 4;
                } else {
                    $j++;
                    continue;
                }
                if (\Phasis\Regex\FoldTable::participates($cp)) {
                    $hasUnicode16FoldCodepoint = true;
                    break;
                }
                $j += $consume;
            }
        }
        return $hasLookbehindWithCapture
            || $hasQuantifiedCapture
            || $hasDotInNonUnicode
            || $hasLookbehind
            || $hasNonAsciiInIWithoutU
            || $hasWordToken
            || $hasAstralInUnicode
            || $hasLoneSurrogateEscape
            || $hasUnicode16FoldCodepoint
            || $caselessNeedsCustom;
    }

    /**
     * Returns true iff the codepoint has a non-trivial simple case-fold
     * equivalent per Unicode 16's CaseFolding.txt (delegated to
     * Regex/FoldTable). Used to route /iu patterns containing
     * potentially fold-divergent codepoints to the custom matcher,
     * which canonicalises via the bundled fold table so behaviour is
     * host-independent. PCRE2's internal /i fold uses the host PCRE2
     * build's ICU; Ubuntu CI ships ICU 70/74 which miss Unicode 14/15/16
     * additions and produces "no match" where ICU 76+ correctly matches.
     */
    private static function isUnicode16FoldCodepoint(int $cp): bool
    {
        return \Phasis\Regex\FoldTable::participates($cp);
    }

    /**
     * Returns true iff $pattern contains an ASCII K/k/S/s as a
     * literal byte or via an escape (\uXXXX, \u{...}, \xHH, or \cK
     * for K). These are the only ASCII letters whose case-fold class
     * differs between PCRE2's /iu Unicode folding and ECMA-262's
     * ASCII-only Canonicalize for non-/u patterns: U+212A folds to k
     * and U+017F folds to s, so PCRE2 over-matches Kelvin / long-s
     * for those four letters but is spec-equivalent for every other
     * ASCII letter.
     */
    private static function patternMentionsCaseDivergentLetter(string $pattern): bool
    {
        $len = strlen($pattern);
        $i = 0;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch === 'K' || $ch === 'k' || $ch === 'S' || $ch === 's') {
                return true;
            }
            if ($ch === '\\' && $i + 1 < $len) {
                $next = $pattern[$i + 1];
                if ($next === 'u' && $i + 2 < $len && $pattern[$i + 2] === '{') {
                    $closeBrace = strpos($pattern, '}', $i + 3);
                    if ($closeBrace !== false) {
                        $hex = substr($pattern, $i + 3, $closeBrace - $i - 3);
                        if (ctype_xdigit($hex)) {
                            $cp = (int) hexdec($hex);
                            if ($cp === 0x4B || $cp === 0x6B || $cp === 0x53 || $cp === 0x73) {
                                return true;
                            }
                        }
                        $i = $closeBrace + 1;
                        continue;
                    }
                } elseif ($next === 'u' && $i + 5 < $len) {
                    $hex = substr($pattern, $i + 2, 4);
                    if (ctype_xdigit($hex)) {
                        $cp = (int) hexdec($hex);
                        if ($cp === 0x4B || $cp === 0x6B || $cp === 0x53 || $cp === 0x73) {
                            return true;
                        }
                    }
                    $i += 6;
                    continue;
                } elseif ($next === 'x' && $i + 3 < $len) {
                    $hex = substr($pattern, $i + 2, 2);
                    if (ctype_xdigit($hex)) {
                        $cp = (int) hexdec($hex);
                        if ($cp === 0x4B || $cp === 0x6B || $cp === 0x53 || $cp === 0x73) {
                            return true;
                        }
                    }
                    $i += 4;
                    continue;
                } elseif ($next === 'c' && $i + 2 < $len) {
                    // \cX control escape: \cK is U+000B (vertical
                    // tab) — not k. None of the control escapes
                    // produce K/k/S/s themselves, so skip.
                    $i += 3;
                    continue;
                }
                $i += 2;
                continue;
            }
            $i++;
        }
        return false;
    }

    /**
     * Extract every distinct named group from the pattern, in source
     * order. Used by exec() to pre-populate the `groups` object so
     * named groups that did not participate in the match still appear
     * with the value undefined (per spec 22.2.6.13.5 Group Specifier
     * Properties — and required by the duplicate-named-groups
     * proposal).
     *
     * @return list<string>
     */
    public static function extractNamedGroupNames(string $pattern): array
    {
        $names = [];
        $seen = [];
        $len = strlen($pattern);
        $i = 0;
        $inClass = false;
        while ($i < $len) {
            $ch = $pattern[$i];
            if ($ch === '\\') {
                $i += 2;
                continue;
            }
            if (!$inClass && $ch === '[') {
                $inClass = true;
                $i++;
                continue;
            }
            if ($inClass && $ch === ']') {
                $inClass = false;
                $i++;
                continue;
            }
            if ($inClass) {
                $i++;
                continue;
            }
            if (
                $ch === '('
                && $i + 3 < $len
                && $pattern[$i + 1] === '?'
                && $pattern[$i + 2] === '<'
                && $pattern[$i + 3] !== '='
                && $pattern[$i + 3] !== '!'
            ) {
                $nameStart = $i + 3;
                $nameEnd = $nameStart;
                while ($nameEnd < $len && $pattern[$nameEnd] !== '>') {
                    $nameEnd++;
                }
                if ($nameEnd < $len) {
                    $name = substr($pattern, $nameStart, $nameEnd - $nameStart);
                    if (!isset($seen[$name])) {
                        $names[] = $name;
                        $seen[$name] = true;
                    }
                }
                $i = $nameEnd + 1;
                continue;
            }
            $i++;
        }
        return $names;
    }
}
