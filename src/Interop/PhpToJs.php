<?php

declare(strict_types=1);

namespace Phasis\Interop;

use Phasis\Engine;
use Phasis\Exceptions\JsThrowable;
use Phasis\Runtime\Interpreter;
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
            $interpreter = Engine::getCurrentInterpreter();
            return JsFunction::fromCallable('(native)', function (JsValue $this_, array $args) use ($value, $interpreter): JsValue {
                try {
                    $phpArgs = array_map(fn(JsValue $v) => self::toPhp($v), $args);
                    return self::convert($value(...$phpArgs));
                } catch (JsThrowable $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    self::throwHostException($e, $interpreter);
                }
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

        if (is_object($value)) {
            return PhpBridge::wrap($value);
        }

        return JsUndefined::instance();
    }

    public static function throwHostException(\Throwable $e, ?Interpreter $interpreter = null): never
    {
        if ($e instanceof JsThrowable) {
            throw $e;
        }
        $interpreter ??= Engine::getCurrentInterpreter();
        if ($interpreter === null) {
            throw $e;
        }
        $error = $interpreter->phpExceptionToJsValue($e);
        if ($error instanceof JsObject && !($e instanceof \Phasis\Exceptions\RuntimeError || $e instanceof \Phasis\Exceptions\SyntaxError)) {
            $error->set('name', new JsString((new \ReflectionClass($e))->getShortName()));
        }
        throw new JsThrowable($error);
    }

    /** @param array<int, true> $seen */
    public static function toPhp(JsValue $value, array $seen = []): mixed
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
        if ($value instanceof PhpBridge) {
            return $value->getHostObject();
        }
        if ($value instanceof JsFunction) {
            $interpreter = $value->realm?->getInterpreter() ?? Engine::getCurrentInterpreter();
            return static function (mixed ...$args) use ($value, $interpreter): mixed {
                if ($interpreter === null) {
                    throw new \LogicException('JavaScript callback has no interpreter');
                }
                $previous = Engine::getCurrentInterpreter();
                Engine::setCurrentInterpreter($interpreter);
                try {
                    $jsArgs = array_map(self::convert(...), $args);
                    return self::toPhp($interpreter->callFunction($value, JsUndefined::instance(), $jsArgs));
                } finally {
                    Engine::setCurrentInterpreter($previous);
                }
            };
        }
        if ($value instanceof JsObject) {
            $id = spl_object_id($value);
            if (isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
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
                $result[] = self::toPhp($value->get((string) $i), $seen);
            }
            return $result;
        }
        if ($value instanceof JsObject) {
            $result = [];
            foreach ($value->getOwnPropertyNames() as $key) {
                $result[$key] = self::toPhp($value->get($key), $seen);
            }
            return $result;
        }
        return null;
    }
}
