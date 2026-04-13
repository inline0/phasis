<?php

declare(strict_types=1);

namespace PhpJs\Value;

class JsNumber implements JsValue
{
    public function __construct(
        public readonly float $value,
    ) {
    }

    public function typeof(): string
    {
        return 'number';
    }

    public function toBoolean(): bool
    {
        // +0, -0, NaN -> false. Everything else -> true.
        if ($this->value === 0.0 || is_nan($this->value)) {
            return false;
        }

        return true;
    }

    public function toNumber(): float
    {
        return $this->value;
    }

    public function toInt32(): int
    {
        if (is_nan($this->value) || is_infinite($this->value) || $this->value === 0.0) {
            return 0;
        }

        // 7.1.5 ToInt32: modulo 2^32, then adjust to signed range.
        $n = ($this->value > 0 ? 1.0 : -1.0) * floor(abs($this->value));
        $int32 = fmod($n, 4294967296.0);
        if ($int32 < 0) {
            $int32 += 4294967296.0;
        }

        if ($int32 >= 2147483648.0) {
            $int32 -= 4294967296.0;
        }

        return (int) $int32;
    }

    public function toUint32(): int
    {
        if (is_nan($this->value) || is_infinite($this->value) || $this->value === 0.0) {
            return 0;
        }

        // 7.1.6 ToUint32: modulo 2^32.
        $n = ($this->value > 0 ? 1.0 : -1.0) * floor(abs($this->value));
        $int32 = fmod($n, 4294967296.0);
        if ($int32 < 0) {
            $int32 += 4294967296.0;
        }

        return (int) $int32;
    }

    public function toJsString(): string
    {
        if (is_nan($this->value)) {
            return 'NaN';
        }

        if (is_infinite($this->value)) {
            return $this->value > 0 ? 'Infinity' : '-Infinity';
        }

        // -0 -> "0".
        if ($this->value === 0.0) {
            return '0';
        }

        // Integer values should not have a decimal point.
        if (floor($this->value) === $this->value && abs($this->value) < 1e21) {
            return number_format($this->value, 0, '', '');
        }

        // Use json_encode for shortest representation matching V8.
        $str = json_encode($this->value);
        if ($str === false) {
            return (string) $this->value;
        }

        // json_encode may produce "1.0E+21" style; JS uses "1e+21".
        $str = str_replace('E+', 'e+', $str);
        $str = str_replace('E-', 'e-', $str);

        return $str;
    }

    public function display(): string
    {
        return $this->toJsString();
    }

    /** Check if this number is NaN. */
    public function isNaN(): bool
    {
        return is_nan($this->value);
    }

    /** Check if a float value is negative zero using IEEE 754 bit comparison. */
    public static function isNegativeZero(float $value = 0.0): bool
    {
        return $value === 0.0 && pack('E', $value) === pack('E', -0.0);
    }
}
