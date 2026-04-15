<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class JsonObject
{
    public static function install(Environment $env): void
    {
        $json = new JsObject();

        $json->defineOwnProperty('parse', \PhpJs\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('parse', self::parse(), 2),
            true,
            false,
            true,
        ));
        $json->defineOwnProperty('stringify', \PhpJs\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('stringify', self::stringify(), 3),
            true,
            false,
            true,
        ));

        $env->defineVar('JSON', $json);
    }

    private static function parse(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $text = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';

            // Handle -0 specially (PHP json_decode loses the sign)
            $trimmed = trim($text);
            if ($trimmed === '-0') {
                return new JsNumber(-0.0);
            }

            $decoded = json_decode($text, true);

            if ($decoded === null && $trimmed !== 'null') {
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \PhpJs\Exceptions\SyntaxError('Unexpected token in JSON');
                }
            }

            $result = self::phpToJsValue($decoded);

            // Apply reviver function if provided
            $reviver = ($args[1] ?? null) instanceof JsFunction ? $args[1] : null;
            if ($reviver !== null && $result instanceof JsObject) {
                $result = self::applyReviver($result, $reviver);
            }

            return $result;
        };
    }

    private static function applyReviver(JsObject $obj, JsFunction $reviver): JsValue
    {
        $keys = $obj instanceof JsArray
            ? array_map('strval', range(0, $obj->getLength() - 1))
            : $obj->getOwnEnumerableKeys();

        foreach ($keys as $key) {
            $val = $obj->get($key);
            if ($val instanceof JsObject) {
                $val = self::applyReviver($val, $reviver);
            }
            $newVal = $reviver->call($obj, [new JsString($key), $val]);
            if ($newVal instanceof JsUndefined) {
                $obj->delete($key);
            } else {
                $obj->set($key, $newVal);
            }
        }

        return $obj;
    }

    private static function stringify(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $value = $args[0] ?? JsUndefined::instance();
            // The second argument (replacer) and third (space) are simplified.
            $space = $args[2] ?? null;
            $indent = 0;
            if ($space instanceof JsNumber) {
                $indent = max(0, min(10, (int) $space->value));
            } elseif ($space instanceof JsString) {
                // Use the string as indentation (up to 10 chars).
                $indentStr = mb_substr($space->value, 0, 10, 'UTF-8');
                // For simplicity, use length as indent level.
                $indent = mb_strlen($indentStr, 'UTF-8');
            }

            $result = self::jsValueToJson($value);
            if ($result === null) {
                return JsUndefined::instance();
            }

            if ($indent > 0) {
                // Re-encode with indentation.
                $phpVal = json_decode($result, true);
                $pretty = json_encode($phpVal, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($pretty !== false && $indent !== 4) {
                    // json_encode uses 4-space indent. Adjust if different.
                    $lines = explode("\n", $pretty);
                    $adjusted = [];
                    foreach ($lines as $line) {
                        $stripped = ltrim($line, ' ');
                        $spaces = strlen($line) - strlen($stripped);
                        $level = (int) ($spaces / 4);
                        $adjusted[] = str_repeat(' ', $level * $indent) . $stripped;
                    }
                    return new JsString(implode("\n", $adjusted));
                }
                return new JsString($pretty !== false ? $pretty : $result);
            }

            return new JsString($result);
        };
    }

    private static function phpToJsValue(mixed $value): JsValue
    {
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
        if (is_array($value)) {
            // Check if it is a sequential (list) array or associative.
            if (array_is_list($value)) {
                $items = array_map(fn(mixed $v) => self::phpToJsValue($v), $value);
                return JsArray::fromArray($items);
            }
            // Associative array becomes JsObject.
            $obj = new JsObject();
            foreach ($value as $key => $val) {
                $obj->set((string) $key, self::phpToJsValue($val));
            }
            return $obj;
        }
        return JsNull::instance();
    }

    private static function jsValueToJson(JsValue $value): ?string
    {
        // Check for toJSON() method on objects
        if ($value instanceof JsObject && $value->has('toJSON')) {
            $toJson = $value->get('toJSON');
            if ($toJson instanceof JsFunction) {
                $value = $toJson->call($value, []);
            }
        }

        if ($value instanceof JsUndefined || $value instanceof JsFunction) {
            return null;
        }

        if ($value instanceof JsNull) {
            return 'null';
        }

        if ($value instanceof JsBoolean) {
            return $value->value ? 'true' : 'false';
        }

        if ($value instanceof JsNumber) {
            if (is_nan($value->value) || is_infinite($value->value)) {
                return 'null';
            }
            return $value->toJsString();
        }

        if ($value instanceof JsString) {
            $encoded = json_encode($value->value, JSON_UNESCAPED_UNICODE);
            return $encoded !== false ? $encoded : '"' . $value->value . '"';
        }

        if ($value instanceof JsArray) {
            $parts = [];
            $len = $value->getLength();
            for ($i = 0; $i < $len; $i++) {
                $elem = $value->get((string) $i);
                $json = self::jsValueToJson($elem);
                $parts[] = $json ?? 'null';
            }
            return '[' . implode(',', $parts) . ']';
        }

        if ($value instanceof JsObject) {
            $parts = [];
            $keys = $value->getOwnEnumerableKeys();
            foreach ($keys as $key) {
                $val = $value->get($key);
                $json = self::jsValueToJson($val);
                if ($json === null) {
                    continue; // Skip undefined and function values.
                }
                $encodedKey = json_encode($key, JSON_UNESCAPED_UNICODE);
                if ($encodedKey === false) {
                    $encodedKey = '"' . $key . '"';
                }
                $parts[] = $encodedKey . ':' . $json;
            }
            return '{' . implode(',', $parts) . '}';
        }

        return null;
    }
}
