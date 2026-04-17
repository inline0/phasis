<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Object\PropertyDescriptor;
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

        $json->defineOwnProperty('parse', PropertyDescriptor::data(
            JsFunction::fromCallable('parse', self::parse(), 2),
            true,
            false,
            true,
        ));
        $json->defineOwnProperty('stringify', PropertyDescriptor::data(
            JsFunction::fromCallable('stringify', self::stringify(), 3),
            true,
            false,
            true,
        ));

        // Symbol.toStringTag = "JSON" per spec 25.5.3.
        $toStringTagSym = SymbolConstructor::toStringTag();
        $json->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('JSON'), false, false, true),
        );

        $env->defineDeletable('JSON', $json);
    }

    private static function jsIsArray(JsValue $value): bool
    {
        if ($value instanceof \PhpJs\Value\JsProxy) {
            if ($value->isRevoked()) {
                throw new \PhpJs\Exceptions\TypeError(
                    'Cannot perform \'IsArray\' on a proxy that has been revoked'
                );
            }

            return self::jsIsArray($value->getTarget());
        }

        return $value instanceof JsArray;
    }

    private static function arrayLikeLength(JsObject $value): int
    {
        $lenVal = $value->get('length');

        if (!$lenVal instanceof JsUndefined) {
            return (int) TypeConversion::toNumber($lenVal);
        }

        if ($value instanceof \PhpJs\Value\JsProxy) {
            return self::arrayLikeLength($value->getTarget());
        }

        return 0;
    }

    private static function hasNumberData(JsObject $obj): bool
    {
        if (!$obj->has('[[PrimitiveValue]]')) {
            return false;
        }

        return $obj->get('[[PrimitiveValue]]') instanceof JsNumber;
    }

    private static function hasStringData(JsObject $obj): bool
    {
        if (!$obj->has('[[PrimitiveValue]]')) {
            return false;
        }

        return $obj->get('[[PrimitiveValue]]') instanceof JsString;
    }

    private static function hasBooleanData(JsObject $obj): bool
    {
        if (!$obj->has('[[PrimitiveValue]]')) {
            return false;
        }

        return $obj->get('[[PrimitiveValue]]') instanceof JsBoolean;
    }

    private static function hasBigIntData(JsObject $obj): bool
    {
        if (!$obj->has('[[PrimitiveValue]]')) {
            return false;
        }

        return $obj->get('[[PrimitiveValue]]') instanceof \PhpJs\Value\JsBigInt;
    }

    /**
     * @return list<string>
     */
    private static function enumerableOwnPropertyNames(JsObject $obj): array
    {
        if ($obj instanceof \PhpJs\Value\JsProxy) {
            return $obj->getOwnEnumerableKeys();
        }

        $allKeys = $obj->ordinaryOwnPropertyKeys();
        $result = [];

        foreach ($allKeys as $keyVal) {
            if (!$keyVal instanceof JsString) {
                continue;
            }

            $key = $keyVal->value;
            $desc = $obj->getOwnPropertyDescriptor($key);

            if ($desc !== null && $desc->enumerable === true) {
                $result[] = $key;
            }
        }

        return $result;
    }

    private static function parse(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $text = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
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
            $reviver = ($args[1] ?? null) instanceof JsFunction ? $args[1] : null;

            if ($reviver !== null) {
                $root = new JsObject();
                $root->defineOwnProperty('', PropertyDescriptor::data($result, true, true, true));
                $result = self::internalizeJSONProperty($root, '', $reviver);
            }

            return $result;
        };
    }

    private static function internalizeJSONProperty(
        JsObject $holder,
        string $name,
        JsFunction $reviver,
    ): JsValue {
        $val = $holder->get($name);

        if ($val instanceof JsObject) {
            if (self::jsIsArray($val)) {
                $len = self::arrayLikeLength($val);

                for ($i = 0; $i < $len; $i++) {
                    $prop = (string) $i;
                    $newElement = self::internalizeJSONProperty($val, $prop, $reviver);

                    if ($newElement instanceof JsUndefined) {
                        $val->delete($prop);
                    } else {
                        $desc = $val->getOwnPropertyDescriptor($prop);
                        if ($desc === null || $desc->configurable !== false) {
                            $val->defineOwnProperty(
                                $prop,
                                PropertyDescriptor::data($newElement, true, true, true),
                            );
                        }
                    }
                }
            } else {
                $keys = self::enumerableOwnPropertyNames($val);

                foreach ($keys as $key) {
                    $newElement = self::internalizeJSONProperty($val, $key, $reviver);

                    if ($newElement instanceof JsUndefined) {
                        $val->delete($key);
                    } else {
                        $desc = $val->getOwnPropertyDescriptor($key);
                        if ($desc === null || $desc->configurable !== false) {
                            $val->defineOwnProperty(
                                $key,
                                PropertyDescriptor::data($newElement, true, true, true),
                            );
                        }
                    }
                }
            }
        }

        return $reviver->call($holder, [new JsString($name), $val]);
    }

    private static function stringify(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $value = $args[0] ?? JsUndefined::instance();
            $replacerArg = $args[1] ?? JsUndefined::instance();
            $space = $args[2] ?? JsUndefined::instance();

            if ($space instanceof JsObject && !$space instanceof JsFunction) {
                if (self::hasNumberData($space)) {
                    $space = new JsNumber(TypeConversion::toNumber($space));
                } elseif (self::hasStringData($space)) {
                    $space = new JsString(TypeConversion::toString($space));
                }
            }

            $gap = '';

            if ($space instanceof JsNumber) {
                $count = max(0, min(10, (int) $space->value));
                $gap = str_repeat(' ', $count);
            } elseif ($space instanceof JsString) {
                $gap = mb_substr($space->value, 0, 10, 'UTF-8');
            }

            $replacerFn = null;
            /** @var list<string>|null $propertyList */
            $propertyList = null;

            if ($replacerArg instanceof JsFunction) {
                $replacerFn = $replacerArg;
            } elseif ($replacerArg instanceof JsObject && self::jsIsArray($replacerArg)) {
                $propertyList = [];
                $seen = [];
                $len = self::arrayLikeLength($replacerArg);

                for ($i = 0; $i < $len; $i++) {
                    $item = $replacerArg->get((string) $i);
                    $key = null;

                    if ($item instanceof JsString) {
                        $key = $item->value;
                    } elseif ($item instanceof JsNumber) {
                        $key = TypeConversion::toString($item);
                    } elseif ($item instanceof JsObject) {
                        if (self::hasStringData($item) || self::hasNumberData($item)) {
                            $key = TypeConversion::toString($item);
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
            $holder = new JsObject();
            $holder->defineOwnProperty('', PropertyDescriptor::data($value, true, true, true));
            $result = self::serializeProperty('', $holder, $replacerFn, $propertyList, $gap, '', $stack);

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
            if (array_is_list($value)) {
                $items = array_map(fn(mixed $v) => self::phpToJsValue($v), $value);
                return JsArray::fromArray($items);
            }
            $obj = new JsObject();
            foreach ($value as $key => $val) {
                $obj->set((string) $key, self::phpToJsValue($val));
            }
            return $obj;
        }
        return JsNull::instance();
    }

    /**
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

        if ($value instanceof JsObject && $value->has('toJSON')) {
            $toJson = $value->get('toJSON');
            if ($toJson instanceof JsFunction) {
                $value = $toJson->call($value, [new JsString($key)]);
            }
        } elseif ($value instanceof \PhpJs\Value\JsBigInt) {
            // Check BigInt.prototype.toJSON per spec 25.5.2.1 step 2.
            // Must invoke the getter (if any) with the BigInt as receiver.
            $bigintProto = \PhpJs\Value\JsBigInt::getPrototype();
            if ($bigintProto !== null) {
                $desc = $bigintProto->getOwnPropertyDescriptor('toJSON');
                if ($desc !== null && $desc->get instanceof JsFunction) {
                    // Accessor: call getter with BigInt as this
                    $toJson = $desc->get->call($value, []);
                    if ($toJson instanceof JsFunction) {
                        $value = $toJson->call($value, [new JsString($key)]);
                    }
                } elseif ($desc !== null && $desc->value instanceof JsFunction) {
                    // Data property
                    $value = $desc->value->call($value, [new JsString($key)]);
                }
            }
        }

        if ($replacerFn !== null) {
            $value = $replacerFn->call($holder, [new JsString($key), $value]);
        }

        if ($value instanceof JsObject && !self::jsIsArray($value) && !$value instanceof JsFunction) {
            if (self::hasNumberData($value)) {
                $value = new JsNumber(TypeConversion::toNumber($value));
            } elseif (self::hasStringData($value)) {
                $value = new JsString(TypeConversion::toString($value));
            } elseif (self::hasBooleanData($value)) {
                $prim = $value->get('[[PrimitiveValue]]');
                if ($prim instanceof JsBoolean) {
                    $value = $prim;
                }
            } elseif (self::hasBigIntData($value)) {
                throw new \PhpJs\Exceptions\TypeError('Do not know how to serialize a BigInt');
            }
        }

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
        if ($value instanceof \PhpJs\Value\JsBigInt) {
            throw new \PhpJs\Exceptions\TypeError('Do not know how to serialize a BigInt');
        }

        if ($value instanceof JsObject && !$value instanceof JsFunction) {
            if ($value instanceof \PhpJs\Value\JsProxy && $value->isRevoked()) {
                throw new \PhpJs\Exceptions\TypeError('Cannot perform \'get\' on a proxy that has been revoked');
            }
            if ($stack->contains($value)) {
                throw new \PhpJs\Exceptions\TypeError('Converting circular structure to JSON');
            }
            if (self::jsIsArray($value)) {
                return self::serializeArray($value, $replacerFn, $propertyList, $gap, $indent, $stack);
            }
            return self::serializeObject($value, $replacerFn, $propertyList, $gap, $indent, $stack);
        }

        return null;
    }

    /**
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
        $keys = $propertyList ?? self::enumerableOwnPropertyNames($value);
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
     * @param \SplObjectStorage<JsObject, true> $stack
     * @param list<string>|null $propertyList
     */
    private static function serializeArray(
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
        $len = self::arrayLikeLength($value);
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
