<?php

declare(strict_types=1);

namespace PhpJs\Module;

use PhpJs\Value\JsObject;

/**
 * Tracks a loaded module: its resolved path and namespace object.
 */
class ModuleRecord
{
    public function __construct(
        public readonly string $path,
        public readonly JsObject $namespace,
    ) {
    }
}
