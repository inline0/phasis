<?php

declare(strict_types=1);

namespace Phasis\Value;

/** An ordinary data object whose string keys never denote legacy native slots. */
final class JsDataObject extends JsObject
{
    protected static function isInternalSlot(string $key): bool
    {
        return false;
    }
}
