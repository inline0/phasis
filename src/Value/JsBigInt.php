<?php

declare(strict_types=1);

namespace PhpJs\Value;

/**
 * BigInt primitive value.
 *
 * Stores the numeric value as a string to handle arbitrarily large integers.
 * The string representation never contains the trailing 'n' suffix.
 */
class JsBigInt implements JsValue
{
    public readonly string $value;

    public function __construct(string $value)
    {
        // Store the canonical decimal string (no trailing 'n', no base prefixes).
        $this->value = self::normalize(rtrim($value, 'n'));
    }

    /**
     * Normalize a BigInt value string to a canonical decimal representation.
     *
     * Handles hex (0x), octal (0o), and binary (0b) prefixes, plus leading
     * zeros. Negative zero normalizes to "0".
     */
    private static function normalize(string $value): string
    {
        $sign = '';
        $digits = $value;

        if ($digits !== '' && $digits[0] === '-') {
            $sign = '-';
            $digits = substr($digits, 1);
        }

        // Hex prefix.
        if (str_starts_with($digits, '0x') || str_starts_with($digits, '0X')) {
            $hex = substr($digits, 2);
            $result = '0';
            $len = strlen($hex);
            for ($i = 0; $i < $len; $i++) {
                $d = hexdec($hex[$i]);
                $result = bcadd(bcmul($result, '16'), (string) $d);
            }
            $digits = $result;
        } elseif (str_starts_with($digits, '0o') || str_starts_with($digits, '0O')) {
            // Octal prefix.
            $oct = substr($digits, 2);
            $result = '0';
            $len = strlen($oct);
            for ($i = 0; $i < $len; $i++) {
                $result = bcadd(bcmul($result, '8'), $oct[$i]);
            }
            $digits = $result;
        } elseif (str_starts_with($digits, '0b') || str_starts_with($digits, '0B')) {
            // Binary prefix.
            $bin = substr($digits, 2);
            $result = '0';
            $len = strlen($bin);
            for ($i = 0; $i < $len; $i++) {
                $result = bcadd(bcmul($result, '2'), $bin[$i]);
            }
            $digits = $result;
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
        throw new \PhpJs\Exceptions\TypeError('Cannot convert a BigInt value to a number');
    }

    public function toInt32(): int
    {
        throw new \PhpJs\Exceptions\TypeError('Cannot convert a BigInt value to a number');
    }

    public function toUint32(): int
    {
        throw new \PhpJs\Exceptions\TypeError('Cannot convert a BigInt value to a number');
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
