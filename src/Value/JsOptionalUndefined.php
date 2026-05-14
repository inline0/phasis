<?php

declare(strict_types=1);

namespace Phasis\Value;

/**
 * Sentinel value returned by optional chaining (?.) when the base is null/undefined.
 * Propagates through subsequent member/call expressions in the chain.
 * Unwrapped to JsUndefined at expression boundaries.
 *
 * Implements the same interface as JsUndefined so it behaves identically if
 * accidentally used in arithmetic, comparisons, etc.
 */
final class JsOptionalUndefined implements JsValue
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function typeof(): string
    {
        return 'undefined';
    }

    public function toBoolean(): bool
    {
        return false;
    }

    public function toNumber(): float
    {
        return NAN;
    }

    public function toInt32(): int
    {
        return 0;
    }

    public function toUint32(): int
    {
        return 0;
    }

    public function toJsString(): string
    {
        return 'undefined';
    }

    public function display(): string
    {
        return 'undefined';
    }
}
