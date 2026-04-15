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

        $env->defineDeletable('JSON', $json);
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
            $replacerArg = $args[1] ?? JsUndefined::instance();
            $space = $args[2] ?? JsUndefined::instance();

            // Unwrap Number/String wrapper objects for the space parameter per spec.
            if ($space instanceof JsObject) {
                $prim = $space->get('[[PrimitiveValue]]');
                if ($prim instanceof JsNumber) {
                    $space = $prim;
                } elseif ($prim instanceof JsString) {
                    $space = $prim;
                } else {
                    // Try valueOf() for other objects
                    $valueOf = $space->get('valueOf');
                    if ($valueOf instanceof JsFunction) {
                        $val = $valueOf->call($space, []);
                        if ($val instanceof JsNumber || $val instanceof JsString) {
                            $space = $val;
                        }
                    }
                }
            }

            // Resolve gap string.
            $gap = '';
            if ($space instanceof JsNumber) {
                $count = max(0, min(10, (int) $space->value));
                $gap = str_repeat(' ', $count);
            } elseif ($space instanceof JsString) {
                $gap = mb_substr($space->value, 0, 10, 'UTF-8');
            }

            // Build replacer function or property list.
            $replacerFn = null;
            /** @var list<string>|null $propertyList */
            $propertyList = null;
            if ($replacerArg instanceof JsFunction) {
                $replacerFn = $replacerArg;
            } elseif ($replacerArg instanceof JsArray) {
                $propertyList = [];
                $seen = [];
                for ($i = 0; $i < $replacerArg->getLength(); $i++) {
                    $item = $replacerArg->get((string) $i);
                    $key = null;
                    if ($item instanceof JsString) {
                        $key = $item->value;
                    } elseif ($item instanceof JsNumber) {
                        $key = (new JsNumber($item->value))->toJsString();
                    } elseif ($item instanceof JsObject) {
                        $prim = $item->get('[[PrimitiveValue]]');
                        if ($prim instanceof JsString) {
                            $key = $prim->value;
                        } elseif ($prim instanceof JsNumber) {
                            $key = (new JsNumber($prim->value))->toJsString();
                        }
                    }
                    if ($key !== null && !isset($seen[$key])) {
                        $propertyList[] = $key;
                        $seen[$key] = true;
                    }
                }
            }

            /** @var \SplObjectStorage<JsObject, true> $stack */
            $stack = new \SplObjectStorage();

            // Wrap the value in a holder object via [[DefineOwnProperty]] (not [[Set]])
            // to avoid triggering Proxy traps per spec.
            $holder = new JsObject();
            $holder->defineOwnProperty('', \PhpJs\Object\PropertyDescriptor::data($value, true, true, true));
            $result = self::serializeProperty(
                '',
                $holder,
                $replacerFn,
                $propertyList,
                $gap,
                '',
                $stack,
            );

            if ($result === null) {
                return JsUndefined::instance();
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

    /**
     * SerializeJSONProperty per ES spec 25.5.2.5.
     *
     * @param \SplObjectStorage<JsObject, true> $stack
     * @param list<string>|null $propertyList
     */
    private static function serializeProperty(
        string $key,
        JsObject $holder,
        ?JsFunction $replacerFn,
        ?array $propertyList,
        string $gap,
        string $indent,
        \SplObjectStorage $stack,
    ): ?string {
        $value = $holder->get($key);

        // Step 2: call toJSON on the value if it exists.
        if ($value instanceof JsObject && $value->has('toJSON')) {
            $toJson = $value->get('toJSON');
            if ($toJson instanceof JsFunction) {
                $value = $toJson->call($value, [new JsString($key)]);
            }
        }

        // Step 3: apply the replacer function.
        if ($replacerFn !== null) {
            $value = $replacerFn->call($holder, [new JsString($key), $value]);
        }

        // Step 4: unwrap Number/String/Boolean wrapper objects.
        if ($value instanceof JsObject && !$value instanceof JsArray && !$value instanceof JsFunction) {
            $prim = $value->get('[[PrimitiveValue]]');
            if ($prim instanceof JsNumber) {
                $value = $prim;
            } elseif ($prim instanceof JsString) {
                $value = $prim;
            } elseif ($prim instanceof JsBoolean) {
                $value = $prim;
            }
        }

        // Steps 5-9: produce the JSON text.
        if ($value instanceof JsNull) {
            return 'null';
        }
        if ($value instanceof JsBoolean) {
            return $value->value ? 'true' : 'false';
        }
        if ($value instanceof JsString) {
            $encoded = json_encode($value->value, JSON_UNESCAPED_UNICODE);
            return $encoded !== false ? $encoded : '"' . $value->value . '"';
        }
        if ($value instanceof JsNumber) {
            if (is_nan($value->value) || is_infinite($value->value)) {
                return 'null';
            }
            return $value->toJsString();
        }

        // BigInt throws TypeError per spec.
        if ($value instanceof \PhpJs\Value\JsBigInt) {
            throw new \PhpJs\Exceptions\TypeError('Do not know how to serialize a BigInt');
        }

        // Step 10: arrays and objects (but not functions).
        if ($value instanceof JsObject && !$value instanceof JsFunction) {
            // Revoked Proxy check.
            if ($value instanceof \PhpJs\Value\JsProxy && $value->isRevoked()) {
                throw new \PhpJs\Exceptions\TypeError('Cannot perform \'get\' on a proxy that has been revoked');
            }

            // Circular reference check.
            if ($stack->contains($value)) {
                throw new \PhpJs\Exceptions\TypeError('Converting circular structure to JSON');
            }

            if ($value instanceof JsArray) {
                return self::serializeArray($value, $replacerFn, $propertyList, $gap, $indent, $stack);
            }
            return self::serializeObject($value, $replacerFn, $propertyList, $gap, $indent, $stack);
        }

        // undefined, functions, and symbols return null (omitted).
        return null;
    }

    /**
     * SerializeJSONObject per ES spec 25.5.2.6.
     *
     * @param \SplObjectStorage<JsObject, true> $stack
     * @param list<string>|null $propertyList
     */
    private static function serializeObject(
        JsObject $value,
        ?JsFunction $replacerFn,
        ?array $propertyList,
        string $gap,
        string $indent,
        \SplObjectStorage $stack,
    ): string {
        $stack->attach($value);
        $stepback = $indent;
        $indent .= $gap;

        $keys = $propertyList ?? $value->getOwnEnumerableKeys();

        $partial = [];
        foreach ($keys as $key) {
            $strP = self::serializeProperty($key, $value, $replacerFn, $propertyList, $gap, $indent, $stack);
            if ($strP !== null) {
                $encodedKey = json_encode($key, JSON_UNESCAPED_UNICODE);
                if ($encodedKey === false) {
                    $encodedKey = '"' . $key . '"';
                }
                $member = $encodedKey . ':';
                if ($gap !== '') {
                    $member .= ' ';
                }
                $member .= $strP;
                $partial[] = $member;
            }
        }

        $stack->detach($value);

        if ($partial === []) {
            return '{}';
        }

        if ($gap === '') {
            return '{' . implode(',', $partial) . '}';
        }

        $separator = ",\n" . $indent;
        $properties = implode($separator, $partial);
        return "{\n" . $indent . $properties . "\n" . $stepback . '}';
    }

    /**
     * SerializeJSONArray per ES spec 25.5.2.7.
     *
     * @param \SplObjectStorage<JsObject, true> $stack
     * @param list<string>|null $propertyList
     */
    private static function serializeArray(
        JsArray $value,
        ?JsFunction $replacerFn,
        ?array $propertyList,
        string $gap,
        string $indent,
        \SplObjectStorage $stack,
    ): string {
        $stack->attach($value);
        $stepback = $indent;
        $indent .= $gap;

        $len = $value->getLength();
        $partial = [];
        for ($i = 0; $i < $len; $i++) {
            $strP = self::serializeProperty((string) $i, $value, $replacerFn, $propertyList, $gap, $indent, $stack);
            $partial[] = $strP ?? 'null';
        }

        $stack->detach($value);

        if ($partial === []) {
            return '[]';
        }

        if ($gap === '') {
            return '[' . implode(',', $partial) . ']';
        }

        $separator = ",\n" . $indent;
        $properties = implode($separator, $partial);
        return "[\n" . $indent . $properties . "\n" . $stepback . ']';
    }
}
