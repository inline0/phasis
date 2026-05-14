<?php

declare(strict_types=1);

namespace Phasis\Interop;

use Phasis\Value\JsArray;
use Phasis\Value\JsArrayBuffer;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsDataView;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsTypedArray;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

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
            return JsNumber::of((float) $value);
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
                $jsArr->set('length', JsNumber::of((float) count($value)));
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
        // Opaque JS objects that have no natural PHP equivalent: return as-is
        // so that PHP callbacks can operate on the original JsValue identity.
        if (
            $value instanceof JsArrayBuffer
            || $value instanceof JsDataView
            || $value instanceof JsTypedArray
        ) {
            return $value;
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
