<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class ArrayConstructor
{
    public static function install(Environment $env): void
    {
        $constructor = JsFunction::fromCallable('Array', function (JsValue $this_, array $args): JsValue {
            if (count($args) === 1 && $args[0] instanceof JsNumber) {
                $len = (int) $args[0]->value;
                $arr = new JsArray();
                $arr->setLength($len);
                return $arr;
            }
            return JsArray::fromArray($args);
        });

        // Static methods.
        $constructor->set('isArray', JsFunction::fromCallable('isArray', self::isArray()));
        $constructor->set('from', JsFunction::fromCallable('from', self::from()));
        $constructor->set('of', JsFunction::fromCallable('of', self::of()));

        // Array.prototype — a JsArray with standard methods accessible via prototype chain
        $proto = new JsArray();
        // Install commonly accessed methods on the prototype
        $proto->set('constructor', $constructor);
        $proto->set('join', JsFunction::fromCallable('join', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsArray) {
                return new JsString('');
            }
            $sep = isset($args[0]) && !$args[0] instanceof JsUndefined
                ? TypeConversion::toString($args[0]) : ',';
            $parts = [];
            for ($i = 0; $i < $this_->getLength(); $i++) {
                $v = $this_->get((string) $i);
                $parts[] = ($v instanceof JsUndefined || $v instanceof \PhpJs\Value\JsNull)
                    ? '' : TypeConversion::toString($v);
            }
            return new JsString(implode($sep, $parts));
        }));
        $constructor->set('prototype', $proto);

        $env->defineVar('Array', $constructor);
    }

    private static function isArray(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $arg = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($arg instanceof JsArray);
        };
    }

    private static function from(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $arrayLike = $args[0] ?? JsUndefined::instance();
            $mapFn = ($args[1] ?? null) instanceof JsFunction ? $args[1] : null;

            // Handle JsArray.
            if ($arrayLike instanceof JsArray) {
                $items = [];
                $len = $arrayLike->getLength();
                for ($i = 0; $i < $len; $i++) {
                    $val = $arrayLike->get((string) $i);
                    if ($mapFn !== null) {
                        $val = $mapFn->call(JsUndefined::instance(), [$val, new JsNumber((float) $i)]);
                    }
                    $items[] = $val;
                }
                return JsArray::fromArray($items);
            }

            // Handle JsString: split into characters.
            if ($arrayLike instanceof JsString) {
                $items = [];
                $str = $arrayLike->value;
                $len = mb_strlen($str, 'UTF-8');
                for ($i = 0; $i < $len; $i++) {
                    $char = mb_substr($str, $i, 1, 'UTF-8');
                    $val = new JsString($char);
                    if ($mapFn !== null) {
                        $val = $mapFn->call(JsUndefined::instance(), [$val, new JsNumber((float) $i)]);
                    }
                    $items[] = $val;
                }
                return JsArray::fromArray($items);
            }

            // Handle array-like objects with a length property.
            if ($arrayLike instanceof JsObject) {
                $lenVal = $arrayLike->get('length');
                $len = (int) TypeConversion::toNumber($lenVal);
                $items = [];
                for ($i = 0; $i < $len; $i++) {
                    $val = $arrayLike->get((string) $i);
                    if ($mapFn !== null) {
                        $val = $mapFn->call(JsUndefined::instance(), [$val, new JsNumber((float) $i)]);
                    }
                    $items[] = $val;
                }
                return JsArray::fromArray($items);
            }

            return new JsArray();
        };
    }

    private static function of(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            return JsArray::fromArray($args);
        };
    }
}
