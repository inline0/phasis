<?php

declare(strict_types=1);

namespace PhpJs\Value;

class JsNumber implements JsValue
{
    private static ?JsObject $numberPrototype = null;

    /**
     * Singleton cache for small integer values. Tight loops and hot
     * arithmetic (fib, counter increments, array index iteration) hit
     * the same small ints over and over; reusing one instance per int
     * avoids the allocator pressure that 4M+ JsNumber objects per
     * benchmark would otherwise create. Range [-127, 256] is the same
     * shape Java uses for Integer.valueOf — wide enough to cover the
     * common case, narrow enough to fit in a single small array.
     *
     * @var array<int, self>
     */
    private static array $smallIntCache = [];
    private const SMALL_INT_CACHE_MIN = -127;
    private const SMALL_INT_CACHE_MAX = 256;

    public static function setNumberPrototype(JsObject $proto): void
    {
        self::$numberPrototype = $proto;
    }

    public static function resetNumberPrototype(): void
    {
        self::$numberPrototype = null;
    }

    public static function getNumberPrototype(): ?JsObject
    {
        return self::$numberPrototype;
    }

    public function __construct(
        public readonly float $value,
    ) {
    }

    /**
     * Allocator-free factory for the common case where the value is a
     * small integer. Reuses cached singletons in [-127, 256]; falls
     * back to `new self(...)` for fractional, NaN, infinite, or
     * out-of-range values. Callers in hot VM arithmetic paths should
     * prefer this over `new JsNumber(...)`.
     */
    public static function of(float $value): self
    {
        // Fast guard: integer-shaped finite values within the cache
        // window. The branch order matters — the cache hit path must
        // be the first case PHP's JIT specializes on.
        if (
            $value >= self::SMALL_INT_CACHE_MIN
            && $value <= self::SMALL_INT_CACHE_MAX
            && $value === (float) (int) $value
        ) {
            $key = (int) $value;
            // -0 must NOT alias 0: per spec NaN !== NaN, but
            // Object.is(-0, 0) is false. The (float)(int) round-trip
            // collapses -0 to 0 (since (int)-0.0 === 0), so we'd
            // hand out the +0 singleton. Detect the sign-bit case
            // and bypass the cache for -0 to keep its identity.
            if ($key === 0 && self::isNegativeZero($value)) {
                return new self($value);
            }
            return self::$smallIntCache[$key] ??= new self((float) $key);
        }
        return new self($value);
    }

    public static function resetSmallIntCache(): void
    {
        self::$smallIntCache = [];
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

        return self::numberToString($this->value);
    }

    /**
     * ECMAScript 7.1.12.1 Number::toString - convert a finite non-zero float to JS string.
     *
     * Uses PHP's json_encode for shortest decimal representation (Grisu3/Ryu),
     * then applies the ECMAScript formatting rules based on the exponent.
     */
    private static function numberToString(float $value): string
    {
        $negative = $value < 0;
        $abs = abs($value);

        // Integer values in range < 1e21: no decimal point needed.
        if (floor($abs) === $abs && $abs < 1e21) {
            return number_format($value, 0, '', '');
        }

        // Get shortest decimal representation.
        $json = json_encode($abs, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = sprintf('%.17g', $abs);
        }

        // Parse digits and exponent from the JSON output.
        // PHP json_encode produces forms like: "1.5", "1.5e+20", "1.5e-5", "15000"
        $json = strtolower($json);
        if (str_contains($json, 'e')) {
            [$mantissa, $exp] = explode('e', $json, 2);
            $phpExp = (int) $exp;
        } else {
            $mantissa = $json;
            $phpExp = 0;
        }

        // Extract digits (remove decimal point) and determine k and n.
        if (str_contains($mantissa, '.')) {
            $dotPos = strpos($mantissa, '.');
            $digits = str_replace('.', '', $mantissa);
            // Number of decimal places in mantissa.
            $decimalPlaces = strlen($mantissa) - $dotPos - 1;
            // Adjust exponent: mantissa * 10^phpExp = digits * 10^(phpExp - decimalPlaces).
            $actualExp = $phpExp - $decimalPlaces;
        } else {
            $digits = $mantissa;
            $actualExp = $phpExp;
        }

        // Remove leading zeros (shouldn't happen with json_encode, but be safe).
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
        }

        // Strip trailing zeros from the digits (PHP json_encode may add ".0" to exact values).
        // When we strip trailing zeros, we must increase actualExp by the same count.
        $trailingZeros = strlen($digits) - strlen(rtrim($digits, '0'));
        if ($trailingZeros > 0) {
            $digits = rtrim($digits, '0');
            $actualExp += $trailingZeros;
        }
        if ($digits === '') {
            $digits = '0';
        }

        $k = strlen($digits); // number of significant digits
        // n = actualExp + k (the power such that value = s * 10^(n-k))
        $n = $actualExp + $k;

        $result = '';

        if ($k <= $n && $n <= 21) {
            // Integer: digits followed by (n-k) zeros.
            $result = $digits . str_repeat('0', $n - $k);
        } elseif (0 < $n && $n <= 21) {
            // Fixed with decimal point.
            $result = substr($digits, 0, $n) . '.' . substr($digits, $n);
        } elseif (-6 < $n && $n <= 0) {
            // Small number: "0." + (-n) zeros + digits.
            $result = '0.' . str_repeat('0', -$n) . $digits;
        } elseif ($k === 1) {
            // Exponential with single digit.
            $e = $n - 1;
            $result = $digits . 'e' . ($e >= 0 ? '+' : '') . $e;
        } else {
            // Exponential with multiple digits.
            $e = $n - 1;
            $result = $digits[0] . '.' . substr($digits, 1) . 'e' . ($e >= 0 ? '+' : '') . $e;
        }

        return $negative ? '-' . $result : $result;
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
