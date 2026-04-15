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
        // Store the canonical numeric string (no trailing 'n').
        $this->value = rtrim($value, 'n');
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
