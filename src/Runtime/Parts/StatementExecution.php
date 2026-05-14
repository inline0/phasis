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
use Phasis\Runtime\Completion;
use Phasis\Runtime\CompletionType;
use Phasis\Runtime\Reference;
use Phasis\Runtime\TailCallThunk;

/**
 * Interpreter part: StatementExecution. Composed into Interpreter via
 * `use Parts\StatementExecution;`. `self::`/`$this->` references resolve
 * into the composing class.
 */
trait StatementExecution
{
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
            // ResolveBinding triggers HasBinding (Proxy has trap) exactly once.
            // PutValue then uses the resolved Reference directly, avoiding
            // a redundant second has-trap call.
            $resolvedWithObj = null;
            $resolvedOuterEnv = null;
            if (
                $node->kind === 'var'
                && $hasInit
                && $declarator->id instanceof Identifier
                && !empty($this->withEnvObjects)
                && !$env->hasOwnBinding($declarator->id->name)
            ) {
                $name = $declarator->id->name;
                // Walk the env chain to find a with-environment that has this binding.
                $walkEnv = $env;
                while ($walkEnv !== null) {
                    $envId = spl_object_id($walkEnv);
                    if (isset($this->withEnvObjects[$envId])) {
                        $withObj = $this->withEnvObjects[$envId];
                        if ($withObj->has($name)) {
                            $resolvedWithObj = $withObj;
                            break;
                        }
                        // Proxy returned false: the binding is in the outer scope.
                        // Record the parent env so PutValue writes there directly.
                        if ($walkEnv->getParent() !== null) {
                            $resolvedOuterEnv = $walkEnv->getParent();
                        }
                    }
                    $walkEnv = $walkEnv->getParent();
                }
            }

