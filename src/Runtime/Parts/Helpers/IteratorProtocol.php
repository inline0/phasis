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
 * Interpreter helper part: IteratorProtocol. Composed back into the
 * Interpreter via the InterpreterHelpers trait. `self::`/`$this->`
 * resolve into the composing class.
 */
trait IteratorProtocol
{
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
}
