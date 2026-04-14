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
        $errorTypes = ['Error', 'TypeError', 'RangeError', 'ReferenceError', 'SyntaxError', 'URIError', 'EvalError'];

        foreach ($errorTypes as $name) {
            $constructor = JsFunction::fromCallable($name, self::makeConstructor($name), 1);
            $proto = new JsObject();
            $proto->defineOwnProperty('name', PropertyDescriptor::data(new JsString($name), true, false, true));
            $proto->defineOwnProperty('message', PropertyDescriptor::data(new JsString(''), true, false, true));
            $proto->defineOwnProperty('constructor', PropertyDescriptor::data($constructor, true, false, true));
            $proto->defineOwnProperty('toString', PropertyDescriptor::data(JsFunction::fromCallable(
                'toString',
                function (JsValue $this_) use ($name): JsValue {
                    if ($this_ instanceof JsObject) {
                        $n = $this_->has('name') ? TypeConversion::toString($this_->get('name')) : $name;
                        $m = $this_->has('message') ? TypeConversion::toString($this_->get('message')) : '';
                        return new JsString($m !== '' ? "{$n}: {$m}" : $n);
                    }
                    return new JsString($name);
                },
                0,
            ), true, false, true));
            $constructor->set('prototype', $proto);
            $env->defineVar($name, $constructor);
        }
    }

    private static function makeConstructor(string $name): \Closure
    {
        return function (JsValue $this_, array $args) use ($name): JsValue {
            $message = isset($args[0]) && !$args[0] instanceof JsUndefined
                ? TypeConversion::toString($args[0])
                : '';

            if ($this_ instanceof JsObject) {
                $this_->set('name', new JsString($name));
                $this_->set('message', new JsString($message));
                $this_->set('stack', new JsString("{$name}: {$message}"));
                return $this_;
            }

            $obj = new JsObject();
            $obj->set('name', new JsString($name));
            $obj->set('message', new JsString($message));
            $obj->set('stack', new JsString("{$name}: {$message}"));
            return $obj;
        };
    }
}
