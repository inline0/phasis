<?php

declare(strict_types=1);

namespace Phasis\Value;

/**
 * Preserve native weak-key ownership without recursively destroying a value
 * that is itself another weak key. Native PHP teardown can exhaust its C stack
 * on long chains, so nested releases join the outer destructor's work queue.
 *
 * @internal
 */
final class WeakMapValue
{
    /** @var list<JsValue> */
    private static array $pending = [];

    private static bool $draining = false;

    public function __construct(public ?JsValue $value)
    {
    }

    public function __destruct()
    {
        if ($this->value === null) {
            return;
        }

        self::$pending[] = $this->value;
        $this->value = null;
        if (self::$draining) {
            return;
        }

        self::$draining = true;
        try {
            while (self::$pending !== []) {
                $value = array_pop(self::$pending);
                unset($value);
            }
        } finally {
            self::$draining = false;
        }
    }
}
