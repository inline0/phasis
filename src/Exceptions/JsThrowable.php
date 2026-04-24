<?php

declare(strict_types=1);

namespace PhpJs\Exceptions;

use PhpJs\Value\JsValue;

/**
 * A PHP exception that wraps a JS throw value.
 *
 * Used when a JS throw needs to propagate through PHP call frames
 * (e.g., through JsGenerator) while preserving the original JsValue.
 * When this exception reaches a JS try/catch via execTryStatement,
 * the original JsValue is extracted and placed into a Throw completion.
 */
class JsThrowable extends RuntimeError
{
    public function __construct(
        public readonly JsValue $jsValue,
        string $message = '',
    ) {
        // Use display() rather than toJsString() to avoid a secondary
        // exception when the thrown value is a Symbol or other primitive
        // whose String conversion is spec-disallowed. The message is for
        // PHP-side diagnostics only; JS code sees the raw $jsValue.
        parent::__construct($message !== '' ? $message : $jsValue->display());
    }
}
