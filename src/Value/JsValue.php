<?php

declare(strict_types=1);

namespace PhpJs\Value;

interface JsValue
{
    /**
     * Returns the result of the typeof operator.
     *
     * One of: "undefined", "boolean", "number", "string", "object", "function", "symbol".
     */
    public function typeof(): string;

    /** ES spec ToBoolean. */
    public function toBoolean(): bool;

    /** ES spec ToNumber. */
    public function toNumber(): float;

    /** ES spec ToInt32. */
    public function toInt32(): int;

    /** ES spec ToUint32. */
    public function toUint32(): int;

    /**
     * ES spec ToString.
     *
     * Named toJsString to avoid collision with PHP's __toString.
     */
    public function toJsString(): string;

    /** Console output representation (e.g. what console.log prints). */
    public function display(): string;
}
