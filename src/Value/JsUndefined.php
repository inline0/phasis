<?php

declare(strict_types=1);

namespace Phasis\Value;

class JsUndefined implements JsValue
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
