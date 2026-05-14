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

/**
 * Interpreter part: DisposalSupport. Composed into Interpreter via
 * `use Parts\DisposalSupport;`. `self::`/`$this->` references resolve
 * into the composing class.
 */
trait DisposalSupport
{
    // -------------------------------------------------------------------------
    // Disposal support (explicit resource management)
    // -------------------------------------------------------------------------

    /** Register a disposable resource on the given environment. */
    private function registerDisposable(JsValue $value, bool $isAsync, Environment $env): void
    {
        // Per spec AddDisposableResource: when sync-dispose, null/undefined
        // are accepted and the binding is non-disposable. When async-dispose,
        // null/undefined are also accepted (covered below).
        if ($value instanceof JsNull || $value instanceof JsUndefined) {
            if ($isAsync) {
                // For async-dispose with null/undefined, also accept and skip.
                return;
            }
            return;
        }
        if (!$value instanceof JsObject) {
            throw new TypeError('Using declaration initializer is not an Object');
        }
        if ($isAsync) {
            $asyncMethod = $value->getBySymbol(\Phasis\BuiltIn\SymbolConstructor::asyncDispose());
            $syncMethod = $value->getBySymbol(\Phasis\BuiltIn\SymbolConstructor::dispose());
            if (
                ($asyncMethod instanceof JsUndefined || $asyncMethod instanceof JsNull)
                && ($syncMethod instanceof JsUndefined || $syncMethod instanceof JsNull)
            ) {
                throw new TypeError('The value does not have a dispose method.');
            }
        } else {
            $method = $value->getBySymbol(\Phasis\BuiltIn\SymbolConstructor::dispose());
            if ($method instanceof JsUndefined || $method instanceof JsNull) {
                throw new TypeError('The value does not have a Symbol.dispose method.');
            }
            if (!$method instanceof JsFunction) {
                throw new TypeError('Property [Symbol.dispose] is not a function.');
            }
        }
        $env->addDisposable($value, $isAsync);
    }

    /** Run disposals for the given environment in reverse order. */
    private function runDisposals(Environment $env, ?JsValue $pendingError = null): Completion
    {
        $disposables = $env->getDisposables();
        if (empty($disposables) && $pendingError === null) {
            return Completion::normal(JsUndefined::instance());
        }
        $error = $pendingError;
        for ($i = count($disposables) - 1; $i >= 0; $i--) {
            [$resource, $isAsync] = $disposables[$i];
            try {
                if ($isAsync) {
                    $method = $resource->getBySymbol(\Phasis\BuiltIn\SymbolConstructor::asyncDispose());
                    if ($method instanceof JsUndefined || $method instanceof JsNull) {
                        $method = $resource->getBySymbol(\Phasis\BuiltIn\SymbolConstructor::dispose());
                    }
                } else {
                    $method = $resource->getBySymbol(\Phasis\BuiltIn\SymbolConstructor::dispose());
                }
                if ($method instanceof JsFunction) {
                    $result = $method->call($resource, []);
                    if ($isAsync) {
                        // Per spec, an async dispose always Awaits the result,
                        // even when it is a non-promise. If we are running
                        // inside an async function Fiber, suspend per dispose
                        // so each Await yields a microtask tick to the caller.
                        $fiber = \Fiber::getCurrent();
                        if ($fiber !== null && $env->getEnclosingFunctionKind() === 'async') {
                            try {
                                \Fiber::suspend(new \Phasis\Value\AwaitSuspension($result));
                            } catch (\Phasis\Exceptions\JsThrowable $e) {
                                throw $e;
                            }
                        } elseif ($result instanceof \Phasis\Value\JsPromise) {
                            \Phasis\Value\JsPromise::drainMicrotasks();
                            // Propagate a rejected dispose promise as a thrown
                            // value so it gets chained into a SuppressedError
                            // if an outer error is already pending, or surfaces
                            // as the error otherwise.
                            if ($result->getState() === \Phasis\Value\JsPromise::STATE_REJECTED) {
                                throw new \Phasis\Exceptions\JsThrowable($result->getResolvedValue());
                            }
                        }
                    }
                } else {
                    throw new TypeError('Property [Symbol.dispose] is not a function.');
                }
            } catch (\Throwable $e) {
                $newError = $this->phpExceptionToJsValue($e);
                if ($error !== null) {
                    $error = $this->createSuppressedError($newError, $error);
                } else {
                    $error = $newError;
                }
            }
        }
        if ($error !== null) {
            return Completion::throw($error);
        }
        return Completion::normal(JsUndefined::instance());
    }

    /** Convert a PHP exception into a JS value for SuppressedError chaining. */
    public function phpExceptionToJsValue(\Throwable $e): JsValue
    {
        if ($e instanceof \Phasis\Exceptions\JsThrowable) {
            return $e->jsValue;
        }
        $ctorName = match (true) {
            $e instanceof TypeError => 'TypeError',
            $e instanceof \Phasis\Exceptions\RangeError => 'RangeError',
            $e instanceof \Phasis\Exceptions\ReferenceError => 'ReferenceError',
            $e instanceof \Phasis\Exceptions\SyntaxError => 'SyntaxError',
            default => 'Error',
        };
        try {
            $ctor = $this->globalEnv->get($ctorName);
        } catch (\Throwable) {
            $ctor = null;
        }
        if ($ctor instanceof JsFunction) {
            $obj = new JsObject();
            $obj->set('[[NewTarget]]', $ctor);
            $proto = $ctor->get('prototype');
            if ($proto instanceof JsObject) {
                $obj->setPrototype($proto);
            }
            $result = $ctor->call($obj, [new JsString($e->getMessage())]);
            if ($result instanceof JsObject) {
                return $result;
            }
        }
        $errObj = new JsObject();
        $errObj->set('message', new JsString($e->getMessage()));
        $errObj->set('name', new JsString($ctorName));
        $errObj->defineOwnProperty(
            '[[ErrorData]]',
            \Phasis\Object\PropertyDescriptor::data(JsUndefined::instance(), false, false, false),
        );
        return $errObj;
    }

    /** Create a SuppressedError(error, suppressed). */
    private function createSuppressedError(JsValue $error, JsValue $suppressed): JsObject
    {
        try {
            $ctor = $this->globalEnv->get('SuppressedError');
        } catch (\Throwable) {
            $ctor = null;
        }
        if ($ctor instanceof JsFunction) {
            $obj = new JsObject();
            $obj->set('[[NewTarget]]', $ctor);
            $proto = $ctor->get('prototype');
            if ($proto instanceof JsObject) {
                $obj->setPrototype($proto);
            }
            $result = $ctor->call($obj, [$error, $suppressed]);
            if ($result instanceof JsObject) {
                return $result;
            }
        }
        $obj = new JsObject();
        $obj->set('error', $error);
        $obj->set('suppressed', $suppressed);
        $obj->set('name', new JsString('SuppressedError'));
        $obj->set('message', new JsString(''));
        return $obj;
    }

    /** Apply disposals for an environment and return the merged completion. */
    private function applyDisposals(Environment $env, Completion $completion): Completion
    {
        if (!$env->hasDisposables()) {
            return $completion;
        }
        $pendingError = $completion->type === CompletionType::Throw ? $completion->value : null;
        $dr = $this->runDisposals($env, $pendingError);
        if ($dr->type === CompletionType::Throw) {
            return $dr;
        }
        return $completion;
    }
}
