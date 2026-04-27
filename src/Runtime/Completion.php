<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class Completion
{
    /**
     * Reusable normal+undefined+non-empty completion for the hot
     * `Completion::normal(JsUndefined::instance())` calls.
     */
    private static ?self $normalUndefinedNonEmpty = null;

    /**
     * Reusable normal+undefined+empty completion: returned by every
     * VariableDeclaration / EmptyStatement / DebuggerStatement and
     * every BlockStatement whose body produced no observable value.
     */
    private static ?self $normalUndefinedEmpty = null;

    public function __construct(
        public readonly CompletionType $type,
        public readonly JsValue $value,
        public readonly ?string $target = null,
        public readonly bool $empty = false,
    ) {
    }

    public static function normal(JsValue $value): self
    {
        if ($value instanceof JsUndefined) {
            return self::$normalUndefinedNonEmpty
                ??= new self(CompletionType::Normal, $value);
        }
        return new self(CompletionType::Normal, $value);
    }

    /** Singleton for the spec's `NormalCompletion(empty)`. */
    public static function normalEmpty(): self
    {
        return self::$normalUndefinedEmpty
            ??= new self(CompletionType::Normal, JsUndefined::instance(), empty: true);
    }

    public static function return(JsValue $value): self
    {
        return new self(CompletionType::Return, $value);
    }

    public static function throw(JsValue $value): self
    {
        return new self(CompletionType::Throw, $value);
    }

    public static function break(?string $label = null): self
    {
        return new self(CompletionType::Break, JsUndefined::instance(), $label, empty: true);
    }

    public static function continue(?string $label = null): self
    {
        return new self(CompletionType::Continue, JsUndefined::instance(), $label, empty: true);
    }

    /** Whether this completion is abrupt (anything other than Normal). */
    public function isAbrupt(): bool
    {
        return $this->type !== CompletionType::Normal;
    }
}
