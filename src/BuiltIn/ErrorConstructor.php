<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsFunction;
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

            $constructor = JsFunction::fromCallable($name, self::makeConstructor($name, $proto), 1);
            $constructor->setConstructable();

            // constructor <-> prototype wiring
            $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));
            // Error.prototype is non-writable, non-configurable per spec
            $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));

            // For Error subtypes, set prototype to inherit from Error.prototype
            if ($name !== 'Error' && $errorProto !== null) {
                $proto->setPrototype($errorProto);
            }

            if ($name === 'Error') {
                $errorProto = $proto;

                // Error.isError(arg) — TC39 Stage 3 proposal
                $isErrorFn = JsFunction::fromCallable('isError', function (JsValue $this_, array $args): JsValue {
                    $arg = $args[0] ?? JsUndefined::instance();
                    if (!$arg instanceof JsObject) {
                        return new \PhpJs\Value\JsBoolean(false);
                    }
                    // Check if the object has an 'Error' or known error type as its name on prototype
                    $name = $arg->get('name');
                    if ($name instanceof JsString) {
                        $nameStr = $name->value;
                        $errorNames = ['Error', 'TypeError', 'RangeError', 'ReferenceError',
                            'SyntaxError', 'URIError', 'EvalError', 'AggregateError'];
                        if (in_array($nameStr, $errorNames, true)) {
                            // Also verify it has a message property (to distinguish from random objects)
                            if ($arg->has('message') || $arg->has('stack')) {
                                return new \PhpJs\Value\JsBoolean(true);
                            }
                        }
                    }
                    return new \PhpJs\Value\JsBoolean(false);
                }, 1);
                $constructor->defineOwnProperty('isError', PropertyDescriptor::data($isErrorFn, true, false, true));

                // Note: Error.prototype does NOT have Symbol.toStringTag per spec.
                // Object.prototype.toString.call(Error.prototype) returns "[object Object]".
            }

            $env->defineVar($name, $constructor);
        }
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
                $this_->setPrototype($proto);
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
}
