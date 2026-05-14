<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Array_;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
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
 * ArrayConstructor trait part: ArrayStatics. Composed into
 * ArrayConstructor via `use Array_\ArrayStatics;`. `self::`/`$this->`
 * resolve into the composing class.
 */
trait ArrayStatics
{
    private static function isArray(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $arg = $args[0] ?? JsUndefined::instance();
            return new JsBoolean(self::isArrayValue($arg));
        };
    }

    /**
     * Per spec 7.2.2 IsArray: unwrap proxy targets recursively.
     */
    private static function isArrayValue(JsValue $arg): bool
    {
        if ($arg instanceof JsArray) {
            return true;
        }
        if ($arg instanceof \Phasis\Value\JsProxy) {
            if ($arg->isRevoked()) {
                throw new TypeError('Cannot perform \'IsArray\' on a proxy that has been revoked');
            }
            return self::isArrayValue($arg->getTarget());
        }
        return false;
    }

    /**
     * Construct an object using a constructor function, mimicking `new C(args)`.
     *
     * Falls back to %Object.prototype% from the constructor's realm when
     * `ctor.prototype` is not an Object (per GetPrototypeFromConstructor
     * step 4: GetFunctionRealm → realm's intrinsic).
     *
     * @param array<mixed> $args
     */
    private static function constructWith(JsFunction $ctor, array $args): JsObject
    {
        $proto = \Phasis\Spec\AbstractOperations::getPrototypeFromConstructor(
            $ctor,
            static fn ($env) => \Phasis\Spec\AbstractOperations::realmIntrinsicPrototype($env, 'Object'),
        );
        $newObj = new JsObject($proto);
        $newObj->defineOwnProperty('[[NewTarget]]', PropertyDescriptor::data($ctor, false, false, false));
        $result = $ctor->call($newObj, $args);
        return $result instanceof JsObject ? $result : $newObj;
    }

    private static function from(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $c = $this_; // The constructor (this) for Array.from.call(C, items)
            $arrayLike = $args[0] ?? JsUndefined::instance();

            // Null/undefined throw TypeError per spec.
            if ($arrayLike instanceof JsNull || $arrayLike instanceof JsUndefined) {
                throw new TypeError('Array.from called on null or undefined');
            }

            // Validate mapFn if provided.
            $mapFnRaw = $args[1] ?? JsUndefined::instance();
            $mapFn = null;
            if (!$mapFnRaw instanceof JsUndefined) {
                if (!$mapFnRaw instanceof JsFunction) {
                    throw new TypeError('Array.from: when provided, the second argument must be a function');
                }
                $mapFn = $mapFnRaw;
            }
            $mapThisArg = $args[2] ?? JsUndefined::instance();

            // Determine if C is a constructor.
            $isConstructor = ($c instanceof JsFunction && $c->isConstructable());

            // Check for Symbol.iterator first (iterables take precedence over array-like).
            // Per spec GetV, primitives like Number look up Symbol.iterator on their
            // wrapper prototype, so a prototype-installed iterator is observable.
            // Strings go through the same path so deleting String.prototype[@@iterator]
            // makes Array.from fall back to the array-like (UTF-16 code unit) path.
            if (
                $arrayLike instanceof JsObject
                || $arrayLike instanceof JsString
                || $arrayLike instanceof JsNumber
                || $arrayLike instanceof JsBoolean
                || $arrayLike instanceof \Phasis\Value\JsBigInt
                || $arrayLike instanceof JsSymbol
            ) {
                $iterSym = SymbolConstructor::iterator();
                $iteratorMethod = null;

                if ($arrayLike instanceof JsObject) {
                    $iteratorMethod = $arrayLike->getBySymbol($iterSym);
                } else {
                    // Primitive (String/Number/Boolean/BigInt/Symbol): GetV
                    // does ToObject for the lookup but keeps the original
                    // primitive as the receiver so a getter on the prototype
                    // observes the unboxed `this` value.
                    $wrapper = TypeConversion::toObject($arrayLike);
                    $iteratorMethod = $wrapper->getBySymbolWithReceiver($iterSym, $arrayLike);
                }

                $iterMethodCallable = $iteratorMethod instanceof JsFunction
                    || $iteratorMethod instanceof \Phasis\Value\JsHTMLDDA;
                // Per spec GetMethod: if V is not undefined/null and not
                // callable, throw TypeError. Primitives like a string or
                // number set as @@iterator must reject Array.from instead
                // of silently falling through to the array-like path.
                if (
                    !$iterMethodCallable
                    && !($iteratorMethod instanceof JsUndefined)
                    && !($iteratorMethod instanceof JsNull)
                ) {
                    throw new TypeError('Array.from: Symbol.iterator is not a function');
                }
                if ($iterMethodCallable) {
                    // Create the result object.
                    if ($isConstructor) {
                        /** @var JsFunction $c */
                        $a = self::constructWith($c, []);
                    } else {
                        $a = new JsArray();
                    }
                    $index = 0;

                    // Use the iterator protocol. HTMLDDA's [[Call]] returns null,
                    // which fails the "iterator is not an object" check below.
                    if ($iteratorMethod instanceof \Phasis\Value\JsHTMLDDA) {
                        $iterator = JsNull::instance();
                    } else {
                        /** @var JsFunction $iteratorMethod */
                        $iterator = $iteratorMethod->call($arrayLike, []);
                    }
                    if (!$iterator instanceof JsObject) {
                        throw new TypeError('Array.from: iterator is not an object');
                    }
                    while (true) {
                        $nextMethod = $iterator->get('next');
                        if (!$nextMethod instanceof JsFunction) {
                            break;
                        }
                        $result = $nextMethod->call($iterator, []);
                        if (!$result instanceof JsObject) {
                            throw new TypeError('Array.from: iterator result is not an object');
                        }
                        $done = TypeConversion::toBoolean($result->get('done'));
                        if ($done) {
                            break;
                        }
                        $val = $result->get('value');
                        if ($mapFn !== null) {
                            try {
                                $val = $mapFn->call(
                                    $mapThisArg,
                                    [$val, JsNumber::of((float) $index)]
                                );
                            } catch (\Throwable $mapErr) {
                                // Per spec: IteratorClose(iterator, mappedValue).
                                // Suppress GetMethod / call exceptions so the
                                // original mapfn error always propagates.
                                try {
                                    $returnMethod = $iterator->get('return');
                                } catch (\Throwable) {
                                    $returnMethod = null;
                                }
                                if ($returnMethod instanceof JsFunction) {
                                    try {
                                        $returnMethod->call($iterator, []);
                                    } catch (\Throwable) {
                                    }
                                }
                                throw $mapErr;
                            }
                        }
                        // CreateDataPropertyOrThrow per spec. Both
                        // false-returns and thrown exceptions must trigger
                        // IteratorClose so the source generator can run its
                        // `return()` cleanup hook (sm/Array/from-iterator-close).
                        // Per spec 7.4.7 IteratorClose, when there is already
                        // a pending throw, the GetMethod and Call results
                        // from the close path are SUPPRESSED so the original
                        // exception always propagates.
                        $closeIterator = static function () use ($iterator): void {
                            try {
                                $returnMethod = $iterator->get('return');
                            } catch (\Throwable) {
                                return;
                            }
                            if ($returnMethod instanceof JsFunction) {
                                try {
                                    $returnMethod->call($iterator, []);
                                } catch (\Throwable) {
                                }
                            }
                        };
                        try {
                            $success = $a->defineOwnProperty(
                                (string) $index,
                                PropertyDescriptor::data($val, true, true, true),
                            );
                        } catch (\Throwable $defineErr) {
                            $closeIterator();
                            throw $defineErr;
                        }
                        if (!$success) {
                            $closeIterator();
                            throw new TypeError(
                                'Cannot define property ' . $index . ' on result object'
                            );
                        }
                        $index++;
                    }

                    // Per spec §23.1.2.1 step 7.h: Set(A, "length", index, true).
                    // Strict-mode set surfaces TypeError when the result has
                    // a read-only or non-extensible length.
                    if ($a instanceof JsArray) {
                        $a->setLength($index);
                    } else {
                        $a->set('length', JsNumber::of((float) $index), true);
                    }
                    return $a;
                }
            }

            // Fall back to array-like handling (length property). Spec step 7
            // wraps the input via ToObject; for a primitive string this gives
            // a String exotic that exposes length and per-UTF-16-code-unit
            // index properties, so deleting String.prototype[@@iterator] makes
            // Array.from(string) split surrogate pairs as expected.
            $arrayLikeObj = ($arrayLike instanceof JsObject)
                ? $arrayLike
                : TypeConversion::toObject($arrayLike);
            $lenVal = $arrayLikeObj->get('length');
            // Spec step 5.b uses LengthOfArrayLike → ToLength, which clamps
            // Infinity/large values to 2^53 - 1 instead of truncating to 0.
            $len = TypeConversion::toLength($lenVal);

            // Create result: use constructor if available.
            if ($isConstructor) {
                /** @var JsFunction $c */
                $a = self::constructWith($c, [JsNumber::of((float) $len)]);
            } else {
                // ArrayCreate(len): length must fit in a canonical array index.
                if ($len > 4294967295) {
                    throw new \Phasis\Exceptions\RangeError('Invalid array length');
                }
                $a = new JsArray();
            }

            for ($i = 0; $i < $len; $i++) {
                $val = $arrayLikeObj->get((string) $i);
                if ($mapFn !== null) {
                    $val = $mapFn->call($mapThisArg, [$val, JsNumber::of((float) $i)]);
                }
                // CreateDataPropertyOrThrow per spec.
                $success = $a->defineOwnProperty(
                    (string) $i,
                    PropertyDescriptor::data($val, true, true, true),
                );
                if (!$success) {
                    throw new TypeError(
                        'Cannot define property ' . $i . ' on result object'
                    );
                }
            }

            // Spec §23.1.2.1 step 8.f.ii: Set(A, "length", len, true). Strict
            // failure surfaces TypeError for inextensible / read-only-length
            // result objects.
            if ($a instanceof JsArray) {
                $a->setLength($len);
            } else {
                $a->set('length', JsNumber::of((float) $len), true);
            }
            return $a;
        };
    }

    /**
     * Array.fromAsync(asyncItems, mapFn?, thisArg?).
     *
     * Per spec, returns a Promise that resolves to an Array.
     * Since phasis is synchronous, thenables and promises are resolved eagerly.
     */
    private static function fromAsync(Environment $env): \Closure
    {
        return function (JsValue $this_, array $args) use ($env): JsValue {
            $c = $this_;
            $asyncItems = $args[0] ?? JsUndefined::instance();
            $mapFnRaw = $args[1] ?? JsUndefined::instance();
            $thisArg = $args[2] ?? JsUndefined::instance();

            // Step 3: validate mapFn early. Per spec the mapfn check happens
            // synchronously, but the rejected Promise itself must be returned
            // asynchronously — never throw synchronously to the caller.
            $mapFn = null;
            if (!$mapFnRaw instanceof JsUndefined) {
                if (!$mapFnRaw instanceof JsFunction) {
                    $promise = new \Phasis\Value\JsPromise();
                    $promise->reject(
                        self::buildErrorObject(
                            $env,
                            new \Phasis\Exceptions\TypeError('mapfn is not a function'),
                        )
                    );
                    return $promise;
                }
                $mapFn = $mapFnRaw;
            }

            $promise = new \Phasis\Value\JsPromise();
            try {
                $isConstructor = ($c instanceof JsFunction && $c->isConstructable());

                // Per spec: check Symbol.asyncIterator first, then Symbol.iterator.
                $usingAsyncIterator = null;
                $usingSyncIterator = null;
                if ($asyncItems instanceof JsObject) {
                    $asyncIterSym = SymbolConstructor::asyncIterator();
                    $asyncIterMethod = $asyncItems->getBySymbol($asyncIterSym);
                    if ($asyncIterMethod instanceof JsFunction) {
                        $usingAsyncIterator = $asyncIterMethod;
                    } elseif (
                        !$asyncIterMethod instanceof JsUndefined
                        && !$asyncIterMethod instanceof JsNull
                    ) {
                        throw new TypeError('object is not iterable');
                    }
                }
                if ($usingAsyncIterator === null) {
                    if ($asyncItems instanceof JsObject || $asyncItems instanceof JsString) {
                        $iterSym = SymbolConstructor::iterator();
                        if ($asyncItems instanceof JsObject) {
                            $syncIterMethod = $asyncItems->getBySymbol($iterSym);
                            if ($syncIterMethod instanceof JsFunction) {
                                $usingSyncIterator = $syncIterMethod;
                            } elseif (
                                !$syncIterMethod instanceof JsUndefined
                                && !$syncIterMethod instanceof JsNull
                            ) {
                                throw new TypeError('object is not iterable');
                            }
                        }
                        if ($usingSyncIterator === null && $asyncItems instanceof JsString) {
                            $usingSyncIterator = true;
                        }
                    }
                }

                if ($usingAsyncIterator !== null || $usingSyncIterator !== null) {
                    if ($isConstructor) {
                        /** @var JsFunction $c */
                        $a = self::constructWith($c, []);
                    } else {
                        $a = new JsArray();
                    }
                    $index = 0;

                    if ($usingSyncIterator === true && $asyncItems instanceof JsString) {
                        $str = $asyncItems->value;
                        $len = mb_strlen($str, 'UTF-8');
                        for ($i = 0; $i < $len; $i++) {
                            $val = new JsString(mb_substr($str, $i, 1, 'UTF-8'));
                            $val = self::awaitValue($val);
                            if ($mapFn !== null) {
                                $val = $mapFn->call($thisArg, [$val, JsNumber::of((float) $index)]);
                                $val = self::awaitValue($val);
                            }
                            $a->defineOwnProperty(
                                (string) $index,
                                PropertyDescriptor::data($val, true, true, true),
                            );
                            $index++;
                        }
                    } else {
                        $iteratorMethod = $usingAsyncIterator ?? $usingSyncIterator;
                        $isAsyncIter = $usingAsyncIterator !== null;
                        /** @var JsFunction $iteratorMethod */
                        $iterator = $iteratorMethod->call($asyncItems, []);
                        if (!$iterator instanceof JsObject) {
                            throw new TypeError('Result of the Symbol.iterator method is not an object');
                        }
                        // Iteration runs as a microtask-driven loop so external
                        // code (e.g., items.push) between the synchronous call
                        // to fromAsync and Await is observed by subsequent reads
                        // of the iterator. The first IteratorStep IS synchronous
                        // (per spec the Await happens AFTER calling next), but
                        // each subsequent step is scheduled via the microtask
                        // queue to give the spec-mandated tick gap.
                        $idxRef = $index;
                        // Anonymous class holder for the recursive $step
                        // closure: PHPStan can track ?\Closure as a typed
                        // property where it cannot infer that a by-reference
                        // local is reassigned before the closure runs.
                        $stepHolder = new class {
                            public ?\Closure $step = null;
                        };
                        $finish = function () use ($promise, $a, &$idxRef): void {
                            $a->set('length', JsNumber::of((float) $idxRef), true);
                            if ($a instanceof JsArray) {
                                $a->setLength($idxRef);
                            }
                            $promise->resolve($a);
                        };
                        $closeIterator = function () use ($iterator): void {
                            $returnMethod = $iterator->get('return');
                            if ($returnMethod instanceof JsFunction) {
                                try {
                                    $returnMethod->call($iterator, []);
                                } catch (\Throwable) {
                                }
                            }
                        };
                        $rejectErr = function (\Throwable $e) use ($promise, $env): void {
                            if ($e instanceof \Phasis\Exceptions\JsThrowable) {
                                $promise->reject($e->jsValue);
                            } else {
                                $promise->reject(self::buildErrorObject($env, $e));
                            }
                        };
                        // After-the-result body: process value, define array
                        // entry, schedule next step.
                        $afterResult = function (JsValue $resultVal) use (
                            $stepHolder,
                            $a,
                            $mapFn,
                            $thisArg,
                            $isAsyncIter,
                            &$idxRef,
                            $finish,
                            $closeIterator,
                            $rejectErr
                        ): void {
                            try {
                                if (!$resultVal instanceof JsObject) {
                                    throw new TypeError('Iterator result is not an object');
                                }
                                $done = TypeConversion::toBoolean($resultVal->get('done'));
                                if ($done) {
                                    $finish();
                                    return;
                                }
                                $val = $resultVal->get('value');
                                if (!$isAsyncIter) {
                                    try {
                                        $val = self::awaitValue($val);
                                    } catch (\Throwable $awaitErr) {
                                        $closeIterator();
                                        throw $awaitErr;
                                    }
                                }
                                if ($mapFn !== null) {
                                    try {
                                        $val = $mapFn->call(
                                            $thisArg,
                                            [$val, JsNumber::of((float) $idxRef)],
                                        );
                                        $val = self::awaitValue($val);
                                    } catch (\Throwable $mapErr) {
                                        $closeIterator();
                                        throw $mapErr;
                                    }
                                }
                                $success = $a->defineOwnProperty(
                                    (string) $idxRef,
                                    PropertyDescriptor::data($val, true, true, true),
                                );
                                if (!$success) {
                                    $closeIterator();
                                    throw new TypeError(
                                        'Cannot define property ' . $idxRef . ' on result object'
                                    );
                                }
                                $idxRef++;
                                // Schedule next iteration as a microtask so
                                // synchronous code after fromAsync's return
                                // can mutate the source between iterations.
                                if ($stepHolder->step !== null) {
                                    \Phasis\Value\JsPromise::scheduleCallback($stepHolder->step);
                                }
                            } catch (\Throwable $e) {
                                $rejectErr($e);
                            }
                        };
                        $stepHolder->step = function () use (
                            $iterator,
                            $afterResult,
                            $rejectErr
                        ): void {
                            try {
                                $nextMethod = $iterator->get('next');
                                if (!$nextMethod instanceof JsFunction) {
                                    throw new TypeError('Iterator next is not a function');
                                }
                                $stepResult = $nextMethod->call($iterator, []);
                                // If the iterator returned a Promise, subscribe
                                // via .then so we don't synchronously block on
                                // awaitValue (which would no-op when already
                                // inside drainMicrotasks). For non-promise
                                // results, deliver directly.
                                if ($stepResult instanceof \Phasis\Value\JsPromise) {
                                    $resolverFn = JsFunction::fromCallable(
                                        '',
                                        function (JsValue $t, array $args) use ($afterResult): JsValue {
                                            $afterResult($args[0] ?? JsUndefined::instance());
                                            return JsUndefined::instance();
                                        },
                                        1,
                                    );
                                    $rejectFn = JsFunction::fromCallable(
                                        '',
                                        function (JsValue $t, array $args) use ($rejectErr): JsValue {
                                            $reason = $args[0] ?? JsUndefined::instance();
                                            $rejectErr(new \Phasis\Exceptions\JsThrowable($reason));
                                            return JsUndefined::instance();
                                        },
                                        1,
                                    );
                                    $stepResult->then([$resolverFn, $rejectFn]);
                                } else {
                                    $afterResult($stepResult);
                                }
                            } catch (\Throwable $e) {
                                $rejectErr($e);
                            }
                        };
                        // Run the first iteration synchronously so the first
                        // element is observably read before fromAsync returns.
                        ($stepHolder->step)();
                        return $promise;
                    }
                    // Per spec the final length set is `Set(A, "length", k, true)`
                    // (throw on failure), so use the strict-mode variant.
                    $a->set('length', JsNumber::of((float) $index), true);
                    if ($a instanceof JsArray) {
                        $a->setLength($index);
                    }
                    $promise->resolve($a);
                    return $promise;
                }

                // Non-iterable: array-like path.
                if ($asyncItems instanceof JsNull || $asyncItems instanceof JsUndefined) {
                    throw new TypeError('Cannot read properties of '
                        . ($asyncItems instanceof JsNull ? 'null' : 'undefined'));
                }

                $arrayLike = ($asyncItems instanceof JsObject)
                    ? $asyncItems
                    : TypeConversion::toObject($asyncItems);

                $lenVal = $arrayLike->get('length');
                $lenNum = TypeConversion::toLength($lenVal);

                if ($isConstructor) {
                    /** @var JsFunction $c */
                    $a = self::constructWith($c, [JsNumber::of((float) $lenNum)]);
                } else {
                    // Per spec, the non-constructor path is `A = ? ArrayCreate(len)`.
                    // ArrayCreate throws RangeError for len > 2^32 - 1.
                    if ($lenNum > 0xFFFFFFFF) {
                        throw new \Phasis\Exceptions\RangeError('Invalid array length');
                    }
                    $a = new JsArray();
                }

                for ($i = 0; $i < $lenNum; $i++) {
                    $val = $arrayLike->get((string) $i);
                    $val = self::awaitValue($val);
                    if ($mapFn !== null) {
                        $val = $mapFn->call($thisArg, [$val, JsNumber::of((float) $i)]);
                        $val = self::awaitValue($val);
                    }
                    $a->defineOwnProperty(
                        (string) $i,
                        PropertyDescriptor::data($val, true, true, true),
                    );
                }
                $a->set('length', JsNumber::of((float) $lenNum), true);
                if ($a instanceof JsArray) {
                    $a->setLength($lenNum);
                }
                $promise->resolve($a);
            } catch (\Throwable $e) {
                if ($e instanceof \Phasis\Exceptions\JsThrowable) {
                    $promise->reject($e->jsValue);
                } else {
                    $promise->reject(self::buildErrorObject($env, $e));
                }
            }
            return $promise;
        };
    }

    /**
     * Convert a PHP exception to a JS Error subclass instance using the live
     * constructors registered on the global environment, so the resulting
     * value is `instanceof TypeError` (etc.) when checked from JS.
     */
    private static function buildErrorObject(Environment $env, \Throwable $e): JsValue
    {
        $ctorName = match (true) {
            $e instanceof \Phasis\Exceptions\TypeError => 'TypeError',
            $e instanceof \Phasis\Exceptions\RangeError => 'RangeError',
            $e instanceof \Phasis\Exceptions\ReferenceError => 'ReferenceError',
            $e instanceof \Phasis\Exceptions\SyntaxError => 'SyntaxError',
            default => 'Error',
        };
        try {
            $ctor = $env->get($ctorName);
        } catch (\Throwable) {
            $ctor = null;
        }
        if ($ctor instanceof JsFunction && $ctor->isConstructable()) {
            try {
                return $ctor->construct([new JsString($e->getMessage())]);
            } catch (\Throwable) {
                // fall through to plain object
            }
        }
        return self::createTypeErrorObject($e->getMessage());
    }

    /**
     * Synchronously resolve a value that may be a Promise or thenable.
     * Since phasis is synchronous, Promises are eagerly resolved.
     */
    private static function awaitValue(JsValue $value): JsValue
    {
        // Per spec Await: PromiseResolve(value) follows promise/thenable
        // chains until a non-thenable resolution. Iterate so a promise
        // that resolves to a thenable runs the thenable's then() too.
        $iterations = 0;
        while ($iterations++ < 32) {
            if ($value instanceof \Phasis\Value\JsPromise) {
                // A promise that's still pending may have a thenable
                // resolution scheduled as a microtask (per spec
                // PromiseResolveThenableJob). Drain microtasks so the
                // thenable's then() runs before we read the value.
                if ($value->getState() === \Phasis\Value\JsPromise::STATE_PENDING) {
                    \Phasis\Value\JsPromise::drainMicrotasks();
                }
                if ($value->getState() === \Phasis\Value\JsPromise::STATE_REJECTED) {
                    $reason = $value->getResolvedValue();
                    throw new \Phasis\Exceptions\JsThrowable($reason);
                }
                $next = $value->getResolvedValue();
                if ($next === $value) {
                    return $next;
                }
                $value = $next;
                continue;
            }
            if ($value instanceof JsObject) {
                $thenMethod = $value->get('then');
                if ($thenMethod instanceof JsFunction) {
                    $resolved = JsUndefined::instance();
                    $rejected = null;
                    $resolveHandler = JsFunction::fromCallable(
                        '',
                        function (JsValue $this_, array $args) use (&$resolved): JsValue {
                            $resolved = $args[0] ?? JsUndefined::instance();
                            return JsUndefined::instance();
                        },
                    );
                    $rejectHandler = JsFunction::fromCallable(
                        '',
                        function (JsValue $this_, array $args) use (&$rejected): JsValue {
                            $rejected = $args[0] ?? JsUndefined::instance();
                            return JsUndefined::instance();
                        },
                    );
                    $thenMethod->call($value, [$resolveHandler, $rejectHandler]);
                    if ($rejected !== null) {
                        throw new \Phasis\Exceptions\JsThrowable($rejected);
                    }
                    if ($resolved === $value) {
                        return $resolved;
                    }
                    $value = $resolved;
                    continue;
                }
            }
            return $value;
        }
        return $value;
    }

    private static function of(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $len = count($args);
            $isConstructor = ($this_ instanceof JsFunction && $this_->isConstructable());
            if ($isConstructor) {
                /** @var JsFunction $this_ */
                $a = self::constructWith($this_, [JsNumber::of((float) $len)]);
            } else {
                $a = new JsArray();
            }
            for ($k = 0; $k < $len; $k++) {
                // Per §23.1.2.2 Array.of, CreateDataPropertyOrThrow is used,
                // which throws TypeError if [[DefineOwnProperty]] returns
                // false (e.g. on a non-extensible or non-configurable target).
                $ok = $a->defineOwnProperty(
                    (string) $k,
                    PropertyDescriptor::data($args[$k], true, true, true),
                );
                if (!$ok) {
                    throw new \Phasis\Exceptions\TypeError(
                        "Cannot define property '{$k}' on target",
                    );
                }
            }
            if ($a instanceof JsArray) {
                $a->setLength($len);
            } else {
                $a->set('length', JsNumber::of((float) $len));
            }
            return $a;
        };
    }
}
