<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Engine;
use Phasis\Value\JsObject;
use Phasis\Value\JsValue;

/** State retained by one contextified sandbox. */
final readonly class VmContext
{
    /** @param array{?JsObject, ?JsValue} $promiseIntrinsics */
    public function __construct(
        public Engine $engine,
        public JsObject $global,
        public JsObject $options,
        public array $promiseIntrinsics,
    ) {
    }
}
