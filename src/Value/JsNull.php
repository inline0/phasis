<?php

declare(strict_types=1);

namespace Phasis\Value;

class JsNull implements JsValue
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** Per spec, typeof null === "object". */
    public function typeof(): string
    {
        return 'object';
    }

    public function toBoolean(): bool
    {
        return false;
    }

    public function toNumber(): float
    {
        return 0.0;
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
        return 'null';
    }

    public function display(): string
    {
        return 'null';
    }
}
