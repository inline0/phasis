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
        $json->defineOwnProperty('rawJSON', PropertyDescriptor::data(
            JsFunction::fromCallable('rawJSON', self::rawJSON(), 1),
            true,
            false,
            true,
        ));
        $json->defineOwnProperty('isRawJSON', PropertyDescriptor::data(
            JsFunction::fromCallable('isRawJSON', self::isRawJSON(), 1),
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

    private static function rawJSON(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $input = $args[0] ?? JsUndefined::instance();
            $jsonString = TypeConversion::toString($input);

            if ($jsonString === '' || self::hasIllegalEndChars($jsonString)) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    'JSON.rawJSON: invalid raw JSON text'
                );
            }

            $decoded = json_decode($jsonString, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    'JSON.rawJSON: invalid JSON text'
                );
            }
            if (is_array($decoded)) {
                throw new \PhpJs\Exceptions\SyntaxError(
                    'JSON.rawJSON: raw JSON value must be a primitive, not an object or array'
                );
            }

            $obj = JsObject::createNullPrototype();
            $obj->defineOwnProperty('rawJSON', PropertyDescriptor::data(
                new JsString($jsonString),
                false,
                true,
                false,
            ));
            $obj->setInternalProperty('[[IsRawJSON]]', true);
            $obj->preventExtensions();
            return $obj;
        };
    }

    private static function isRawJSON(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $value = $args[0] ?? JsUndefined::instance();
            if (!$value instanceof JsObject) {
                return new JsBoolean(false);
            }
            return new JsBoolean($value->getInternalProperty('[[IsRawJSON]]') === true);
        };
    }

    private static function hasIllegalEndChars(string $s): bool
    {
        $illegal = ["\t", "\n", "\r", " "];
        $first = mb_substr($s, 0, 1, 'UTF-8');
        $last = mb_substr($s, -1, 1, 'UTF-8');
        return in_array($first, $illegal, true) || in_array($last, $illegal, true);
    }

    private static function isRawJsonObject(JsValue $value): bool
    {
        return $value instanceof JsObject
            && $value->getInternalProperty('[[IsRawJSON]]') === true;
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

            // Decode objects to stdClass so we can distinguish {} from [];
            // both decode to PHP's empty array under assoc=true.
            $decoded = json_decode($text);

            if ($decoded === null && $trimmed !== 'null') {
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \PhpJs\Exceptions\SyntaxError('Unexpected token in JSON');
                }
            }

            $reviver = ($args[1] ?? null) instanceof JsFunction ? $args[1] : null;

            if ($reviver !== null) {
                $parsed = self::parseWithSources($text);
                $result = $parsed['value'];
                $root = $parsed['root'];
                $sourceMap = $parsed['sourceMap'];
                $result = self::internalizeJSONProperty($root, '', $reviver, $sourceMap);
            } else {
                $result = self::phpToJsValue($decoded);
            }

            return $result;
        };
    }

    /**
     * @return array{value: JsValue, root: JsObject, sourceMap: array<string, array{0: string, 1: JsValue}>}
     */
    private static function parseWithSources(string $text): array
    {
        /** @var array<string, array{0: string, 1: JsValue}> $sourceMap */
        $sourceMap = [];
        $pos = 0;
        $root = new JsObject();
        $value = self::parseJsonValue($text, $pos, $sourceMap, $root, '');
        $root->defineOwnProperty('', PropertyDescriptor::data($value, true, true, true));
        return ['value' => $value, 'root' => $root, 'sourceMap' => $sourceMap];
    }

    /**
     * @param array<string, array{0: string, 1: JsValue}> $sourceMap
     */
    private static function parseJsonValue(
        string $text,
        int &$pos,
        array &$sourceMap,
        JsObject $holder,
        string $key,
    ): JsValue {
        self::skipWhitespace($text, $pos);
        $len = strlen($text);

        if ($pos >= $len) {
            throw new \PhpJs\Exceptions\SyntaxError('Unexpected end of JSON input');
        }

        $ch = $text[$pos];

        if ($ch === '{') {
            return self::parseJsonObjectWithSources($text, $pos, $sourceMap);
        }
        if ($ch === '[') {
            return self::parseJsonArrayWithSources($text, $pos, $sourceMap);
        }
        if ($ch === '"') {
            $start = $pos;
            $strVal = self::parseJsonStringRaw($text, $pos);
            $val = new JsString($strVal);
            $sourceMap[spl_object_id($holder) . ':' . $key] = [substr($text, $start, $pos - $start), $val];
            return $val;
        }
        if ($ch === '-' || ($ch >= '0' && $ch <= '9')) {
            $start = $pos;
            $numVal = self::parseJsonNumberRaw($text, $pos);
            $val = new JsNumber($numVal);
            $sourceMap[spl_object_id($holder) . ':' . $key] = [substr($text, $start, $pos - $start), $val];
            return $val;
        }
        if (substr($text, $pos, 4) === 'true') {
            $val = new JsBoolean(true);
            $sourceMap[spl_object_id($holder) . ':' . $key] = ['true', $val];
            $pos += 4;
            return $val;
        }
        if (substr($text, $pos, 5) === 'false') {
            $val = new JsBoolean(false);
            $sourceMap[spl_object_id($holder) . ':' . $key] = ['false', $val];
            $pos += 5;
            return $val;
        }
        if (substr($text, $pos, 4) === 'null') {
            $val = JsNull::instance();
            $sourceMap[spl_object_id($holder) . ':' . $key] = ['null', $val];
            $pos += 4;
            return $val;
        }

        throw new \PhpJs\Exceptions\SyntaxError('Unexpected token in JSON at position ' . $pos);
    }

    /**
     * @param array<string, array{0: string, 1: JsValue}> $sourceMap
     */
    private static function parseJsonObjectWithSources(
        string $text,
        int &$pos,
        array &$sourceMap,
    ): JsObject {
        $pos++; // skip {
        $obj = new JsObject();
        self::skipWhitespace($text, $pos);

        if ($pos < strlen($text) && $text[$pos] === '}') {
            $pos++;
            return $obj;
        }

        while ($pos < strlen($text)) {
            self::skipWhitespace($text, $pos);
            if ($text[$pos] !== '"') {
                throw new \PhpJs\Exceptions\SyntaxError('Expected string key in JSON object');
            }
            $propKey = self::parseJsonStringRaw($text, $pos);
            self::skipWhitespace($text, $pos);
            if ($pos >= strlen($text) || $text[$pos] !== ':') {
                throw new \PhpJs\Exceptions\SyntaxError('Expected colon in JSON object');
            }
            $pos++; // skip :
            $propVal = self::parseJsonValue($text, $pos, $sourceMap, $obj, $propKey);
            $obj->defineOwnProperty($propKey, PropertyDescriptor::data($propVal, true, true, true));
            self::skipWhitespace($text, $pos);
            if ($pos >= strlen($text)) {
                break;
            }
            if ($text[$pos] === '}') {
                $pos++;
                return $obj;
            }
            if ($text[$pos] !== ',') {
                throw new \PhpJs\Exceptions\SyntaxError('Expected comma or closing brace in JSON object');
            }
            $pos++; // skip ,
        }

        throw new \PhpJs\Exceptions\SyntaxError('Unterminated JSON object');
    }

    /**
     * @param array<string, array{0: string, 1: JsValue}> $sourceMap
     */
    private static function parseJsonArrayWithSources(
        string $text,
        int &$pos,
        array &$sourceMap,
    ): JsArray {
        $pos++; // skip [
        $arr = JsArray::fromArray([]);
        $index = 0;
        self::skipWhitespace($text, $pos);

        if ($pos < strlen($text) && $text[$pos] === ']') {
            $pos++;
            return $arr;
        }

        while ($pos < strlen($text)) {
            $propKey = (string) $index;
            $propVal = self::parseJsonValue($text, $pos, $sourceMap, $arr, $propKey);
            $arr->defineOwnProperty($propKey, PropertyDescriptor::data($propVal, true, true, true));
            $index++;
            $arr->set('length', new JsNumber($index));
            self::skipWhitespace($text, $pos);
            if ($pos >= strlen($text)) {
                break;
            }
            if ($text[$pos] === ']') {
                $pos++;
                return $arr;
            }
            if ($text[$pos] !== ',') {
                throw new \PhpJs\Exceptions\SyntaxError('Expected comma or closing bracket in JSON array');
            }
            $pos++; // skip ,
        }

        throw new \PhpJs\Exceptions\SyntaxError('Unterminated JSON array');
    }

    private static function parseJsonStringRaw(string $text, int &$pos): string
    {
        $pos++; // skip opening "
        $result = '';
        $len = strlen($text);

        while ($pos < $len) {
            $ch = $text[$pos];
            if ($ch === '"') {
                $pos++;
                return $result;
            }
            if ($ch === '\\') {
                $pos++;
                if ($pos >= $len) {
                    break;
                }
                $esc = $text[$pos];
                if ($esc === 'u') {
                    $pos++;
                    if ($pos + 4 > $len) {
                        throw new \PhpJs\Exceptions\SyntaxError('Invalid unicode escape in JSON');
                    }
                    $hex = substr($text, $pos, 4);
                    $pos += 4;
                    $cp = hexdec($hex);
                    $result .= mb_chr((int) $cp, 'UTF-8') ?: '';
                } else {
                    $result .= match ($esc) {
                        '"' => '"',
                        '\\' => '\\',
                        '/' => '/',
                        'b' => "\x08",
                        'f' => "\x0C",
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        default => $esc,
                    };
                    $pos++;
                }
            } else {
                $result .= $ch;
                $pos++;
            }
        }

        throw new \PhpJs\Exceptions\SyntaxError('Unterminated string in JSON');
    }

    private static function parseJsonNumberRaw(string $text, int &$pos): float
    {
        $start = $pos;
        $slen = strlen($text);
        if ($text[$pos] === '-') {
            $pos++;
        }
        while ($pos < $slen && $text[$pos] >= '0' && $text[$pos] <= '9') {
            $pos++;
        }
        if ($pos < $slen && $text[$pos] === '.') {
            $pos++;
            while ($pos < $slen && $text[$pos] >= '0' && $text[$pos] <= '9') {
                $pos++;
            }
        }
        if ($pos < $slen && ($text[$pos] === 'e' || $text[$pos] === 'E')) {
            $pos++;
            if ($pos < $slen && ($text[$pos] === '+' || $text[$pos] === '-')) {
                $pos++;
            }
            while ($pos < $slen && $text[$pos] >= '0' && $text[$pos] <= '9') {
                $pos++;
            }
        }
        $raw = substr($text, $start, $pos - $start);
        return $raw === '-0' ? -0.0 : (float) $raw;
    }

    private static function skipWhitespace(string $text, int &$pos): void
    {
        $slen = strlen($text);
        while (
            $pos < $slen
            && ($text[$pos] === ' ' || $text[$pos] === "\t"
                || $text[$pos] === "\n" || $text[$pos] === "\r")
        ) {
            $pos++;
        }
    }

    /**
     * @param array<string, array{0: string, 1: JsValue}>|null $sourceMap
     */
    private static function internalizeJSONProperty(
        JsObject $holder,
        string $name,
        JsFunction $reviver,
        ?array $sourceMap = null,
    ): JsValue {
        $val = $holder->get($name);

        if ($val instanceof JsObject) {
            if (self::jsIsArray($val)) {
                $len = self::arrayLikeLength($val);

                for ($i = 0; $i < $len; $i++) {
                    $prop = (string) $i;
                    $newElement = self::internalizeJSONProperty($val, $prop, $reviver, $sourceMap);

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
                    $newElement = self::internalizeJSONProperty($val, $key, $reviver, $sourceMap);

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

        if ($sourceMap !== null) {
            $context = new JsObject();
            $mapKey = spl_object_id($holder) . ':' . $name;
            // Per spec, include `source` whenever the current value is
            // SameValue with the originally-parsed value, even if the reviver
            // replaced it with a structurally-equal primitive.
            if (
                isset($sourceMap[$mapKey])
                && \PhpJs\Spec\AbstractOperations::sameValue($sourceMap[$mapKey][1], $val)
            ) {
                $context->defineOwnProperty('source', PropertyDescriptor::data(
                    new JsString($sourceMap[$mapKey][0]),
                    true,
                    true,
                    true,
                ));
            }
            return $reviver->call($holder, [new JsString($name), $val, $context]);
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
        if ($value instanceof \stdClass) {
            $obj = new JsObject();
            foreach (get_object_vars($value) as $key => $val) {
                $obj->defineOwnProperty(
                    $key,
                    PropertyDescriptor::data(self::phpToJsValue($val), true, true, true),
                );
            }
            return $obj;
        }
        if (is_array($value)) {
            // Note: PHP's json_decode($text, true) collapses both [] and {}
            // to []; we rely on json_decode($text) (no assoc flag) so empty
            // JSON objects come back as stdClass instances above. Any plain
            // PHP array reaching this branch comes from JSON arrays.
            $items = array_map(fn(mixed $v) => self::phpToJsValue($v), $value);
            return JsArray::fromArray($items);
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

        // Emit rawJSON values verbatim (JSON.rawJSON support).
        if ($value instanceof JsObject && self::isRawJsonObject($value)) {
            $rawProp = $value->get('rawJSON');
            return $rawProp instanceof JsString ? $rawProp->value : 'null';
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
            return self::quoteJsonString($value->value);
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

    /**
     * QuoteJSONString per spec with well-formed JSON stringify.
     *
     * Lone surrogates are escaped as \uXXXX instead of raw bytes.
     * Valid surrogate pairs are emitted as UTF-8 code points.
     */
    private static function quoteJsonString(string $str): string
    {
        $u16 = \PhpJs\Value\JsString::utf8ToUtf16LE($str);
        $u16Len = (int) (strlen($u16) / 2);
        $result = '"';
        $i = 0;
        while ($i < $u16Len) {
            $cu = ord($u16[$i * 2])
                | (ord($u16[$i * 2 + 1]) << 8);
            if ($cu >= 0xD800 && $cu <= 0xDBFF) {
                if ($i + 1 < $u16Len) {
                    $next = ord($u16[($i + 1) * 2])
                        | (ord($u16[($i + 1) * 2 + 1]) << 8);
                    if ($next >= 0xDC00 && $next <= 0xDFFF) {
                        $cp = ($cu - 0xD800) * 0x400
                            + ($next - 0xDC00) + 0x10000;
                        $result .= mb_chr($cp, 'UTF-8');
                        $i += 2;
                        continue;
                    }
                }
                $result .= sprintf('\\u%04x', $cu);
                $i++;
            } elseif ($cu >= 0xDC00 && $cu <= 0xDFFF) {
                $result .= sprintf('\\u%04x', $cu);
                $i++;
            } else {
                $result .= match ($cu) {
                    0x08 => '\\b',
                    0x09 => '\\t',
                    0x0A => '\\n',
                    0x0C => '\\f',
                    0x0D => '\\r',
                    0x22 => '\\"',
                    0x5C => '\\\\',
                    default => $cu < 0x20
                        ? sprintf('\\u%04x', $cu)
                        : mb_chr($cu, 'UTF-8'),
                };
                $i++;
            }
        }
        $result .= '"';
        return $result;
    }
}
