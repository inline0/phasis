<?php

declare(strict_types=1);

namespace Phasis\Value;

/**
 * BigInt primitive value.
 *
 * Stores the numeric value as a canonical decimal string (no trailing 'n', no base prefixes).
 * Base conversion (hex/oct/bin) is done by the interpreter before constructing JsBigInt.
 */
class JsBigInt implements JsValue
{
    public readonly string $value;

    /** BigInt.prototype shared across all BigInt primitives. */
    private static ?JsObject $prototype = null;

    public static function setPrototype(JsObject $proto): void
    {
        self::$prototype = $proto;
    }

    public static function getPrototype(): ?JsObject
    {
        return self::$prototype;
    }

    public function __construct(string $value)
    {
        // Store the canonical decimal string (no trailing 'n', no base prefixes).
        $this->value = self::normalize(rtrim($value, 'n'));
    }

    /**
     * Normalize a BigInt decimal string: strip leading zeros, normalize -0 to 0.
     * Base conversion (hex/oct/bin) must be done before calling this.
     */
    private static function normalize(string $value): string
    {
        $sign = '';
        $digits = $value;

        if ($digits !== '' && $digits[0] === '-') {
            $sign = '-';
            $digits = substr($digits, 1);
        }

        // Strip leading zeros.
        $digits = ltrim($digits, '0') ?: '0';

        $result = $sign . $digits;

        // Normalize -0 to 0.
        if ($result === '-0') {
            return '0';
        }

        return $result;
    }

    public function typeof(): string
    {
        return 'bigint';
    }

    /**
     * ToBoolean: false if 0n, true otherwise.
     */
    public function toBoolean(): bool
    {
        // "0", "-0", or empty all count as zero.
        return $this->value !== '0' && $this->value !== '-0' && $this->value !== '';
    }

    public function toNumber(): float
    {
        throw new \Phasis\Exceptions\TypeError('Cannot convert a BigInt value to a number');
    }

    public function toInt32(): int
    {
        throw new \Phasis\Exceptions\TypeError('Cannot convert a BigInt value to a number');
    }

    public function toUint32(): int
    {
        throw new \Phasis\Exceptions\TypeError('Cannot convert a BigInt value to a number');
    }

    public function toJsString(): string
    {
        return $this->value;
    }

    public function display(): string
    {
        return $this->value . 'n';
    }
}
