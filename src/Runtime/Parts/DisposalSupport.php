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
        $resource = \Phasis\Runtime\DisposableResource::create($value, $isAsync);
        if ($resource !== null) {
            $env->addDisposable($resource);
        }
    }

    /** Run captured disposal methods in reverse order, preserving abrupt completions. */
    private function runDisposals(Environment $env, ?JsValue $pendingError = null): Completion
    {
        $disposables = $env->takeDisposables();
        $error = $pendingError;
        $needsAwait = false;
        $hasAwaited = false;
        $await = static function (JsValue $result): void {
            if (\Fiber::getCurrent() !== null) {
                \Fiber::suspend(new \Phasis\Value\AwaitSuspension($result));
                return;
            }
            $promise = $result instanceof \Phasis\Value\JsPromise
                ? $result : \Phasis\Value\JsPromise::resolved($result);
            \Phasis\Value\JsPromise::drainMicrotasks();
            if ($promise->getState() === \Phasis\Value\JsPromise::STATE_REJECTED) {
                throw new \Phasis\Exceptions\JsThrowable($promise->getResolvedValue());
            }
        };
        foreach (array_reverse($disposables) as $resource) {
            if (!$resource->async && $needsAwait && !$hasAwaited) {
                $await(JsUndefined::instance());
                $needsAwait = false;
            }
            if ($resource->method === null) {
                $needsAwait = true;
                continue;
            }
            try {
                $result = $resource->method->call($resource->value, []);
                if ($resource->async) {
                    $hasAwaited = true;
                    $await($result);
                }
            } catch (\Throwable $exception) {
                $newError = $this->phpExceptionToJsValue($exception);
                $error = $error === null ? $newError : $this->createSuppressedError($newError, $error);
            }
        }
        if ($needsAwait && !$hasAwaited) {
            $await(JsUndefined::instance());
        }
        return $error !== null ? Completion::throw($error) : Completion::normal(JsUndefined::instance());
    }

    /** Preserve the executing function's realm when creating an abrupt completion. */
    private function activeRealmExceptionToJsValue(\Throwable $error): JsValue
    {
        $interpreter = \Phasis\Engine::getCurrentInterpreter() ?? $this;
        return $interpreter->phpExceptionToJsValue($error);
    }

    /** Convert a PHP exception into a JS value for SuppressedError chaining. */
    public function phpExceptionToJsValue(\Throwable $e): JsValue
    {
        if ($e instanceof \Phasis\Exceptions\JsThrowable) {
            return $e->jsValue;
        }
        $ctorName = match (true) {
            $e instanceof TypeError || $e instanceof \TypeError => 'TypeError',
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

    /**
     * Evaluate a scope body and dispose its resources on both returned and thrown completions.
     * @param list<Node> $body
     */
    private function executeDisposableBody(array $body, Environment $env): Completion
    {
        try {
            $completion = $this->executeBody($body, $env);
        } catch (\Phasis\Exceptions\JsThrowable | \Phasis\Exceptions\RuntimeError $error) {
            $completion = Completion::throw($this->activeRealmExceptionToJsValue($error));
        }
        return $this->applyDisposals($env, $completion);
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