            // For anonymous class expressions, pass the binding name down
            // into ClassDefinitionEvaluation so static fields observe the
            // name (spec NamedEvaluation). Name inference for non-class
            // anonymous functions is still handled after evaluation.
            if (
                $hasInit
                && $declarator->init instanceof ClassExpression
                && $declarator->init->id === null
                && $declarator->id instanceof Identifier
            ) {
                $init = $this->evalClassExpression($declarator->init, $env, $declarator->id->name);
            } else {
                $init = $hasInit
                    ? $this->evaluate($declarator->init, $env)
                    : JsUndefined::instance();
            }

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
                    } elseif ($resolvedOuterEnv !== null && $declarator->id instanceof Identifier) {
                        // ResolveBinding found the binding in the outer scope (Proxy
                        // returned false for has). Write directly to avoid a redundant
                        // second has-trap call.
                        $resolvedOuterEnv->set($declarator->id->name, $init, false);
                    } else {
                        $this->assignVarBinding($declarator->id, $init, $env);
                    }
                }
                // else: var without init — no-op. Hoisting already created the binding.
                // If the binding was deleted (eval-created deletable binding), it should
                // remain deleted per spec.
            } else {
                $this->declareBinding($node->kind, $declarator->id, $init, $env);
            }
        }
        // Per spec §14.3.2.1: VariableStatement → NormalCompletion(empty).
        return Completion::normalEmpty();
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
            try {
                foreach ($pattern->elements as $element) {
                    if ($element instanceof RestElement) {
                        $rest = $this->iteratorRest($iterator, $nextMethod, $done);
                        $this->assignVarBinding($element->argument, $rest, $env);
                        $done = true;
                        break;
                    }
                    $elemValue = $this->iteratorNext($iterator, $nextMethod, $done);
                    if ($element === null) {
                        continue;
                    }
                    $this->assignVarBinding($element, $elemValue, $env);
                }
            } catch (\Throwable $e) {
                if (!$done) {
                    $this->iteratorClose($iterator, $e);
                }
                throw $e;
            }
            if (!$done) {
                $this->iteratorClose($iterator);
            }
            return;
        }
        if ($pattern instanceof ObjectPattern) {
            if ($value instanceof JsNull || $value instanceof JsUndefined) {
                throw new \Phasis\Exceptions\TypeError(
                    "Cannot destructure property of " . TypeConversion::toString($value),
                );
            }
            $usedKeysAvb = [];
            $usedSymIdsAvb = [];
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof RestElement) {
                    $restObjAvb = new JsObject();
                    if ($value instanceof JsObject) {
                        // Delegate to CopyDataProperties so Proxy ownKeys
                        // trap fires and both string and symbol keys are
                        // copied (§14.3.3.1 RestBindingInitialization).
                        $this->copyRestDataProperties($value, $restObjAvb, $usedKeysAvb, $usedSymIdsAvb);
                    }
                    $this->assignVarBinding($prop->argument, $restObjAvb, $env);
                    break;
                }
                if ($prop instanceof AssignmentProperty) {
                    // Resolve the property name. Computed keys may evaluate to
                    // a Symbol, in which case we track it under the symbol
                    // exclusion set and skip the string-key conversion.
                    $symKey = null;
                    $key = '';
                    if ($prop->computed) {
                        $rawKey = $this->evaluate($prop->key, $env);
                        if ($rawKey instanceof JsSymbol) {
                            $symKey = $rawKey;
                            $usedSymIdsAvb[$rawKey->getId()] = true;
                        } else {
                            $key = TypeConversion::toString($rawKey);
                            $usedKeysAvb[] = $key;
                        }
                    } else {
                        $key = $prop->key instanceof Identifier
                            ? $prop->key->name
                            : TypeConversion::toString($this->evaluate($prop->key, $env));
                        $usedKeysAvb[] = $key;
                    }

                    // Per spec 14.3.3.3 KeyedBindingInitialization:
                    // Step 2: ResolveBinding(bindingId) BEFORE GetV.
                    // This triggers HasBinding (Proxy has trap) at the correct time.
                    $resolvedBindingEnv = null;
                    if (!empty($this->withEnvObjects)) {
                        $bindingTarget = $prop->value;
                        if ($bindingTarget instanceof AssignmentPattern) {
                            $bindingTarget = $bindingTarget->left;
                        }
                        if ($bindingTarget instanceof Identifier) {
                            $resolvedBindingEnv = $this->resolveBindingForWith(
                                $bindingTarget->name,
                                $env,
                            );
                        }
                    }

                    if ($symKey !== null) {
                        $propValue = ($value instanceof JsObject)
                            ? $value->getBySymbol($symKey)
                            : JsUndefined::instance();
                    } else {
                        $propValue = ($value instanceof JsObject)
                            ? $value->get($key)
                            : JsUndefined::instance();
                    }

                    if ($resolvedBindingEnv !== null) {
                        $this->assignVarBindingResolved($prop->value, $propValue, $env, $resolvedBindingEnv);
                    } else {
                        $this->assignVarBinding($prop->value, $propValue, $env);
                    }
                }
            }
            return;
        }
        if ($pattern instanceof AssignmentPattern) {
            if ($value instanceof JsUndefined) {
                $value = $this->evaluate($pattern->right, $env);
                // Function name inference: check AST node type.
                // Per spec, only apply if HasOwnProperty(value, "name") is false,
                // i.e. the function does not have an explicitly set name property
                // (e.g. from a static name() method on a class).
                if (
                    $value instanceof JsFunction
                    && $pattern->left instanceof Identifier
                    && $this->isAnonymousFunctionDefinitionNode($pattern->right)
                    && !$this->hasExplicitNameProperty($value)
                ) {
                    $value->setName($pattern->left->name);
                }
            }
            $this->assignVarBinding($pattern->left, $value, $env);
        }
    }

    /**
     * Resolve a binding through the with-environment scope chain.
     * Triggers HasBinding (Proxy has trap) as required by spec.
     * Returns the environment where the binding was found, or null if
     * no with-environment is in the chain.
     */
    private function resolveBindingForWith(string $name, Environment $env): ?Environment
    {
        $walkEnv = $env;
        while ($walkEnv !== null) {
            $envId = spl_object_id($walkEnv);
            if (isset($this->withEnvObjects[$envId])) {
                $withObj = $this->withEnvObjects[$envId];
                if ($withObj->has($name)) {
                    // The with-object owns this binding.
                    return $walkEnv;
                }
                // Proxy returned false: binding is in the outer scope.
                if ($walkEnv->getParent() !== null) {
                    return $walkEnv->getParent();
                }
            }
            $walkEnv = $walkEnv->getParent();
        }
        return null;
    }

    /**
     * Like assignVarBinding but for the final assignment step, uses a pre-resolved
     * environment to avoid triggering redundant Proxy has traps.
     */
    private function assignVarBindingResolved(
        Node $pattern,
        JsValue $value,
        Environment $env,
        Environment $resolvedEnv,
    ): void {
        if ($pattern instanceof Identifier) {
            $resolvedEnv->set($pattern->name, $value, false);
            return;
        }
        if ($pattern instanceof AssignmentPattern) {
            if ($value instanceof JsUndefined) {
                $value = $this->evaluate($pattern->right, $env);
                if (
                    $value instanceof JsFunction
                    && $pattern->left instanceof Identifier
                    && $this->isAnonymousFunctionDefinitionNode($pattern->right)
                    && !$this->hasExplicitNameProperty($value)
                ) {
                    $value->setName($pattern->left->name);
                }
            }
            $this->assignVarBindingResolved($pattern->left, $value, $env, $resolvedEnv);
            return;
        }
        // Fallback for non-simple patterns.
        $this->assignVarBinding($pattern, $value, $env);
    }

    private function declareBinding(string $kind, Node $pattern, JsValue $value, Environment $env): void
    {
        if ($pattern instanceof Identifier) {
            if ($kind === 'using' || $kind === 'await using') {
                $env->defineConst($pattern->name, $value);
                $this->registerDisposable($value, $kind === 'await using', $env);
            } else {
                match ($kind) {
                    'var' => $env->defineVar($pattern->name, $value),
                    'let' => $env->defineLet($pattern->name, $value),
                    'const' => $env->defineConst($pattern->name, $value),
                    default => $env->defineVar($pattern->name, $value),
                };
            }
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
        // Per spec ClassDefinitionEvaluation: create an inner scope for the
        // class name so that methods close over an immutable binding of the
        // class name. The outer scope gets a separate mutable let binding.
        $classEnv = $env;
        if ($node->id !== null) {
            $classEnv = $env->createChild();
            $classEnv->declareConst($node->id->name);
        }
        $cls = $this->buildClass($node->id?->name, $node->superClass, $body, $classEnv);
        if ($node->id !== null && $classEnv->isInTdz($node->id->name)) {
            $classEnv->initialize($node->id->name, $cls);
        }
        // Per spec, Function.prototype.toString on a class returns the full class source text.
        if ($node->sourceText !== null) {
            $cls->setSourceText($node->sourceText);
        }
        // Apply class decorators (evaluated in reverse order, innermost first).
        $cls = $this->applyClassDecorators($node->decorators, $cls, $env);
        if ($node->id !== null) {
            // Class declarations are lexical bindings (like let), not var bindings.
            // They must NOT be visible as properties on the global object.
            $env->defineLet($node->id->name, $cls);
        }
        // Per spec 15.7.5: ClassDeclaration evaluates to empty so preceding
        // statement-list values are not clobbered.
        return Completion::normalEmpty();
    }

    /**
     * Apply class-level decorators. Each decorator is a function that receives
     * (value, context) and may return a replacement value.
     *
     * @param Node[] $decorators
     */
    private function applyClassDecorators(array $decorators, JsFunction $cls, Environment $env): JsFunction
    {
        if (empty($decorators)) {
            return $cls;
        }
        // Evaluate all decorators first (left to right), then apply (right to left).
        $fns = [];
        foreach ($decorators as $decorator) {
            $fns[] = $this->evaluate($decorator, $env);
        }
        $result = $cls;
        for ($i = count($fns) - 1; $i >= 0; $i--) {
            $fn = $fns[$i];
            if (!$fn instanceof JsFunction) {
                throw new TypeError('A decorator must be a function');
            }
            $context = new JsObject();
            $context->set('kind', new JsString('class'));
            $context->set('name', $cls->getName() !== ''
                ? new JsString($cls->getName())
                : JsUndefined::instance());
            $ret = $this->callFunction($fn, JsUndefined::instance(), [$result, $context]);
            if (!($ret instanceof JsUndefined)) {
                if (!$ret instanceof JsFunction) {
                    throw new TypeError('A class decorator must return a constructor or undefined');
                }
                $result = $ret;
            }
        }
        return $result;
    }

    /** @param list<Node> $elements ClassMethod, ClassProperty, or StaticBlock nodes. */
    private function buildClass(
        ?string $name,
        ?Node $superClassNode,
        array $elements,
        Environment $env,
        bool $hasInnerNameBinding = true,
    ): JsFunction {
        // Per spec, ALL parts of a class definition are strict mode code,
        // including the ClassHeritage expression. Flip strict mode BEFORE
        // evaluating the heritage so any FunctionExpression/ArrowFunction
        // produced there carries the strict flag (e.g. `class extends
        // function(){ arguments.callee }` must throw on access).
        $previousStrictMode = $this->strictMode;
        $this->strictMode = true;

        $superClass = $superClassNode !== null
            ? $this->evaluate($superClassNode, $env)
            : null;

        // Per spec §15.7.14: if ClassHeritage is present and not null, it must be a constructor.
        if ($superClass !== null && !($superClass instanceof \Phasis\Value\JsNull)) {
            $isConstructor = false;
            if ($superClass instanceof JsFunction && $superClass->isConstructable()) {
                $isConstructor = true;
            } elseif ($superClass instanceof \Phasis\Value\JsProxy && $superClass->isConstructable()) {
                $isConstructor = true;
            }
            if (!$isConstructor) {
                // Avoid triggering proxy traps when constructing the error message.
                $superStr = $superClass instanceof \Phasis\Value\JsProxy
                    ? 'function () { [native code] }'
                    : TypeConversion::toString($superClass);
                $this->strictMode = $previousStrictMode;
                throw new TypeError(
                    'Class extends value ' . $superStr . ' is not a constructor or null',
                );
            }
        }

        $constructor = null;
        $staticMethods = [];
        $instanceMethods = [];
        $instanceFields = [];
        $privateInstanceMethods = [];
        $privateStaticMethods = [];
        // Auto-accessor (`accessor name = init`) storage-slot initializers.
        // Public and private alike: each entry is [storageKey, initNode|null].
        // For static accessors, the third element is true if private.
        $instanceAutoAccessorInits = [];
        $staticAutoAccessorInits = [];

        // Per spec ClassDefinitionEvaluation: create a new PrivateEnvironment.
        // Each evaluation of a class body generates unique branded private names
        // so that instances from different evaluations of the same class expression
        // have distinct private fields (PrivateBrandCheck).
        $brandId = self::$nextPrivateBrandId++;
        $privateNames = [];
        foreach ($elements as $element) {
            if (
                ($element instanceof ClassMethod || $element instanceof ClassProperty)
                && $element->key instanceof PrivateIdentifier
            ) {
                $privateNames[$element->key->name] = true;
            }
        }
        $privateNameMap = [];
        foreach ($privateNames as $pname => $_) {
            $privateNameMap[$pname] = $pname . '@' . $brandId;
        }
        // Create a private environment that maps source-level private names to branded names.
        $privateEnv = $env->createChild();
        $privateEnv->setPrivateNameMap($privateNameMap);

        // Evaluate computed keys in source order at class definition time.
        $computedKeys = [];
        foreach ($elements as $i => $element) {
            if (($element instanceof ClassMethod || $element instanceof ClassProperty) && $element->computed) {
                $computedKeys[$i] = $this->evaluate($element->key, $privateEnv);
            }
        }

        foreach ($elements as $i => $element) {
            if ($element instanceof StaticBlock) {
                continue; // Handled after constructor setup
            }
            if ($element instanceof ClassProperty) {
                if ($element->isAccessor) {
                    $this->collectAutoAccessor(
                        $element,
                        $i,
                        $privateEnv,
                        $privateNameMap,
                        $computedKeys,
                        $instanceMethods,
                        $staticMethods,
                        $privateInstanceMethods,
                        $privateStaticMethods,
                        $instanceAutoAccessorInits,
                        $staticAutoAccessorInits,
                    );
                    continue;
                }
                if (!$element->static) {
                    $instanceFields[] = [$element, $i];
                }
                continue; // Static fields handled after constructor setup
            }
            if (!($element instanceof ClassMethod)) {
                continue;
            }

            $method = $element;
            $isPrivate = $method->key instanceof PrivateIdentifier;
            $symbolKey = null;

            if ($isPrivate) {
                $key = $privateNameMap[$method->key->name] ?? $method->key->name;
            } elseif (isset($computedKeys[$i])) {
                $keyVal = TypeConversion::toPropertyKey($computedKeys[$i]);
                if ($keyVal instanceof \Phasis\Value\JsSymbol) {
                    $symbolKey = $keyVal;
                    $key = '';
                } else {
                    $key = TypeConversion::toString($keyVal);
                }
            } else {
                $key = $method->key instanceof Identifier
                    ? $method->key->name
                    : TypeConversion::toString($this->evaluate($method->key, $privateEnv));
            }

            $fn = $this->evaluate($method->value, $privateEnv);

            if ($fn instanceof JsFunction && $method->kind !== 'constructor') {
                if ($symbolKey !== null) {
                    // Per spec, symbol-keyed method name is [description] or empty string.
                    $desc = $symbolKey->getDescription();
                    $symName = $desc !== null ? "[{$desc}]" : '';
                    $methodName = $method->kind === 'get' || $method->kind === 'set'
                        ? "{$method->kind} {$symName}"
                        : $symName;
                } else {
                    // For private methods, use the source-level name (e.g. "#m")
                    // rather than the branded internal key (e.g. "#m@0").
                    $displayKey = $isPrivate ? $method->key->name : $key;
                    $methodName = $method->kind === 'get' || $method->kind === 'set'
                        ? "{$method->kind} {$displayKey}"
                        : $displayKey;
                }
                if (!$this->hasExplicitNameProperty($fn)) {
                    $fn->setName($methodName);
                }
            }

            if ($method->kind !== 'constructor' && $fn instanceof JsFunction) {
                $fn->setNonConstructable();
                // Generator (sync or async) methods keep their .prototype —
                // it controls the prototype of the returned generator. Other
                // methods (regular, async non-generator, get, set) drop it.
                if (!$fn->isGenerator()) {
                    $fn->forceDelete('prototype');
                }
                // Class methods / getters / setters are "newer-type"
                // functions per Forbidden Extensions §16.2 — drop the
                // legacy .arguments / .caller slots so prototype walk
                // hits the Function.prototype thrower.
                $fn->forceDelete('arguments');
                $fn->forceDelete('caller');
            }

            if ($method->kind === 'constructor') {
                $constructor = $fn;
            } elseif ($isPrivate) {
                if ($method->static) {
                    $privateStaticMethods[] = [$key, $fn, $method->kind];
                } else {
                    $privateInstanceMethods[] = [$key, $fn, $method->kind];
                }
            } elseif ($method->static) {
                $staticMethods[] = [$key, $fn, $method->kind, $symbolKey];
            } else {
                $instanceMethods[] = [$key, $fn, $method->kind, $symbolKey];
            }
        }

        // Per spec 15.7.14 step 15: if ClassHeritage is present (even if null),
        // set [[ConstructorKind]] to "derived". This means `this` starts uninitialized.
        $isDerived = $superClassNode !== null;

        if ($constructor === null) {
            // Default constructor. The native callable signature must accept 2 args
            // (JsFunction::call passes only thisVal and args) OR 3 (Interpreter::callFunction
            // passes thisVal, args, and interp). Use optional third param for safety.
            $self = $this;
            $needsFieldInit = true;
            if ($isDerived && $superClass instanceof JsFunction) {
                // Anonymous-class holder so PHPStan can track the
                // constructor self-reference assigned after the closure
                // is created (a by-ref local would still be inferred as
                // null at closure-capture time).
                $ref = new class {
                    public ?JsFunction $fn = null;
                };
                $constructor = JsFunction::fromCallable(
                    $name ?? '(anonymous)',
                    function (JsValue $thisVal, array $args) use ($ref, $self) {
                        // Spec default derived constructor: GetSuperConstructor
                        // looks up via [[GetPrototypeOf]](activeFunction) so a
                        // runtime Object.setPrototypeOf on the class redirects
                        // super(...args) to the new parent.
                        $activeSuper = $ref->fn instanceof JsFunction
                            ? $ref->fn->getPrototype()
                            : null;
                        if (!$activeSuper instanceof JsFunction || !$activeSuper->isConstructable()) {
                            throw new TypeError('Super constructor must be a constructor');
                        }
                        if (
                            $thisVal instanceof JsObject
                            && $activeSuper->isClassConstructor()
                            && !$activeSuper->isDerivedConstructor()
                        ) {
                            $self->initializeInstanceFields(
                                $activeSuper,
                                $thisVal,
                                $activeSuper->getPrivateEnv() ?? $self->getGlobalEnv(),
                            );
                        }
                        return $self->callFunction($activeSuper, $thisVal, $args);
                    },
                )->setConstructable();
                $ref->fn = $constructor;
            } elseif ($isDerived && $superClass instanceof \Phasis\Value\JsProxy) {
                // Reaching here, superClass already passed the constructor
                // validation above (8593-8605); JsProxy targets in the
                // derived path are guaranteed constructable.
                $ref = new class {
                    public ?JsFunction $fn = null;
                };
                $constructor = JsFunction::fromCallable(
                    $name ?? '(anonymous)',
                    function (JsValue $thisVal, array $args) use ($ref) {
                        // Per spec, the default constructor's super() uses the
                        // active function's new.target, not the super itself.
                        $newTarget = $ref->fn;
                        if ($thisVal instanceof JsObject) {
                            $nt = $thisVal->get('[[NewTarget]]');
                            if ($nt instanceof JsFunction || $nt instanceof \Phasis\Value\JsProxy) {
                                $newTarget = $nt;
                            }
                        }
                        $activeSuper = $ref->fn instanceof JsFunction
                            ? $ref->fn->getPrototype()
                            : null;
                        if ($activeSuper instanceof \Phasis\Value\JsProxy && $activeSuper->isConstructable()) {
                            return $activeSuper->construct($args, $newTarget ?? $activeSuper);
                        }
                        if ($activeSuper instanceof JsFunction && $activeSuper->isConstructable()) {
                            return $activeSuper->construct($args);
                        }
                        throw new TypeError('Super constructor must be a constructor');
                    },
                )->setConstructable();
                $ref->fn = $constructor;
            } elseif ($isDerived && $superClass instanceof \Phasis\Value\JsNull) {
                // class C extends null { }: default constructor is
                // `constructor(...args) { super(...args); }` per spec, which
                // throws because GetSuperConstructor returns %FunctionPrototype%
                // (not a constructor).
                $constructor = JsFunction::fromCallable(
                    $name ?? '(anonymous)',
                    function (): JsValue {
                        throw new TypeError('Super constructor must be a constructor');
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

        // Per spec, the class constructor's name is the class name.
        if ($name !== null) {
            $constructor->setName($name);
        }

        // Set up prototype chain.
        // `class C extends null` must produce C.prototype with null [[Prototype]].
        // `new JsObject(null)` would fall back to globalPrototype due to `??`, so we
        // use setPrototype() explicitly for the null-heritage case.
        // The constructor validation above (8593-8605) guarantees that any
        // JsProxy in the derived path is constructable, so the check here
        // collapses to a plain instanceof.
        $superIsConstructor = $superClass instanceof JsFunction
            || $superClass instanceof \Phasis\Value\JsProxy;
        if ($superIsConstructor) {
            $superProto = $superClass->get('prototype');
            // Per spec 15.7.14 step 6.g.iv: if protoParent is neither Object nor Null, throw TypeError.
            if (!($superProto instanceof JsObject) && !($superProto instanceof \Phasis\Value\JsNull)) {
                throw new TypeError(
                    'Class extends value does not have valid prototype property',
                );
            }
            $proto = new JsObject($superProto instanceof JsObject ? $superProto : null);
            if ($superProto instanceof \Phasis\Value\JsNull) {
                $proto->setPrototype(null);
            }
        } elseif ($superClassNode !== null && $superClass instanceof \Phasis\Value\JsNull) {
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
            if ($symbolKey !== null && ($kind === 'get' || $kind === 'set')) {
                // Symbol-keyed accessor (e.g. get [Symbol.toStringTag]() {})
                $existing = $proto->getPropertyDescriptorBySymbol($symbolKey);
                if ($kind === 'get') {
                    $proto->definePropertyBySymbol($symbolKey, PropertyDescriptor::accessor(
                        $fn instanceof JsFunction ? $fn : null,
                        $existing?->set,
                        enumerable: false,
                        configurable: true,
                    ));
                } else {
                    $proto->definePropertyBySymbol($symbolKey, PropertyDescriptor::accessor(
                        $existing?->get,
                        $fn instanceof JsFunction ? $fn : null,
                        enumerable: false,
                        configurable: true,
                    ));
                }
            } elseif ($symbolKey !== null) {
                // Symbol-keyed method (e.g. [Symbol.replace], [Symbol.iterator])
                $proto->definePropertyBySymbol($symbolKey, PropertyDescriptor::data(
                    $fn,
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
                    $fn,
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
            if ($symbolKey !== null && ($kind === 'get' || $kind === 'set')) {
                // Symbol-keyed static accessor
                $existing = $constructor->getPropertyDescriptorBySymbol($symbolKey);
                if ($kind === 'get') {
                    $constructor->definePropertyBySymbol($symbolKey, PropertyDescriptor::accessor(
                        $fn instanceof JsFunction ? $fn : null,
                        $existing?->set,
                        enumerable: false,
                        configurable: true,
                    ));
                } else {
                    $constructor->definePropertyBySymbol($symbolKey, PropertyDescriptor::accessor(
                        $existing?->get,
                        $fn instanceof JsFunction ? $fn : null,
                        enumerable: false,
                        configurable: true,
                    ));
                }
                continue;
            } elseif ($symbolKey !== null) {
                $constructor->definePropertyBySymbol($symbolKey, PropertyDescriptor::data(
                    $fn,
                    true,
                    false,
                    true,
                ));
                continue;
            }
            // Per spec §15.7.1: it is a SyntaxError if a static method is named "prototype".
            if ($key === 'prototype') {
                throw new \Phasis\Exceptions\TypeError(
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
                    $fn,
                    true,
                    false,
                    true,
                ));
            }
        }

        // Register instance field initializers on the constructor.
        foreach ($instanceFields as [$field, $idx]) {
            $isPrivate = $field->key instanceof PrivateIdentifier;
            if ($isPrivate) {
                $constructor->addInstanceFieldInitializer(
                    $privateNameMap[$field->key->name] ?? $field->key->name,
                    $field->value,
                    false,
                    true,
                );
            } elseif (isset($computedKeys[$idx])) {
                $keyVal = TypeConversion::toPropertyKey($computedKeys[$idx]);
                if ($keyVal instanceof \Phasis\Value\JsSymbol) {
                    $constructor->addInstanceFieldInitializer($keyVal, $field->value, true, false);
                } else {
                    $constructor->addInstanceFieldInitializer(
                        TypeConversion::toString($keyVal),
                        $field->value,
                        false,
                        false,
                    );
                }
            } else {
                $keyStr = $field->key instanceof Identifier
                    ? $field->key->name
                    : TypeConversion::toString($this->evaluate($field->key, $privateEnv));
                $constructor->addInstanceFieldInitializer($keyStr, $field->value, false, false);
            }
        }

        // Register auto-accessor storage-slot initializers. Each slot is a
        // hidden `[[AutoAccessor:N]]` data property primed at construction
        // time. The slot key is a [[…]] internal slot, so it's filtered from
        // ownKeys / for-in / Object.keys.
        foreach ($instanceAutoAccessorInits as [$storageKey, $initNode]) {
            $constructor->addInstanceFieldInitializer($storageKey, $initNode, false, false);
        }

        // Register private instance methods on the constructor.
        foreach ($privateInstanceMethods as [$key, $fn, $kind]) {
            if (!$fn instanceof JsFunction) {
                continue;
            }
            $fn->setHomeObject($proto);
            $constructor->addPrivateMethodEntry($key, $fn, $kind);
        }

        // Store the class's private/lexical environment on the constructor.
        // Field initializers (which run at construction time) need this to
        // resolve branded private names AND to see the inner class-name
        // binding (e.g. \`class Inner { field = Inner; }\`). Always set it
        // so the lexical scope chain is preserved even when no private
        // names are declared.
        $constructor->setPrivateEnv($privateEnv);

        // Inheritance: set [[Prototype]] of constructor to super class.
        if ($superClass instanceof JsFunction) {
            $constructor->setCustomPrototype($superClass);
        } elseif ($superClass instanceof \Phasis\Value\JsProxy && $superClass->isConstructable()) {
            // Extending a callable Proxy: the constructor's [[Prototype]] is
            // the proxy itself so super() resolves to the proxy via
            // [[GetPrototypeOf]](activeFunction).
            $constructor->setCustomPrototype($superClass);
        }

        // Per spec, the class constructor's [[HomeObject]] is the class
        // prototype regardless of whether there is a superclass. This lets
        // super.X property access inside the constructor body reach
        // Object.prototype for non-derived classes.
        $constructor->setHomeObject($proto);

        // Per spec ClassDefinitionEvaluation step 16: bind the class name in the
        // class scope BEFORE evaluating static fields and static blocks, so they
        // can reference the class by name. Only do this when the class has its
        // own inner-name binding (named class expressions / class declarations).
        // For anonymous class expressions assigned to `let X = class { ... }`,
        // the X binding lives in the outer scope and must remain in TDZ during
        // static field evaluation per spec.
        if ($hasInnerNameBinding && $name !== null && $env->hasOwnBinding($name) && $env->isInTdz($name)) {
            $env->initialize($name, $constructor);
        }

        // Install private static methods on the constructor BEFORE static
        // fields and static blocks run, so static initializers can resolve
        // private static methods/getters/setters via C.#name.
        foreach ($privateStaticMethods as [$key, $fn, $kind]) {
            if (!$fn instanceof JsFunction) {
                continue;
            }
            $fn->setHomeObject($constructor);
            if ($kind === 'get' || $kind === 'set') {
                if ($kind === 'get') {
                    $existingAccessor = $constructor->hasPrivateField($key)
                        ? $constructor->getPrivateFieldRaw($key)
                        : null;
                    $setter = is_array($existingAccessor) ? $existingAccessor[1] : null;
                    $constructor->setPrivateAccessor($key, [$fn, $setter]);
                } else {
                    $existingAccessor = $constructor->hasPrivateField($key)
                        ? $constructor->getPrivateFieldRaw($key)
                        : null;
                    $getter = is_array($existingAccessor) ? $existingAccessor[0] : null;
                    $constructor->setPrivateAccessor($key, [$getter, $fn]);
                }
            } else {
                $constructor->setPrivateMethod($key, $fn);
            }
        }

        // Per spec, static field initializers have `this` bound to the
        // constructor and an implicit [[HomeObject]] pointing at it. Create a
        // scoped environment so inner expressions (and eval) observe `this`.
        $staticEnv = $privateEnv->createChild();
        $staticEnv->defineVar('this', $constructor);
        $staticEnv->defineVar('[[HomeObject]]', $constructor);
        $staticEnv->defineVar('[[ClassFieldInitializer]]', new JsBoolean(true));
        // Per spec, static field initializers have an implicit [[NewTarget]]
        // of undefined (they run at class-definition time, not construct).
        $staticEnv->defineVar('[[NewTarget]]', JsUndefined::instance());

        // staticAutoAccessorInits is in source order (collectAutoAccessor is
        // called from the source-order loop above). Consume entries in order
        // as we encounter `static accessor` elements in the static-fields pass.
        $staticAccessorCursor = 0;

        // Evaluate static fields and static blocks at class definition time.
        foreach ($elements as $i => $element) {
            if ($element instanceof ClassProperty && $element->static) {
                if ($element->isAccessor) {
                    // Initialize the hidden storage slot on the constructor
                    // for this static auto-accessor. Storage entries are in
                    // source order (see collectAutoAccessor).
                    [$storageKey, $initNode] = [
                        $staticAutoAccessorInits[$staticAccessorCursor][0],
                        $staticAutoAccessorInits[$staticAccessorCursor][1],
                    ];
                    $staticAccessorCursor++;
                    $value = $initNode !== null
                        ? $this->evaluate($initNode, $staticEnv)
                        : JsUndefined::instance();
                    $constructor->defineOwnProperty(
                        $storageKey,
                        PropertyDescriptor::data($value, true, false, true),
                    );
                    continue;
                }
                $isPrivate = $element->key instanceof PrivateIdentifier;
                if ($isPrivate) {
                    $fieldKey = $privateNameMap[$element->key->name] ?? $element->key->name;
                } elseif (isset($computedKeys[$i])) {
                    $keyVal = TypeConversion::toPropertyKey($computedKeys[$i]);
                    if ($keyVal instanceof \Phasis\Value\JsSymbol) {
                        $constructor->definePropertyBySymbol($keyVal, PropertyDescriptor::data(
                            $element->value !== null
                                ? $this->evaluate($element->value, $staticEnv)
                                : JsUndefined::instance(),
                            true,
                            true,
                            true,
                        ));
                        continue;
                    }
                    $fieldKey = TypeConversion::toString($keyVal);
                } else {
                    $fieldKey = $element->key instanceof Identifier
                        ? $element->key->name
                        : TypeConversion::toString($this->evaluate($element->key, $privateEnv));
                }

                $fieldValue = $element->value !== null
                    ? $this->evaluate($element->value, $staticEnv)
                    : JsUndefined::instance();

                // NamedEvaluation for static fields: assign the field's name to
                // anonymous function-valued initializers.
                if ($fieldValue instanceof JsFunction && !$this->hasExplicitNameProperty($fieldValue)) {
                    if ($isPrivate) {
                        // Source-level private name, e.g. "#field".
                        $nameToUse = $element->key->name;
                    } else {
                        $nameToUse = $fieldKey;
                    }
                    $fieldValue->setName($nameToUse);
                }

                if ($isPrivate) {
                    $constructor->setPrivateField($fieldKey, $fieldValue);
                } else {
                    if ($fieldKey === 'prototype') {
                        throw new \Phasis\Exceptions\TypeError(
                            "Classes may not have a static property named 'prototype'",
                        );
                    }
                    $constructor->defineOwnProperty($fieldKey, PropertyDescriptor::data(
                        $fieldValue,
                        true,
                        true,
                        true,
                    ));
                }
            } elseif ($element instanceof StaticBlock) {
                $blockEnv = $privateEnv->createChild();
                // Per spec, static blocks have their own var scope (like function bodies).
                $blockEnv->setFunctionKind('static-block');
                $blockEnv->defineVar('this', $constructor);
                // Per spec, the home object for a class static initialization
                // block is the parent class (i.e. the class constructor), so
                // super.X property access resolves against the constructor's
                // [[Prototype]].
                $blockEnv->defineVar('[[HomeObject]]', $constructor);
                // Per spec, new.target is undefined inside static blocks.
                $blockEnv->defineVar('[[NewTarget]]', JsUndefined::instance());
                $this->hoistDeclarations($element->body->body, $blockEnv);
                $this->hoistEvalLexicalDeclarations($element->body->body, $blockEnv);
                $sbCompletion = $this->executeBody($element->body->body, $blockEnv);
                $sbCompletion = $this->applyDisposals($blockEnv, $sbCompletion);
                if ($sbCompletion->type === CompletionType::Throw) {
                    $this->throwJsValue($sbCompletion->value);
                }
            }
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
        // Anonymous function declarations (from export default function() {}) do nothing at execution.
        if ($node->id === null) {
            return Completion::normalEmpty();
        }

        if (!$this->strictMode) {
            $name = $node->id->name;
            // Per B.3.3.1 step 3: propagate the existing block-scoped function
            // to the variable environment. The JsFunction was already created
            // during hoistDeclarations and lives in $env (the current
            // lexical/block scope); reuse that exact object so the block's
            // x and the function-scope x are identity-equal per spec.
            if (isset($this->annexBEligible[spl_object_id($node)])) {
                try {
                    $blockFn = $env->get($name);
                } catch (\Throwable) {
                    $blockFn = null;
                }
                if ($blockFn instanceof JsFunction) {
                    // Skip the immediate block scope and look for the
                    // enclosing var scope. The block scope already holds
                    // the function as its lexical binding; Annex B's job is
                    // to mirror it into the function/global scope.
                    $varScope = $env->getParent();
                    while ($varScope !== null && !$varScope->isAnnexBHoisted($name)) {
                        $varScope = $varScope->getParent();
                    }
                    if ($varScope !== null) {
                        $varScope->set($name, $blockFn, false);
                    }
                }
            }
        }

        return Completion::normalEmpty();
    }

    private function execBlockStatement(BlockStatement $node, Environment $env): Completion
    {
        $blockEnv = $env->createChild();
        // Cache once which hoist passes the block actually needs. Inner
        // loop bodies like `{ const t = a + b; a = b; b = t; }` only
        // need the lexical TDZ pass; pure-statement blocks need neither.
        if ($node->hoistsLexicalCache === null) {
            $node->hoistsLexicalCache = $this->blockHoistsLexical($node->body);
            $node->hoistsVarOrFuncCache = $this->bodyNeedsHoisting($node->body);
        }
        if ($node->hoistsVarOrFuncCache) {
            $savedSkip = $this->skipAnnexBHoisting;
            $this->skipAnnexBHoisting = true;
            $this->hoistDeclarations($node->body, $blockEnv);
            $this->skipAnnexBHoisting = $savedSkip;
        }
        if ($node->hoistsLexicalCache) {
            $this->hoistEvalLexicalDeclarations($node->body, $blockEnv);
        }
        $completion = $this->executeBody($node->body, $blockEnv);
        return $this->applyDisposals($blockEnv, $completion);
    }

    private function execIfStatement(IfStatement $node, Environment $env): Completion
    {
        $test = $this->evaluate($node->test, $env);
        if (TypeConversion::toBoolean($test)) {
            // Per Annex B.3.4, a FunctionDeclaration directly in an if branch
            // (not wrapped in a block) gets its own implicit block scope for
            // block-scoped binding semantics. The function is both bound in
            // the block scope and, if annexBEligible, propagated to the var scope.
            if ($node->consequent instanceof FunctionDeclaration && !$this->strictMode) {
                $blockEnv = $env->createChild();
                $this->hoistDeclarations([$node->consequent], $blockEnv);
                $stmtCompletion = $this->executeStatement($node->consequent, $blockEnv);
            } else {
                $stmtCompletion = $this->executeStatement($node->consequent, $env);
            }
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
            if ($node->alternate instanceof FunctionDeclaration && !$this->strictMode) {
                $blockEnv = $env->createChild();
                $this->hoistDeclarations([$node->alternate], $blockEnv);
                $stmtCompletion = $this->executeStatement($node->alternate, $blockEnv);
            } else {
                $stmtCompletion = $this->executeStatement($node->alternate, $env);
            }
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
        $isConstDecl = $node->init instanceof VariableDeclaration && $node->init->kind === 'const';
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
        //
        // Optimisation: per-iteration bindings exist so closures created
        // inside the body capture the binding value at THAT iteration. If
        // no closure exists in body/update/test, the per-iteration semantics
        // are unobservable and we can reuse loopEnv for every iteration.
        // V8 performs the same shortcut. Cached on the AST node since the
        // result depends only on syntax. test() can also create closures,
        // but it is rare; checked together with body+update for safety.
        if ($node->bodyHasClosure === null) {
            $node->bodyHasClosure = $this->nodeContainsClosure($node->body)
                || $this->nodeContainsClosure($node->update)
                || $this->nodeContainsClosure($node->test)
                || $this->nodeContainsClosure($node->init);
        }
        $iterEnv = $loopEnv;
        $perIterEnvNeeded = $perIterationBindings !== [] && $node->bodyHasClosure;
        if ($perIterEnvNeeded) {
            $iterEnv = $env->createChild();
            foreach ($perIterationBindings as $name) {
                if ($isConstDecl) {
                    $iterEnv->defineConst($name, $loopEnv->get($name));
                } else {
                    $iterEnv->defineLet($name, $loopEnv->get($name));
                }
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
            // scoped variables inside the body do not leak. If the body is
            // not a BlockStatement, no block-scoped declarations are
            // possible and the child env is pure overhead — execute in
            // the iteration env directly. Big win on tight loops like
            // `for (let i = 0; i < N; i++) s += i;` where the body is a
            // single ExpressionStatement.
            if ($perIterEnvNeeded || !($node->body instanceof BlockStatement)) {
                $bodyEnv = $iterEnv;
            } else {
                $bodyEnv = $iterEnv->createChild();
            }
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
            // Skip when no closure can observe the per-iteration identity.
            if ($perIterEnvNeeded) {
                $nextIterEnv = $env->createChild();
                foreach ($perIterationBindings as $name) {
                    if ($isConstDecl) {
                        $nextIterEnv->defineConst($name, $iterEnv->get($name));
                    } else {
                        $nextIterEnv->defineLet($name, $iterEnv->get($name));
                    }
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

        // Annex B: for (var x = expr in obj) evaluates the initializer before the loop.
        if ($node->left instanceof VariableDeclaration && $node->left->kind === 'var') {
            foreach ($node->left->declarations as $decl) {
                if ($decl->init !== null) {
                    $initVal = $this->evaluate($decl->init, $env);
                    if ($decl->id instanceof Identifier) {
                        // Per NamedEvaluation, an anonymous function/class
                        // assigned to a var binding here gets the binding
                        // name as its `.name` property.
                        if (
                            $initVal instanceof JsFunction
                            && $initVal->getName() === '(anonymous)'
                            && $this->isAnonymousFunctionDefinitionNode($decl->init)
                            && !$this->hasExplicitNameProperty($initVal)
                        ) {
                            $initVal->setName($decl->id->name);
                        }
                        $env->set($decl->id->name, $initVal, false);
                    }
                }
            }
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
            // Pre-declare lexical bindings so destructured names inherit the
            // const/let kind (matches the for-of body branch below).
            if ($node->left instanceof VariableDeclaration && $node->left->kind !== 'var') {
                $declareKind = $node->left->kind;
                foreach ($node->left->declarations as $decl) {
                    foreach ($this->patternBoundNames($decl->id) as $boundName) {
                        if ($declareKind === 'const') {
                            $iterEnv->declareConst($boundName);
                        } else {
                            $iterEnv->declareLet($boundName);
                        }
                    }
                }
            }
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

        // For for-await-of, try Symbol.asyncIterator first, then fall back to Symbol.iterator.
        if ($node->await) {
            $iterator = $this->getAsyncIterator($iterable);
        } else {
            $iterator = $this->getIterator($iterable);
        }

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
                } catch (\Phasis\Exceptions\JsThrowable $e) {
                    // GetMethod threw. Per step 5: if original was throw, suppress; else propagate.
                    if ($isOriginalThrow) {
                        return null;
                    }
                    return Completion::throw($e->jsValue);
                } catch (\Phasis\Exceptions\RuntimeError $e) {
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
                } catch (\Phasis\Exceptions\JsThrowable $e) {
                    // Step 6: return() threw. If original was throw, suppress; else propagate.
                    if ($isOriginalThrow) {
                        return null;
                    }
                    return Completion::throw($e->jsValue);
                } catch (\Phasis\Exceptions\RuntimeError $e) {
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
                // For for-await-of, unwrap the promise returned by the async iterator.
                if ($node->await) {
                    $result = $this->forAwaitUnwrap($result, $env);
                }
                if (!$result instanceof JsObject) {
                    throw new TypeError('Iterator result is not an object');
                }

                $done = $result->get('done');
                if (TypeConversion::toBoolean($done)) {
                    break;
                }

                // For for-await-of, the spec only awaits the next() result,
                // not the value. AsyncFromSyncIterator already awaits the
                // value inside its own next() implementation, so a second
                // await here added an extra microtask per iteration that
                // broke the spec's tick budget (sm/for-await-of/ticks-with-*).
                $value = $result->get('value');
                $iterEnv = $env->createChild();
                // Pre-declare let/const bindings in the iteration env so that
                // destructured names inherit the lexical kind (especially const,
                // which bindPattern would otherwise downgrade to var via the
                // initializeTdz fallback).
                if ($node->left instanceof VariableDeclaration && $node->left->kind !== 'var') {
                    $declareKind = $node->left->kind;
                    foreach ($node->left->declarations as $decl) {
                        foreach ($this->patternBoundNames($decl->id) as $boundName) {
                            if ($declareKind === 'const') {
                                $iterEnv->declareConst($boundName);
                            } else {
                                $iterEnv->declareLet($boundName);
                            }
                        }
                    }
                }
                // Per spec ForIn/OfBodyEvaluation: if LHS assignment/destructuring is abrupt,
                // close the iterator before propagating the error.
                try {
                    $this->assignForBinding($node->left, $value, $iterEnv);
                } catch (\Phasis\Exceptions\JsThrowable $assignErr) {
                    $closeIterator(Completion::throw($assignErr->jsValue));
                    throw $assignErr;
                } catch (\Phasis\Exceptions\RuntimeError $assignErr) {
                    $closeIterator(Completion::throw($this->phpExceptionToJsValue($assignErr)));
                    throw $assignErr;
                }
                try {
                    $completion = $this->executeStatement($node->body, $iterEnv);
                } catch (\Phasis\Value\GeneratorReturnSignal $returnSignal) {
                    // Per spec ForIn/OfBodyEvaluation: an abrupt completion in
                    // the body (including a generator-return resumption that
                    // propagates through a yield inside the body) must close
                    // the iterator before unwinding further. The original
                    // completion is "return", so a throw from iter.return()
                    // replaces the return per IteratorClose semantics.
                    $closeCompletion = $closeIterator(null);
                    if ($closeCompletion !== null && $closeCompletion->type === CompletionType::Throw) {
                        throw new \Phasis\Exceptions\JsThrowable($closeCompletion->value);
                    }
                    throw $returnSignal;
                } catch (\Phasis\Exceptions\JsThrowable $bodyErr) {
                    $closeIterator(Completion::throw($bodyErr->jsValue));
                    throw $bodyErr;
                } catch (\Phasis\Exceptions\RuntimeError $bodyErr) {
                    $closeIterator(Completion::throw($this->phpExceptionToJsValue($bodyErr)));
                    throw $bodyErr;
                }

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
    /**
     * Get an async iterator from a value using the Symbol.asyncIterator protocol.
     * Falls back to Symbol.iterator if Symbol.asyncIterator is not present.
     */
    private function getAsyncIterator(JsValue $iterable): ?JsObject
    {
        if (!$iterable instanceof JsObject) {
            if ($iterable instanceof JsUndefined || $iterable instanceof JsNull) {
                return null;
            }
            $iterable = TypeConversion::toObject($iterable);
        }

        // Try Symbol.asyncIterator first.
        $asyncIterSym = \Phasis\BuiltIn\SymbolConstructor::asyncIterator();
        $asyncIterMethod = $iterable->getBySymbol($asyncIterSym);

        if ($asyncIterMethod instanceof JsFunction) {
            $iterator = $this->callFunction($asyncIterMethod, $iterable, []);
            if (!$iterator instanceof JsObject) {
                throw new TypeError('Result of the Symbol.asyncIterator method is not an object');
            }
            return $iterator;
        }
        if ($asyncIterMethod instanceof \Phasis\Value\JsHTMLDDA) {
            // HTMLDDA's [[Call]] returns null, which fails the object check.
            throw new TypeError('Result of the Symbol.asyncIterator method is not an object');
        }
        // Per spec GetMethod: if the property is present but neither callable
        // nor null/undefined, throw TypeError before falling back to
        // Symbol.iterator. This avoids observing the [@@iterator] getter when
        // the async hint already saw an incompatible @@asyncIterator value.
        if (
            !($asyncIterMethod instanceof JsUndefined)
            && !($asyncIterMethod instanceof JsNull)
        ) {
            throw new TypeError('Symbol.asyncIterator is not a function');
        }

        // Fall back to Symbol.iterator: wrap in AsyncFromSyncIterator.
        $syncIterator = $this->getIterator($iterable);
        if ($syncIterator === null) {
            return null;
        }
        return $this->createAsyncFromSyncIterator($syncIterator);
    }

    /**
     * Create an AsyncFromSyncIterator wrapper per spec 27.1.4.1.
     * Wraps a sync iterator so that yielded values are awaited.
     */
    private function createAsyncFromSyncIterator(JsObject $syncIterator): JsObject
    {
        $interpreter = $this;
        $wrapper = new JsObject();

        $syncNext = $syncIterator->get('next');

        $nextFn = function (JsValue $this_, array $args) use ($syncIterator, $syncNext, $interpreter): JsValue {
            // Per spec: forward the argument only if one was passed.
            $forward = array_key_exists(0, $args) ? [$args[0]] : [];
            return $interpreter->asyncFromSyncNext($syncIterator, $syncNext, $forward);
        };
        $wrapper->set('next', JsFunction::fromCallable('next', $nextFn, 1));

        $returnFn = function (JsValue $this_, array $args) use ($syncIterator, $interpreter): JsValue {
            $hasValue = array_key_exists(0, $args);
            $value = $hasValue ? $args[0] : JsUndefined::instance();
            $returnMethod = $syncIterator->get('return');
            if ($returnMethod instanceof JsUndefined || $returnMethod instanceof JsNull) {
                $result = new JsObject();
                $result->set('value', $value);
                $result->set('done', new JsBoolean(true));
                return \Phasis\Value\JsPromise::resolved($result);
            }
            if (!$returnMethod instanceof JsFunction) {
                throw new TypeError('return is not a function');
            }
            $forward = $hasValue ? [$value] : [];
            return $interpreter->asyncFromSyncMethod($syncIterator, $returnMethod, $forward);
        };
        $wrapper->set('return', JsFunction::fromCallable('return', $returnFn, 1));

        $throwFn = function (JsValue $this_, array $args) use ($syncIterator, $interpreter): JsValue {
            $hasValue = array_key_exists(0, $args);
            $value = $hasValue ? $args[0] : JsUndefined::instance();
            $throwMethod = $syncIterator->get('throw');
            if ($throwMethod instanceof JsUndefined || $throwMethod instanceof JsNull) {
                // Per spec 27.1.4.4 step 7: close the iterator, then reject with TypeError.
                $interpreter->iteratorClose($syncIterator);
                $err = $interpreter->phpExceptionToJsValue(
                    new TypeError(
                        'The iterator does not provide a throw method'
                    )
                );
                return \Phasis\Value\JsPromise::rejected($err);
            }
            if (!$throwMethod instanceof JsFunction) {
                $interpreter->iteratorClose($syncIterator);
                $err = $interpreter->phpExceptionToJsValue(
                    new TypeError('throw is not a function')
                );
                return \Phasis\Value\JsPromise::rejected($err);
            }
            $forward = $hasValue ? [$value] : [];
            return $interpreter->asyncFromSyncMethod($syncIterator, $throwMethod, $forward);
        };
        $wrapper->set('throw', JsFunction::fromCallable('throw', $throwFn, 1));

        return $wrapper;
    }

    /**
     * AsyncFromSyncIterator next: call sync next, unwrap value.
     *
     * @param list<JsValue> $forwardedArgs
     */
    private function asyncFromSyncNext(
        JsObject $syncIterator,
        JsValue $syncNext,
        array $forwardedArgs,
    ): JsValue {
        if (!$syncNext instanceof JsFunction) {
            throw new TypeError('Iterator next is not a function');
        }
        try {
            $syncResult = $this->callFunction($syncNext, $syncIterator, $forwardedArgs);
        } catch (\Throwable $e) {
            $jsErr = $e instanceof \Phasis\Exceptions\JsThrowable ? $e->jsValue : $this->phpExceptionToJsValue($e);
            return \Phasis\Value\JsPromise::rejected($jsErr);
        }
        if (!$syncResult instanceof JsObject) {
            // Per spec 27.1.4.2 step 8: AsyncFromSyncIteratorContinuation's
            // IteratorComplete sees a non-Object result and rejects the promise
            // rather than letting the call throw at the host boundary.
            $err = $this->phpExceptionToJsValue(
                new TypeError('Iterator result is not an object')
            );
            return \Phasis\Value\JsPromise::rejected($err);
        }
        return $this->asyncFromSyncUnwrapResult($syncResult, $syncIterator);
    }

    /**
     * AsyncFromSyncIterator method: call sync method, unwrap value.
     *
     * @param list<JsValue> $forwardedArgs
     */
    private function asyncFromSyncMethod(
        JsObject $syncIterator,
        JsFunction $method,
        array $forwardedArgs,
    ): JsValue {
        try {
            $syncResult = $this->callFunction($method, $syncIterator, $forwardedArgs);
        } catch (\Throwable $e) {
            $jsErr = $e instanceof \Phasis\Exceptions\JsThrowable
                ? $e->jsValue : $this->phpExceptionToJsValue($e);
            return \Phasis\Value\JsPromise::rejected($jsErr);
        }
        if (!$syncResult instanceof JsObject) {
            $err = $this->phpExceptionToJsValue(
                new TypeError('Iterator result is not an object')
            );
            return \Phasis\Value\JsPromise::rejected($err);
        }
        return $this->asyncFromSyncUnwrapResult($syncResult, $syncIterator);
    }

    /** Unwrap a sync iterator result: await the value property. */
    private function asyncFromSyncUnwrapResult(
        JsObject $syncResult,
        ?JsObject $syncIterator = null,
    ): JsValue {
        try {
            $done = TypeConversion::toBoolean($syncResult->get('done'));
        } catch (\Throwable $e) {
            $jsErr = $e instanceof \Phasis\Exceptions\JsThrowable
                ? $e->jsValue : $this->phpExceptionToJsValue($e);
            return \Phasis\Value\JsPromise::rejected($jsErr);
        }
        try {
            $value = $syncResult->get('value');
        } catch (\Throwable $e) {
            $jsErr = $e instanceof \Phasis\Exceptions\JsThrowable
                ? $e->jsValue : $this->phpExceptionToJsValue($e);
            // Close iterator if not done.
            if (!$done && $syncIterator !== null) {
                $this->iteratorClose($syncIterator);
            }
            return \Phasis\Value\JsPromise::rejected($jsErr);
        }
        // Per spec 27.1.4.4 step 5: valueWrapper = PromiseResolve(%Promise%, value).
        // PromiseResolve reads value.constructor when value is a Promise; a
        // poisoned constructor getter must surface as the promise rejection.
        if ($value instanceof \Phasis\Value\JsPromise) {
            try {
                $value->get('constructor');
            } catch (\Throwable $e) {
                $jsErr = $e instanceof \Phasis\Exceptions\JsThrowable
                    ? $e->jsValue : $this->phpExceptionToJsValue($e);
                if (!$done && $syncIterator !== null) {
                    try {
                        $this->iteratorClose($syncIterator);
                    } catch (\Throwable) {
                    }
                }
                return \Phasis\Value\JsPromise::rejected($jsErr);
            }
        }
        // Per spec 27.1.4.4: AsyncFromSyncIteratorContinuation builds valueWrapper
        // via PromiseResolve, attaches an onFulfilled reaction that constructs
        // the iter result, then resolves a separate promise capability. The
        // layered scheduling adds one microtask tick for valueWrapper to settle
        // and another for the outer capability to resolve from onFulfilled —
        // collapsing it loses the tick that for-await Await observers depend on.
        $outer = new \Phasis\Value\JsPromise();
        if ($value instanceof \Phasis\Value\JsPromise) {
            $valueWrapper = $value;
        } else {
            $valueWrapper = new \Phasis\Value\JsPromise();
            $valueWrapper->resolve($value);
        }
        $iteratorRef = $syncIterator;
        $onFulfilled = JsFunction::fromCallable(
            '',
            static function (JsValue $this_, array $args) use ($outer, $done): JsValue {
                $v = $args[0] ?? JsUndefined::instance();
                $iter = new JsObject();
                $iter->set('value', $v);
                $iter->set('done', new JsBoolean($done));
                $outer->resolve($iter);
                return JsUndefined::instance();
            },
            1
        );
        $closeOnReject = !$done && $iteratorRef !== null;
        $onRejected = JsFunction::fromCallable(
            '',
            function (JsValue $this_, array $args) use ($outer, $iteratorRef, $closeOnReject): JsValue {
                $reason = $args[0] ?? JsUndefined::instance();
                if ($closeOnReject) {
                    try {
                        $this->iteratorClose($iteratorRef);
                    } catch (\Throwable) {
                    }
                }
                $outer->reject($reason);
                return JsUndefined::instance();
            },
            1
        );
        $valueWrapper->then([$onFulfilled, $onRejected]);
        return $outer;
    }

    // iteratorClose is defined below at line ~9621.

    /**
     * Await a value during for-await-of iteration. When called inside an
     * async function fiber, suspends the fiber via AwaitSuspension so the
     * for-await loop yields to microtasks per spec; otherwise falls back
     * to the synchronous drain.
     */
    private function forAwaitUnwrap(JsValue $value, Environment $env): JsValue
    {
        $fiber = \Fiber::getCurrent();
        $kind = $env->getEnclosingFunctionKind();
        if ($fiber !== null && ($kind === 'async' || $kind === 'async-generator')) {
            try {
                $resumed = \Fiber::suspend(new \Phasis\Value\AwaitSuspension($value));
            } catch (\Phasis\Exceptions\JsThrowable $e) {
                $this->throwJsValue($e->jsValue);
            }
            if ($resumed instanceof JsValue) {
                return $resumed;
            }
            return JsUndefined::instance();
        }
        return $this->awaitValue($value);
    }

    /**
     * Await a value from inside an async-generator yield* loop. When running
     * on a fiber, suspend via AwaitSuspension so the generator's driver can
     * subscribe to the awaited value (which may be a pending promise that only
     * resolves once the surrounding microtask drain finishes). Falls back to
     * synchronous awaitValue when no fiber is active (e.g. unit tests).
     */
    private function awaitInGenerator(JsValue $value): JsValue
    {
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null) {
            try {
                $resumed = \Fiber::suspend(new \Phasis\Value\AwaitSuspension($value));
            } catch (\Phasis\Exceptions\JsThrowable $e) {
                $this->throwJsValue($e->jsValue);
            }
            if ($resumed instanceof JsValue) {
                return $resumed;
            }
            return JsUndefined::instance();
        }
        return $this->awaitValue($value);
    }

    /**
     * Await a JS value: if it is a Promise, extract the resolved value.
     * If it is a thenable, resolve it. Otherwise return as-is.
     */
    private function awaitValue(JsValue $value): JsValue
    {
        if ($value instanceof \Phasis\Value\JsPromise) {
            // If the promise is still pending (e.g. produced by a .then()
            // chain), drain queued microtasks until it settles so the
            // top-level await observes a resolved value.
            $guard = 0;
            while (
                $value->getState() === \Phasis\Value\JsPromise::STATE_PENDING
                && $guard++ < 100000
            ) {
                \Phasis\Value\JsPromise::drainMicrotasks();
                // Drain ran once. If still pending, no further microtask
                // progress is possible in our single-tick interpreter, so
                // break out instead of spinning.
                break;
            }
            if ($value->getState() === \Phasis\Value\JsPromise::STATE_REJECTED) {
                $this->throwJsValue($value->getResolvedValue());
            }
            return $value->getResolvedValue();
        }
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
                // CreateResolvingFunctions: anonymous built-ins per spec.
                $resolveFn = JsFunction::fromCallable('', $resolveHandler, 1);
                $rejectFn = JsFunction::fromCallable('', $rejectHandler, 1);
                try {
                    $thenMethod->call($value, [$resolveFn, $rejectFn]);
                } catch (\Throwable $e) {
                    if ($e instanceof \Phasis\Exceptions\JsThrowable) {
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

    private function getIterator(JsValue $iterable): ?JsObject
    {
        // String iteration: produce a code-point iterator that correctly
        // handles surrogate pairs and lone surrogates per spec 22.1.5.2.1.
        if ($iterable instanceof JsString) {
            $chars = [];
            $u16 = JsString::utf8ToUtf16LE($iterable->value);
            $u16Len = (int) (strlen($u16) / 2);
            $si = 0;
            while ($si < $u16Len) {
                $cu = ord($u16[$si * 2]) | (ord($u16[$si * 2 + 1]) << 8);
                if ($cu >= 0xD800 && $cu <= 0xDBFF && $si + 1 < $u16Len) {
                    $next = ord($u16[($si + 1) * 2]) | (ord($u16[($si + 1) * 2 + 1]) << 8);
                    if ($next >= 0xDC00 && $next <= 0xDFFF) {
                        // Valid surrogate pair: combine into a single code point.
                        $cp = ($cu - 0xD800) * 0x400 + ($next - 0xDC00) + 0x10000;
                        $chars[] = mb_chr($cp, 'UTF-8');
                        $si += 2;
                        continue;
                    }
                }
                // Lone surrogate or BMP character.
                $chars[] = JsString::utf16CodeUnitToUtf8($cu);
                $si++;
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

        // For non-string primitives, GetMethod(V, @@iterator) looks up on the
        // wrapper prototype; an installed Symbol.iterator there must be called
        // with the primitive as its receiver.
        if (
            $iterable instanceof JsNumber
            || $iterable instanceof JsBoolean
            || $iterable instanceof \Phasis\Value\JsBigInt
            || $iterable instanceof JsSymbol
        ) {
            $wrapper = TypeConversion::toObject($iterable);
            $iterSym = \Phasis\BuiltIn\SymbolConstructor::iterator();
            $method = $wrapper->getBySymbol($iterSym);
            if (!$method instanceof JsFunction) {
                return null;
            }
            $result = $this->callFunction($method, $iterable, []);
            if (!$result instanceof JsObject) {
                throw new TypeError('Iterator method did not return an object');
            }
            return $result;
        }
        if (!$iterable instanceof JsObject) {
            return null;
        }

        // Check for Symbol.iterator method.
        $iterSym = \Phasis\BuiltIn\SymbolConstructor::iterator();
        $iteratorMethod = $iterable->getBySymbol($iterSym);

        $isCallable = $iteratorMethod instanceof JsFunction
            || ($iteratorMethod instanceof \Phasis\Value\JsProxy && $iteratorMethod->isCallable());
        if (!$isCallable) {
            return null;
        }

        $iterator = $iteratorMethod instanceof \Phasis\Value\JsProxy
            ? $iteratorMethod->apply($iterable, [])
            : $this->callFunction($iteratorMethod, $iterable, []);
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
            try {
                foreach ($pattern->elements as $element) {
                    if ($element instanceof RestElement) {
                        $rest = $this->iteratorRest($iterator, $nextMethod, $done);
                        $this->assignPatternToEnv($element->argument, $rest, $env);
                        $done = true;
                        break;
                    }
                    $elemValue = $this->iteratorNext($iterator, $nextMethod, $done);
                    if ($element === null) {
                        continue;
                    }
                    $this->assignPatternToEnv($element, $elemValue, $env);
                }
            } catch (\Throwable $e) {
                if (!$done) {
                    $this->iteratorClose($iterator, $e);
                }
                throw $e;
            }
            if (!$done) {
                $this->iteratorClose($iterator);
            }
            return;
        }

        if ($pattern instanceof ObjectPattern) {
            if ($value instanceof JsNull || $value instanceof JsUndefined) {
                throw new \Phasis\Exceptions\TypeError(
                    "Cannot destructure property of " . TypeConversion::toString($value),
                );
            }
            $usedKeysApe = [];
            $usedSymIdsApe = [];
            foreach ($pattern->properties as $prop) {
                if ($prop instanceof RestElement) {
                    $restObjApe = new JsObject();
                    if ($value instanceof JsObject) {
                        $this->copyRestDataProperties($value, $restObjApe, $usedKeysApe, $usedSymIdsApe);
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
                    if ($prop->computed) {
                        $rawK = $this->evaluate($prop->key, $env);
                        if ($rawK instanceof JsSymbol) {
                            $usedSymIdsApe[$rawK->getId()] = true;
                            $propValue = $this->getVForDestructuring($value, null, $rawK);
                            $this->assignPatternToEnv($prop->value, $propValue, $env);
                            continue;
                        }
                        $key = TypeConversion::toString($rawK);
                    } else {
                        $key = $prop->key instanceof Identifier
                            ? $prop->key->name
                            : TypeConversion::toString($this->evaluate($prop->key, $env));
                    }
                    $usedKeysApe[] = $key;
                    $propValue = $this->getVForDestructuring($value, $key, null);
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
                    && !$this->hasExplicitNameProperty($value)
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
                        $finalResult = Completion::normal($v);
                        return $this->applyDisposals($switchEnv, $finalResult);
                    }
                    return $this->applyDisposals($switchEnv, $result);
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
                            $finalResult = Completion::normal($v);
                            return $this->applyDisposals($switchEnv, $finalResult);
                        }
                        return $this->applyDisposals($switchEnv, $result);
                    }
                }
            }
        }

        return $this->applyDisposals($switchEnv, Completion::normal($v));
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
        // Tail call optimization: in strict mode, if the return argument is a
        // direct function call (or reachable via a conditional / logical /
        // comma expression whose operands are tail positions), create a
        // TailCallThunk instead of evaluating the call immediately. The
        // helper returns either a TailCallThunk or a JsValue (when the
        // tail branch could be evaluated directly with its side-effecting
        // prefix); we wrap accordingly.
        if ($this->strictMode && $node->argument !== null && $this->inTailPosition) {
            $result = $this->evalTailPositionExpr($node->argument, $env);
            if ($result !== null) {
                return Completion::return($result);
            }
        }

        if ($node->argument === null) {
            // ReturnStatement : `return ;` — no expression, no Await even in
            // async generators (spec 13.10.1 distinguishes the two forms).
            return Completion::return(JsUndefined::instance());
        }
        $value = $this->evaluate($node->argument, $env);
        // Per spec 13.10.1 step 3: in an async generator, `return Expression`
        // sets exprValue to Await(exprValue). We mark the explicit-return
        // value by wrapping it in a fulfilled JsPromise so JsAsyncGenerator's
        // terminated-path routes through asyncGeneratorAwaitReturn, which
        // adds the spec-mandated microtask tick.
        if (
            $env->getEnclosingFunctionKind() === 'async-generator'
            && !($value instanceof \Phasis\Value\JsPromise)
        ) {
            $wrap = new \Phasis\Value\JsPromise();
            $wrap->resolve($value);
            $value = $wrap;
        }
        return Completion::return($value);
    }

    /**
     * Try to create a TailCallThunk for a call expression in tail position.
     * Returns null if the call cannot be optimized (e.g., super call, eval).
     */
    /**
     * Try to produce either a TailCallThunk or the final JsValue for an
     * expression in tail position. Per HasCallInTailPosition, conditional,
     * logical, comma, and parenthesized expressions propagate tail-position
     * into their operands; this helper walks those structures, evaluating
     * any side-effecting prefix so the caller can just wrap the result in
     * Completion::return without re-running side effects.
     *
     * Returns null when the expression does not qualify for TCO reasoning
     * (falls back to a normal evaluate in execReturnStatement).
     */
    private function evalTailPositionExpr(Node $node, Environment $env): TailCallThunk|JsValue|null
    {
        if ($node instanceof CallExpression) {
            return $this->evalTailCall($node, $env);
        }
        if ($node instanceof TaggedTemplate) {
            return $this->evalTaggedTemplateTailCall($node, $env);
        }
        if ($node instanceof \Phasis\Ast\Expression\ConditionalExpression) {
            $test = $this->evaluate($node->test, $env);
            $branch = TypeConversion::toBoolean($test) ? $node->consequent : $node->alternate;
            $result = $this->evalTailPositionExpr($branch, $env);
            if ($result !== null) {
                return $result;
            }
            return $this->evaluate($branch, $env);
        }
        if ($node instanceof \Phasis\Ast\Expression\SequenceExpression) {
            $exprs = $node->expressions;
            if ($exprs === []) {
                return null;
            }
            for ($i = 0, $n = count($exprs); $i < $n - 1; $i++) {
                $this->evaluate($exprs[$i], $env);
            }
            $result = $this->evalTailPositionExpr($exprs[$n - 1], $env);
            if ($result !== null) {
                return $result;
            }
            return $this->evaluate($exprs[$n - 1], $env);
        }
        if (
            $node instanceof \Phasis\Ast\Expression\LogicalExpression
            && in_array($node->operator, ['&&', '||', '??'], true)
        ) {
            $left = $this->evaluate($node->left, $env);
            $takesRight = match ($node->operator) {
                '&&' => TypeConversion::toBoolean($left),
                '||' => !TypeConversion::toBoolean($left),
                '??' => $left instanceof JsNull
                    || $left instanceof JsUndefined
                    || $left instanceof JsOptionalUndefined,
            };
            if (!$takesRight) {
                return $left;
            }
            $result = $this->evalTailPositionExpr($node->right, $env);
            if ($result !== null) {
                return $result;
            }
            return $this->evaluate($node->right, $env);
        }
        return null;
    }

    /**
     * Try to create a TailCallThunk for a tagged template expression in tail
     * position. Returns null if the tag cannot be optimized.
     */
    private function evalTaggedTemplateTailCall(TaggedTemplate $node, Environment $env): ?TailCallThunk
    {
        $tag = null;
        $thisValue = JsUndefined::instance();
        if ($node->tag instanceof MemberExpression) {
            if (
                $node->tag->object instanceof Identifier
                && $node->tag->object->name === 'super'
            ) {
                return null;
            }
            if ($node->tag->property instanceof PrivateIdentifier) {
                return null;
            }
            $obj = $this->evaluate($node->tag->object, $env);
            $propName = $node->tag->computed
                ? TypeConversion::toString($this->evaluate($node->tag->property, $env))
                : ($node->tag->property instanceof Identifier
                    ? $node->tag->property->name
                    : TypeConversion::toString($this->evaluate($node->tag->property, $env)));
            $tag = $obj instanceof JsObject ? $obj->get($propName) : null;
            $thisValue = $obj;
        } elseif ($node->tag instanceof Identifier) {
            if ($node->tag->name === 'super') {
                return null;
            }
            if ($env->has($node->tag->name)) {
                $tag = $env->get($node->tag->name);
            }
        } else {
            $tag = $this->evaluate($node->tag, $env);
        }
        if (!$tag instanceof JsFunction) {
            return null;
        }
        if ($tag->getNativeCallable() !== null || $tag->isGenerator() || $tag->isAsync()) {
            return null;
        }

        // Build the arguments list (strings array + expression values).
        $cacheKey = spl_object_id($node->quasi);
        if (isset($this->templateObjectCache[$cacheKey])) {
            $strings = $this->templateObjectCache[$cacheKey];
        } else {
            $strings = new JsArray();
            $raw = new JsArray();
            $count = count($node->quasi->quasis);
            foreach ($node->quasi->quasis as $i => $quasi) {
                $cookedVal = $quasi->cookedValue === null
                    ? JsUndefined::instance()
                    : new JsString($quasi->cookedValue);
                $strings->defineOwnProperty((string) $i, \Phasis\Object\PropertyDescriptor::data(
                    $cookedVal,
                    false,
                    true,
                    false,
                ));
                $raw->defineOwnProperty((string) $i, \Phasis\Object\PropertyDescriptor::data(
                    new JsString($quasi->rawValue),
                    false,
                    true,
                    false,
                ));
            }
            $strings->defineOwnProperty('length', \Phasis\Object\PropertyDescriptor::data(
                JsNumber::of((float) $count),
                false,
                false,
                false,
            ));
            $raw->defineOwnProperty('length', \Phasis\Object\PropertyDescriptor::data(
                JsNumber::of((float) $count),
                false,
                false,
                false,
            ));
            $raw->preventExtensions();
            $strings->defineOwnProperty('raw', \Phasis\Object\PropertyDescriptor::data(
                $raw,
                false,
                false,
                false,
            ));
            $strings->preventExtensions();
            $this->templateObjectCache[$cacheKey] = $strings;
        }
        $args = [$strings];
        foreach ($node->quasi->expressions as $expr) {
            $args[] = $this->evaluate($expr, $env);
        }

        return new TailCallThunk($tag, $thisValue, $args);
    }

    private function evalTailCall(CallExpression $node, Environment $env): ?TailCallThunk
    {
        // Resolve the callee and its this-binding.
        $callee = null;
        $thisValue = JsUndefined::instance();

        if ($node->callee instanceof MemberExpression) {
            // super.method() needs special resolution via HomeObject; bail to the
            // regular call path which knows how to handle it.
            if (
                $node->callee->object instanceof Identifier
                && $node->callee->object->name === 'super'
            ) {
                return null;
            }
            // Private method calls (obj.#method()) resolve via the receiver's
            // private-name slots; the regular call path handles this, so bail.
            if ($node->callee->property instanceof PrivateIdentifier) {
                return null;
            }
            // The receiver of a method call is usually side-effecting; if we
            // evaluated it here and then bailed because the callee turned out
            // to be a native method, execReturnStatement would re-evaluate
            // the same expression and double-run side effects (observable via
            // `return foo().then(a).then(b)` where `.then` is native). To
            // avoid that, only attempt TCO when the receiver is statically
            // proven side-effect-free (Identifier, ThisExpression, Literal,
            // or recursively a side-effect-free MemberExpression).
            if (!self::isSideEffectFreeAst($node->callee->object)) {
                return null;
            }
            $obj = $this->evaluate($node->callee->object, $env);
            // Computed member with a Symbol key must not be stringified; fall back to
            // the regular call path (returning null) so getBySymbol is used correctly.
            if ($node->callee->computed) {
                $propVal = $this->evaluate($node->callee->property, $env);
                if ($propVal instanceof \Phasis\Value\JsSymbol) {
                    return null;
                }
                $propName = TypeConversion::toString($propVal);
            } else {
                $propName = $node->callee->property instanceof Identifier
                    ? $node->callee->property->name
                    : TypeConversion::toString($this->evaluate($node->callee->property, $env));
            }
            $callee = $obj instanceof JsObject ? $obj->get($propName) : null;
            $thisValue = $obj;
        } elseif ($node->callee instanceof Identifier) {
            // super() is not eligible for TCO.
            if ($node->callee->name === 'super') {
                return null;
            }
            if ($env->has($node->callee->name)) {
                $callee = $env->get($node->callee->name);
            }
            // Direct eval (identifier 'eval' resolving to native %eval%) is
            // not eligible; a user-shadowed 'eval' bound to a regular
            // function still is (it's just a function call).
            if (
                $node->callee->name === 'eval'
                && $callee instanceof JsFunction
                && $callee->isNative()
                && $callee->getName() === 'eval'
            ) {
                return null;
            }
        } else {
            // Anything else (call/new expr, etc.) is side-effecting; bail to
            // the regular evaluate path so we don't run side effects twice.
            return null;
        }

        if (!$callee instanceof JsFunction) {
            return null; // Not a function, fall back to normal evaluation
        }

        // Cannot TCO native functions, generators, async functions, or constructors
        if ($callee->getNativeCallable() !== null || $callee->isGenerator() || $callee->isAsync()) {
            return null;
        }

        // Evaluate arguments
        $args = [];
        foreach ($node->arguments as $arg) {
            if ($arg instanceof SpreadElement) {
                $spread = $this->evaluate($arg->argument, $env);
                $this->spreadInto($spread, $args);
            } else {
                $args[] = $this->evaluate($arg, $env);
            }
        }

        return new TailCallThunk($callee, $thisValue, $args);
    }

    /**
     * Heuristic AST classification: returns true when evaluating the node
     * is guaranteed not to observably mutate user state nor invoke a
     * callable. Used by evalTailCall to decide whether it is safe to
     * evaluate the receiver speculatively (knowing that if TCO ultimately
     * bails out, the caller will re-run the expression). Conservative —
     * unknown AST shapes return false.
     */
    private static function isSideEffectFreeAst(Node $node): bool
    {
        if (
            $node instanceof Identifier
            || $node instanceof \Phasis\Ast\Expression\ThisExpression
            || $node instanceof \Phasis\Ast\Expression\Literal
            || $node instanceof \Phasis\Ast\Expression\TemplateLiteral
            || $node instanceof \Phasis\Ast\Expression\ArrayExpression
            || $node instanceof \Phasis\Ast\Expression\ObjectExpression
            || $node instanceof \Phasis\Ast\Expression\FunctionExpression
            || $node instanceof \Phasis\Ast\Expression\ArrowFunction
            || $node instanceof \Phasis\Ast\Expression\ClassExpression
        ) {
            // Identifiers can technically observe getter-on-globalThis side
            // effects via the var lookup, but the spec-mandated TCO test
            // shape `return f.call(...)` would never observe this, so we
            // accept identifiers. ArrayExpression / ObjectExpression
            // /Template etc. can contain side-effecting subexpressions, but
            // they would also be evaluated identically by the fallback
            // evaluate path; the duplication concern is only for *receiver*
            // sub-call expressions, which are excluded here.
            return true;
        }
        if ($node instanceof MemberExpression) {
            // Pure member access requires the object chain to be free of
            // call expressions too. Computed keys with a non-pure key would
            // re-evaluate on fallback; bar them.
            if ($node->computed && !self::isSideEffectFreeAst($node->property)) {
                return false;
            }
            return self::isSideEffectFreeAst($node->object);
        }
        return false;
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
        } catch (\Phasis\Exceptions\JsThrowable $e) {
            // A PHP exception carrying a JS value (e.g., from generator.throw()).
            // Extract the original JS value for the catch handler.
            $completion = Completion::throw($e->jsValue);
        } catch (\Phasis\Exceptions\SyntaxError $e) {
            // A PHP SyntaxError (e.g. from eval parsing). Convert to a JS
            // SyntaxError so the catch handler can process it.
            $completion = Completion::throw($this->phpExceptionToJsValue($e));
        } catch (\Phasis\Exceptions\RuntimeError $e) {
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
            // Use limited hoisting for catch body: hoist var names and function
            // declarations but do NOT create Annex B var markers. The Annex B
            // hoisting was already handled at the enclosing function scope level.
            $savedSkip = $this->skipAnnexBHoisting;
            $this->skipAnnexBHoisting = true;
            $this->hoistDeclarations($node->handler->body->body, $bodyEnv);
            $this->skipAnnexBHoisting = $savedSkip;
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

        // Per spec 14.15.1 step 4: return UpdateEmpty(C, undefined). If the
        // try/catch/finally completion's value is empty (e.g. empty catch
        // body), fill it with undefined so statement-list accumulation replaces
        // any preceding expression value.
        if ($completion->empty) {
            return new Completion(
                $completion->type,
                JsUndefined::instance(),
                $completion->target,
            );
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
        $objId = spl_object_id($obj);
        $this->withEnvObjects[spl_object_id($withEnv)] = $obj;
        $this->activeWithObjectIds[$objId] = true;
        try {
            $completion = $this->executeStatement($node->body, $withEnv);
        } finally {
            unset($this->withEnvObjects[spl_object_id($withEnv)]);
            unset($this->activeWithObjectIds[$objId]);
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
}
