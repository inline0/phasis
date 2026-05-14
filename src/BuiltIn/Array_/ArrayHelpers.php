<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Array_;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * ArrayConstructor trait part: ArrayHelpers. Composed into
 * ArrayConstructor via `use Array_\ArrayHelpers;`. `self::`/`$this->`
 * resolve into the composing class.
 */
trait ArrayHelpers
{
    /**
     * Get length from array or array-like object.
     */
    /**
     * Per spec, Array prototype methods call ToObject(this) and throw
     * TypeError for null/undefined. For primitive values like booleans,
     * numbers, strings, ToObject wraps them.
     */
    private static function toObject(JsValue $this_): JsObject
    {
        if ($this_ instanceof JsNull || $this_ instanceof JsUndefined) {
            throw new TypeError('Array.prototype method called on null or undefined');
        }
        if ($this_ instanceof JsObject) {
            return $this_;
        }
        // Wrap primitive values
        return TypeConversion::toObject($this_);
    }

    /**
     * ArraySpeciesCreate(originalArray, length) per spec 7.3.20.
     *
     * Uses the @@species pattern to create the result array. Falls back to
     * a plain Array when the species is undefined or the original is not an array.
     */
    private static function arraySpeciesCreate(JsObject $originalArray, int $length): JsObject
    {
        $makeArray = static function (int $len): JsArray {
            // The Array constructor with a single numeric arg rejects lengths
            // that exceed 2^32 - 1 with a RangeError. Match that here so the
            // non-Array fallback surfaces the same error before we try to
            // iterate a gargantuan length.
            if ($len < 0 || $len > 4294967295) {
                throw new \Phasis\Exceptions\RangeError('Invalid array length');
            }
            // Pin the prototype to the CURRENT realm's %Array.prototype% so
            // a previously-created sibling realm cannot leak its prototype
            // through JsArray::$globalPrototype (which is process-wide).
            $thisRealm = \Phasis\Engine::getCurrentRealm();
            $arrProto = null;
            if ($thisRealm !== null) {
                $arrProto = \Phasis\Spec\AbstractOperations::realmIntrinsicPrototype(
                    $thisRealm->getGlobalEnv(),
                    'Array',
                );
            }
            $arr = new JsArray([], $arrProto);
            $arr->setLength($len);
            return $arr;
        };
        // Per spec 7.3.20 step 3: IsArray(originalArray), walks through Proxy.
        if (!self::isArrayValue($originalArray)) {
            return $makeArray($length);
        }
        $c = $originalArray->get('constructor');
        if ($c instanceof JsUndefined) {
            return $makeArray($length);
        }
        // Spec 7.3.20 step 6.a–c: if C is a constructor from a different
        // realm and SameValue(C, realmC.[[Intrinsics]].[[%Array%]]) is true,
        // let C be undefined (so we create a plain Array of the *current*
        // realm). Without this, cross-realm `array.constructor = OArray`
        // would build OArray instances even when the user meant the
        // current realm's Array.
        if (
            $c instanceof JsFunction
            && $c->isConstructable()
        ) {
            $thisRealm = \Phasis\Engine::getCurrentRealm();
            $realmC = \Phasis\Spec\AbstractOperations::getFunctionRealm($c);
            if ($thisRealm !== null && $realmC !== null && $thisRealm !== $realmC) {
                $otherArray = $realmC->getGlobalEnv()->has('Array')
                    ? $realmC->getGlobalEnv()->get('Array')
                    : null;
                if ($otherArray === $c) {
                    return $makeArray($length);
                }
            }
        }
        if ($c instanceof JsObject) {
            $speciesSym = SymbolConstructor::species();
            $species = $c->getBySymbol($speciesSym);
            if ($species instanceof JsNull || $species instanceof JsUndefined) {
                return $makeArray($length);
            }
            $c = $species;
        }
        if (!$c instanceof JsFunction || !$c->isConstructable()) {
            throw new TypeError('Species constructor is not a valid constructor');
        }
        $result = $c->construct([JsNumber::of((float) $length)]);
        if (!$result instanceof JsObject) {
            throw new TypeError('Species constructor did not return an object');
        }
        return $result;
    }

    /**
     * Convert a float array index to its canonical string property key.
     * Values within int range format as integers; larger integral floats
     * format without scientific notation so property lookup matches the
     * keys used by the test's getters.
     */
    private static function floatIndexToKey(float $f): string
    {
        if ($f >= PHP_INT_MIN && $f <= PHP_INT_MAX && (float) (int) $f === $f) {
            return (string) (int) $f;
        }
        return rtrim(rtrim(number_format($f, 0, '.', ''), '0'), '.');
    }

