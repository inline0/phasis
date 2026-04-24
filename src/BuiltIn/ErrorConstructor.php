<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;
use PhpJs\Object\PropertyDescriptor;

class ErrorConstructor
{
    public static function install(Environment $env): void
    {
        $errorTypes = [
            'Error', 'TypeError', 'RangeError', 'ReferenceError',
            'SyntaxError', 'URIError', 'EvalError', 'AggregateError',
        ];

        $errorProto = null;
        $errorConstructor = null;

        foreach ($errorTypes as $name) {
            $proto = new JsObject();
            // name and message are non-enumerable, writable, configurable
            $proto->defineOwnProperty('name', PropertyDescriptor::data(new JsString($name), true, false, true));
            $proto->defineOwnProperty('message', PropertyDescriptor::data(new JsString(''), true, false, true));

            // Error.prototype.toString per spec §19.5.3.4
            $proto->defineOwnProperty('toString', PropertyDescriptor::data(JsFunction::fromCallable(
                'toString',
                function (JsValue $this_): JsValue {
                    if (!$this_ instanceof JsObject) {
                        throw new \PhpJs\Exceptions\TypeError(
                            'Error.prototype.toString requires that \'this\' be an Object',
                        );
                    }
                    // Step 3: Let name be Get(O, "name"). If undefined, use "Error".
                    $n = $this_->get('name');
                    $nameStr = ($n instanceof JsUndefined) ? 'Error' : TypeConversion::toString($n);
                    // Step 5: Let msg be Get(O, "message"). If undefined, use "".
                    $m = $this_->get('message');
                    $msgStr = ($m instanceof JsUndefined) ? '' : TypeConversion::toString($m);
                    // Step 7-9
                    if ($nameStr === '') {
                        return new JsString($msgStr);
                    }
                    if ($msgStr === '') {
                        return new JsString($nameStr);
                    }
                    return new JsString("{$nameStr}: {$msgStr}");
                },
                0,
            ), true, false, true));

            if ($name === 'AggregateError') {
                $constructor = JsFunction::fromCallable(
                    $name,
                    self::makeAggregateErrorConstructor($name, $proto),
                    2,
                );
            } else {
                $constructor = JsFunction::fromCallable($name, self::makeConstructor($name, $proto), 1);
            }
            $constructor->setConstructable();

            // constructor <-> prototype wiring
            $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));
            // Error.prototype is non-writable, non-configurable per spec
            $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));

            // For Error subtypes, set prototype chain:
            // - SubError.prototype inherits from Error.prototype
            // - SubError constructor inherits from Error constructor
            if ($name !== 'Error' && $errorProto !== null) {
                $proto->setPrototype($errorProto);
                if ($errorConstructor !== null) {
                    $constructor->setPrototype($errorConstructor);
                }
            }

            if ($name === 'Error') {
                $errorProto = $proto;
                $errorConstructor = $constructor;

                // Error.isError(arg) — TC39 Stage 3 proposal
                $isErrorFn = JsFunction::fromCallable('isError', function (JsValue $this_, array $args): JsValue {
                    $arg = $args[0] ?? JsUndefined::instance();
                    if (!$arg instanceof JsObject) {
                        return new \PhpJs\Value\JsBoolean(false);
                    }
                    // Per spec, check for [[ErrorData]] internal slot.
                    // We mark real error objects with this slot during construction.
                    return new \PhpJs\Value\JsBoolean(
                        $arg->hasOwnProperty('[[ErrorData]]')
                    );
                }, 1);
                $constructor->defineOwnProperty('isError', PropertyDescriptor::data($isErrorFn, true, false, true));

                // Note: Error.prototype does NOT have Symbol.toStringTag per spec.
                // Object.prototype.toString.call(Error.prototype) returns "[object Object]".
            }

            $env->defineVar($name, $constructor);
        }

        // SuppressedError: SuppressedError(error, suppressed [, message])
        // Per spec: has .error, .suppressed, .message properties.
        if ($errorProto !== null) {
            $suppressedProto = new JsObject();
            $suppressedProto->defineOwnProperty(
                'name',
                PropertyDescriptor::data(new JsString('SuppressedError'), true, false, true),
            );
            $suppressedProto->defineOwnProperty(
                'message',
                PropertyDescriptor::data(new JsString(''), true, false, true),
            );
            $suppressedProto->setPrototype($errorProto);

            $suppressedProto->defineOwnProperty('toString', PropertyDescriptor::data(JsFunction::fromCallable(
                'toString',
                function (JsValue $this_): JsValue {
                    if (!$this_ instanceof JsObject) {
                        throw new \PhpJs\Exceptions\TypeError(
                            'Error.prototype.toString requires that \'this\' be an Object',
                        );
                    }
                    $n = $this_->get('name');
                    $nameStr = ($n instanceof JsUndefined) ? 'Error' : TypeConversion::toString($n);
                    $m = $this_->get('message');
                    $msgStr = ($m instanceof JsUndefined) ? '' : TypeConversion::toString($m);
                    if ($nameStr === '') {
                        return new JsString($msgStr);
                    }
                    if ($msgStr === '') {
                        return new JsString($nameStr);
                    }
                    return new JsString("{$nameStr}: {$msgStr}");
                },
                0,
            ), true, false, true));

            $suppressedCtor = JsFunction::fromCallable(
                'SuppressedError',
                self::makeSuppressedErrorConstructor($suppressedProto),
                3,
            );
            $suppressedCtor->setConstructable();
            $suppressedProto->defineOwnProperty(
                'constructor',
                PropertyDescriptor::data($suppressedCtor, true, false, true),
            );
            $suppressedCtor->defineOwnProperty(
                'prototype',
                PropertyDescriptor::data($suppressedProto, false, false, false),
            );
            if ($errorConstructor !== null) {
                $suppressedCtor->setPrototype($errorConstructor);
            }
            $env->defineVar('SuppressedError', $suppressedCtor);
        }
    }

    /**
     * AggregateError constructor per spec sec-aggregate-error.
     *
     * Signature: AggregateError(errors, message [, options])
     * 1. Process message (ToString if not undefined).
     * 2. Iterate errors (IterableToList).
     * 3. Install cause from options.
     */
    private static function makeAggregateErrorConstructor(string $name, JsObject $proto): \Closure
    {
        return function (JsValue $this_, array $args) use ($name, $proto): JsValue {
            $errorsArg = $args[0] ?? JsUndefined::instance();
            $messageArg = $args[1] ?? JsUndefined::instance();
            $options = $args[2] ?? JsUndefined::instance();

            // Determine target object.
            if ($this_ instanceof JsObject && $this_->has('[[NewTarget]]')) {
                $obj = $this_;
                // Per §9.1.13 OrdinaryCreateFromConstructor, set the prototype
                // from newTarget.prototype (fall back to intrinsicDefaultProto).
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : $proto;
                    $obj->setPrototype($useProto);
                }
            } else {
                $obj = new JsObject($proto);
            }

            // Mark as real error with [[ErrorData]] internal slot.
            $obj->defineOwnProperty(
                '[[ErrorData]]',
                PropertyDescriptor::data(JsUndefined::instance(), false, false, false),
            );

            // Step 5: If message is not undefined, let msg = ToString(message),
            // then CreateMethodProperty(O, "message", msg).
            // Per spec, this happens BEFORE iterating errors.
            if (!$messageArg instanceof JsUndefined) {
                $msgStr = TypeConversion::toString($messageArg);
                $obj->defineOwnProperty('message', PropertyDescriptor::data(
                    new JsString($msgStr),
                    true,
                    false,
                    true,
                ));
            }

            // Step 3: Let errorsList be IterableToList(errors).
            $errorsList = self::iterableToList($errorsArg);

            // Step 4: Set O.[[AggregateErrors]] to errorsList.
            // The errors property is a frozen array stored as own "errors" property.
            $errorsArray = JsArray::fromArray($errorsList);
            $obj->defineOwnProperty('errors', PropertyDescriptor::data(
                $errorsArray,
                true,
                false,
                true,
            ));

            // Stack trace.
            $message = $obj->has('message') ? TypeConversion::toString($obj->get('message')) : '';
            $obj->defineOwnProperty('stack', PropertyDescriptor::data(
                new JsString("{$name}: {$message}"),
                true,
                false,
                true,
            ));

            // Step 4: Perform ? InstallErrorCause(O, options).
            if ($options instanceof JsObject && $options->has('cause')) {
                $obj->defineOwnProperty('cause', PropertyDescriptor::data(
                    $options->get('cause'),
                    true,
                    false,
                    true,
                ));
            }

            return $obj;
        };
    }

    /**
     * IterableToList per spec 7.4.9.
     *
     * Iterates the given value and returns a list of values.
     * Throws TypeError if the value is not iterable.
     *
     * @return list<JsValue>
     */
    private static function iterableToList(JsValue $items): array
    {
        if ($items instanceof JsUndefined || $items instanceof JsNull) {
            throw new \PhpJs\Exceptions\TypeError(
                'undefined is not iterable (cannot read property Symbol(Symbol.iterator))'
            );
        }

        if (!$items instanceof JsObject) {
            throw new \PhpJs\Exceptions\TypeError(
                TypeConversion::toString($items) . ' is not iterable'
            );
        }

        // Get the iterator method.
        $iterSym = SymbolConstructor::iterator();
        $iteratorMethod = $items->getBySymbol($iterSym);
        if ($iteratorMethod instanceof JsUndefined || $iteratorMethod instanceof JsNull) {
            throw new \PhpJs\Exceptions\TypeError(
                'object is not iterable (cannot read property Symbol(Symbol.iterator))'
            );
        }
        if (!$iteratorMethod instanceof JsFunction) {
            throw new \PhpJs\Exceptions\TypeError(
                'Result of the Symbol.iterator method is not an object'
            );
        }

        // Call the iterator method.
        $iterator = $iteratorMethod->call($items, []);
        if (!$iterator instanceof JsObject) {
            throw new \PhpJs\Exceptions\TypeError(
                'Result of the Symbol.iterator method is not an object'
            );
        }

        // Get the next method.
        $nextMethod = $iterator->get('next');
        if (!$nextMethod instanceof JsFunction) {
            throw new \PhpJs\Exceptions\TypeError(
                'iterator.next is not a function'
            );
        }

        $values = [];
        while (true) {
            $result = $nextMethod->call($iterator, []);
            if (!$result instanceof JsObject) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Iterator result is not an object'
                );
            }
            if (TypeConversion::toBoolean($result->get('done'))) {
                break;
            }
            $values[] = $result->get('value');
        }

        return $values;
    }

    private static function makeConstructor(string $name, JsObject $proto): \Closure
    {
        return function (JsValue $this_, array $args) use ($name, $proto): JsValue {
            $message = isset($args[0]) && !$args[0] instanceof JsUndefined
                ? TypeConversion::toString($args[0])
                : null;
            $options = $args[1] ?? JsUndefined::instance();

            if ($this_ instanceof JsObject && $this_->has('[[NewTarget]]')) {
                // Called via new: populate the already-created object.
                // Do NOT override the prototype: evalNewExpression already sets
                // the correct prototype based on new.target's .prototype property.
                // This ensures subclasses (class Err extends Error {}) keep their prototype chain.
                // Mark as real error with [[ErrorData]] internal slot.
                $this_->defineOwnProperty(
                    '[[ErrorData]]',
                    PropertyDescriptor::data(
                        \PhpJs\Value\JsUndefined::instance(),
                        false,
                        false,
                        false,
                    ),
                );
                if ($message !== null) {
                    $this_->defineOwnProperty('message', PropertyDescriptor::data(
                        new JsString($message),
                        true,
                        false,
                        true,
                    ));
                }
                $this_->defineOwnProperty('stack', PropertyDescriptor::data(
                    new JsString("{$name}: " . ($message ?? '')),
                    true,
                    false,
                    true,
                ));
                // ES2022: Error cause property from options bag
                if ($options instanceof JsObject && $options->has('cause')) {
                    $this_->defineOwnProperty('cause', PropertyDescriptor::data(
                        $options->get('cause'),
                        true,
                        false,
                        true,
                    ));
                }
                return $this_;
            }

            // Called as a function: create a new object with the correct prototype.
            $obj = new JsObject($proto);
            $obj->defineOwnProperty(
                '[[ErrorData]]',
                PropertyDescriptor::data(
                    \PhpJs\Value\JsUndefined::instance(),
                    false,
                    false,
                    false,
                ),
            );
            if ($message !== null) {
                $obj->defineOwnProperty('message', PropertyDescriptor::data(
                    new JsString($message),
                    true,
                    false,
                    true,
                ));
            }
            $obj->defineOwnProperty('stack', PropertyDescriptor::data(
                new JsString("{$name}: " . ($message ?? '')),
                true,
                false,
                true,
            ));
            if ($options instanceof JsObject && $options->has('cause')) {
                $obj->defineOwnProperty('cause', PropertyDescriptor::data(
                    $options->get('cause'),
                    true,
                    false,
                    true,
                ));
            }
            return $obj;
        };
    }

    /**
     * SuppressedError constructor per spec.
     *
     * Signature: SuppressedError(error, suppressed [, message])
     */
    private static function makeSuppressedErrorConstructor(JsObject $proto): \Closure
    {
        return function (JsValue $this_, array $args) use ($proto): JsValue {
            $errorArg = $args[0] ?? JsUndefined::instance();
            $suppressedArg = $args[1] ?? JsUndefined::instance();
            $messageArg = $args[2] ?? JsUndefined::instance();

            if ($this_ instanceof JsObject && $this_->has('[[NewTarget]]')) {
                $obj = $this_;
                // Per §9.1.13 OrdinaryCreateFromConstructor, set prototype
                // from newTarget.prototype.
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsObject) {
                    $ntProto = $newTarget->get('prototype');
                    $useProto = $ntProto instanceof JsObject ? $ntProto : $proto;
                    $obj->setPrototype($useProto);
                }
            } else {
                $obj = new JsObject($proto);
            }

            $obj->defineOwnProperty(
                '[[ErrorData]]',
                PropertyDescriptor::data(JsUndefined::instance(), false, false, false),
            );

            if (!$messageArg instanceof JsUndefined) {
                $msgStr = TypeConversion::toString($messageArg);
                $obj->defineOwnProperty('message', PropertyDescriptor::data(
                    new JsString($msgStr),
                    true,
                    false,
                    true,
                ));
            }

            $obj->defineOwnProperty('error', PropertyDescriptor::data(
                $errorArg,
                true,
                false,
                true,
            ));

            $obj->defineOwnProperty('suppressed', PropertyDescriptor::data(
                $suppressedArg,
                true,
                false,
                true,
            ));

            $message = $obj->has('message') ? TypeConversion::toString($obj->get('message')) : '';
            $obj->defineOwnProperty('stack', PropertyDescriptor::data(
                new JsString("SuppressedError: {$message}"),
                true,
                false,
                true,
            ));

            return $obj;
        };
    }
}
