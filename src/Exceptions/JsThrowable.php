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
    ) {
        parent::__construct($jsValue->toJsString());
    }
}