    private static function getLen(JsValue $obj): int
    {
        if ($obj instanceof JsArray) {
            return $obj->getLength();
        }
        if ($obj instanceof JsObject) {
            $lenVal = $obj->get('length');
            $n = TypeConversion::toNumber($lenVal);
            if (is_nan($n) || $n < 0) {
                return 0;
            }
            return (int) min($n, 4294967295);
        }
        return 0;
    }

    /**
     * Get length as float to preserve large values above PHP_INT_MAX (for overflow checks).
     */
    private static function getLenFloat(JsValue $obj): float
    {
        if ($obj instanceof JsArray) {
            return (float) $obj->getLength();
        }
        if ($obj instanceof JsObject) {
            $lenVal = $obj->get('length');
            $n = TypeConversion::toNumber($lenVal);
            if (is_nan($n) || $n < 0) {
                return 0.0;
            }
            return min($n, 9007199254740991.0); // 2^53 - 1
        }
        return 0.0;
    }

    /**
     * FlattenIntoArray per spec 23.1.3.11.1.
     *
     * Writes directly to the target using CreateDataPropertyOrThrow,
     * respecting extensibility and configurability of the target object.
     */
    private static function specFlattenIntoArray(
        JsObject $target,
        JsObject $source,
        int $sourceLen,
        int $start,
        int $depth,
        ?JsFunction $mapperFunction = null,
        ?JsValue $thisArg = null,
    ): int {
        $targetIndex = $start;
        for ($sourceIndex = 0; $sourceIndex < $sourceLen; $sourceIndex++) {
            $p = (string) $sourceIndex;
            if ($source->has($p)) {
                $element = $source->get($p);
                if ($mapperFunction !== null) {
                    $element = $mapperFunction->call(
                        $thisArg ?? JsUndefined::instance(),
                        [$element, JsNumber::of((float) $sourceIndex), $source],
                    );
                }
                $shouldFlatten = false;
                if ($depth > 0) {
                    $shouldFlatten = self::isArrayValue($element);
                }
                if ($shouldFlatten) {
                    /** @var JsObject $element */
                    $elementLen = self::lengthOfArrayLike($element);
                    $targetIndex = self::specFlattenIntoArray(
                        $target,
                        $element,
                        $elementLen,
                        $targetIndex,
                        $depth - 1,
                    );
                } else {
                    if ($targetIndex >= 9007199254740991) {
                        throw new TypeError('FlattenIntoArray: target index exceeded');
                    }
                    // CreateDataPropertyOrThrow per spec.
                    $success = $target->defineOwnProperty(
                        (string) $targetIndex,
                        PropertyDescriptor::data($element, true, true, true),
                    );
                    if (!$success) {
                        throw new TypeError(
                            'Cannot define property '
                            . $targetIndex . ' on result object'
                        );
                    }
                    $targetIndex++;
                }
            }
        }
        return $targetIndex;
    }

    private static function normalizeRelativeIndex(float $index, int $length): int
    {
        if ($index === INF) {
            return $length;
        }
        if ($index === -INF) {
            return 0;
        }

        $integerIndex = (int) $index;
        if ($integerIndex < 0) {
            return max($length + $integerIndex, 0);
        }

        return min($integerIndex, $length);
    }

    private static function lengthOfArrayLike(JsObject $object): int
    {
        return TypeConversion::toLength($object->get('length'));
    }

    private static function shouldUseSparseIndexScan(JsObject $object, int $length): bool
    {
        return !$object instanceof JsArray && $length > self::SPARSE_SCAN_THRESHOLD;
    }

    /**
     * @return list<int>
     */
    private static function numericPropertyIndicesInRange(
        JsObject $object,
        int $start,
        int $end,
        bool $descending = false,
    ): array {
        if ($end < $start) {
            return [];
        }

        $seen = [];
        $indices = [];
        $current = $object;

        while ($current !== null) {
            foreach ($current->getProperties()->keys() as $key) {
                if (!self::isCanonicalArrayIndexString($key)) {
                    continue;
                }
                if (isset($seen[$key])) {
                    continue;
                }

                $index = (int) $key;
                if ($index < $start || $index > $end) {
                    continue;
                }

                $seen[$key] = true;
                $indices[] = $index;
            }

            $current = $current->getPrototype();
        }

        $descending ? rsort($indices) : sort($indices);

        return $indices;
    }

    private static function isCanonicalArrayIndexString(string $key): bool
    {
        if ($key === '' || !ctype_digit($key)) {
            return false;
        }

        return $key === '0' || $key[0] !== '0';
    }

    /**
     * Create a TypeError object suitable for Promise rejection.
     */
    private static function createTypeErrorObject(string $message): JsObject
    {
        $err = new JsObject();
        $err->set('name', new JsString('TypeError'));
        $err->set('message', new JsString($message));
        return $err;
    }
}
