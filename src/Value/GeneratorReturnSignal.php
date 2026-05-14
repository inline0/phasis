<?php

declare(strict_types=1);

namespace Phasis\Value;

/**
 * Internal exception thrown into the Fiber by generator.return(value).
 *
 * When the caller invokes generator.return(value), we throw this signal
 * into the Fiber. If the generator body does not catch it (generators
 * cannot catch this in JS), the Fiber terminates and the generator
 * becomes done. If a finally block runs, it may yield additional values
 * before the signal propagates.
 */
class GeneratorReturnSignal extends \Exception
{
    public function __construct(
        public readonly JsValue $value,
        /**
         * True when the value has already been Awaited inside an async-generator
         * yield* delegation (per spec yield-star-return-then-getter-ticks).
         * Used by JsAsyncGenerator to skip a redundant outer Await when the
         * return propagation already performed the spec-required ticks.
         */
        public readonly bool $awaited = false,
    ) {
        parent::__construct('Generator return signal');
    }
}
