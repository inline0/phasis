<?php

declare(strict_types=1);

namespace PhpJs\Interop;

use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

final class PhpToJs
{
    public static function convert(mixed $value): JsValue
    {
        if ($value instanceof JsValue) {
            return $value;
        }

        if ($value === null) {
            return JsNull::instance();
        }

        if (is_bool($value)) {
            return new JsBoolean($value);
        }

        if (is_int($value) || is_float($value)) {
            return new JsNumber((float) $value);
        }

        if (is_string($value)) {
            return new JsString($value);
        }

        if (is_callable($value)) {
            return JsFunction::fromCallable('(native)', function (JsValue $this_, array $args) use ($value): JsValue {
                $phpArgs = array_map(fn(JsValue $v) => self::toPhp($v), $args);
                $result = $value(...$phpArgs);
                return self::convert($result);
            });
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $jsArr = new JsArray();
                foreach ($value as $i => $item) {
                    $jsArr->set((string) $i, self::convert($item));
                }
                $jsArr->set('length', new JsNumber((float) count($value)));
                return $jsArr;
            }

            $jsObj = new JsObject();
            foreach ($value as $key => $item) {
                $jsObj->set((string) $key, self::convert($item));
            }
            return $jsObj;
        }

        return JsUndefined::instance();
    }

    public static function toPhp(JsValue $value): mixed
    {
        if ($value instanceof JsUndefined || $value instanceof JsNull) {
            return null;
        }
        if ($value instanceof JsBoolean) {
            return $value->toBoolean();
        }
        if ($value instanceof JsNumber) {
            $num = $value->value;
            if ($num == (int) $num && abs($num) < PHP_INT_MAX && !is_nan($num) && !is_infinite($num)) {
                return (int) $num;
            }
            return $num;
        }
        if ($value instanceof JsString) {
            return $value->value;
        }
        if ($value instanceof JsArray) {
            $result = [];
            $len = $value->getLength();
            for ($i = 0; $i < $len; $i++) {
                $result[] = self::toPhp($value->get((string) $i));
            }
            return $result;
        }
        if ($value instanceof JsObject) {
            $result = [];
            foreach ($value->getOwnPropertyNames() as $key) {
                $result[$key] = self::toPhp($value->get($key));
            }
            return $result;
        }
        return null;
    }
}
