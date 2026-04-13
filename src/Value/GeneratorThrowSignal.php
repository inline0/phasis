<?php

declare(strict_types=1);

namespace PhpJs\Value;

/**
 * Internal exception thrown into the Fiber by generator.throw(value).
 *
 * When the caller invokes generator.throw(value), we throw this signal
 * into the Fiber. If the generator body has a try/catch around the yield
 * point, the catch block can handle it. If not, the signal propagates
 * out and the generator becomes done.
 */
class GeneratorThrowSignal extends \Exception
{
    public function __construct(
        public readonly JsValue $jsValue,
    ) {
        parent::__construct('Generator throw signal');
    }
}
