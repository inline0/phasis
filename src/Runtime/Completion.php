<?php

declare(strict_types=1);

namespace PhpJs\Runtime;

use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class Completion
{
    public function __construct(
        public readonly CompletionType $type,
        public readonly JsValue $value,
        public readonly ?string $target = null,
        public readonly bool $empty = false,
    ) {
    }

    public static function normal(JsValue $value): self
    {
        return new self(CompletionType::Normal, $value);
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
