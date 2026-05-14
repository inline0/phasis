<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Temporal;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBigInt;
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
 * Temporal helper section (BigIntNsHelpers). Composed into TemporalObject
 * via `use Temporal\BigIntNsHelpers;`. `self::` references resolve into
 * the composing class.
 */
trait BigIntNsHelpers
{
    // -----------------------------------------------------------------------
    // Helpers: BigInt nanosecond arithmetic via bcmath
    // -----------------------------------------------------------------------

    private static function bigCmp(string $a, string $b): int
    {
        return bccomp($a, $b, 0);
    }

    private static function bigFloorDiv(string $ns, string $divisor): float
    {
        // Floor division toward negative infinity for epoch milliseconds.
        $neg = (isset($ns[0]) && $ns[0] === '-');
        if (!$neg) {
            $q = bcdiv($ns, $divisor, 0);
            return (float) $q;
        }
        // For negative: floor division.
        $abs = substr($ns, 1);
        $q = bcdiv($abs, $divisor, 0);
        $rem = bcsub($abs, bcmul($q, $divisor, 0), 0);
        if ($rem !== '0') {
            $q = bcadd($q, '1', 0);
        }
        return -1.0 * (float) $q;
    }

    private static function validateInstantRange(string $ns): void
    {
        if (bccomp($ns, self::NS_MAX, 0) > 0 || bccomp($ns, self::NS_MIN, 0) < 0) {
            throw new RangeError('Instant outside representable range');
        }
    }

    private static function toBigIntNsFromArg(JsValue $arg): string
    {
        if ($arg instanceof JsBigInt) {
            return $arg->value;
        }
        if ($arg instanceof JsString) {
            // Parse as BigInt string.
            $str = trim($arg->value);
            if (!preg_match('/^-?[0-9]+$/', $str)) {
                throw new \Phasis\Exceptions\SyntaxError("Cannot convert {$str} to a BigInt");
            }
            return (new JsBigInt($str))->value;
        }
        if ($arg instanceof JsNumber) {
            throw new TypeError('Temporal.Instant requires a BigInt, not a Number');
        }
        throw new TypeError('Temporal.Instant requires a BigInt');
    }
}
