<?php

declare(strict_types=1);

namespace Phasis\Value;

use Phasis\Exceptions\TypeError;
use Phasis\Spec\TypeConversion;

/** Array iterator internal slots, kept separate from JavaScript properties. */
final class JsArrayIterator extends JsObject
{
    private int $nextIndex = 0;

    public function __construct(
        private ?JsObject $array,
        private readonly string $kind,
        private readonly JsFunction $intrinsicNext,
        JsObject $prototype,
    ) {
        parent::__construct($prototype);
    }

    /** Whether the cached next method is this iterator's unchanged intrinsic. */
    public function hasIntrinsicNext(JsValue $method): bool
    {
        return $method === $this->intrinsicNext
            && ($this->intrinsicNext->realm === null || $this->intrinsicNext->realm === \Phasis\Engine::getCurrentRealm());
    }

    /** Advance internal slots without allocating an unobservable iterator-result object. */
    public function nextValue(bool &$done): JsValue
    {
        $array = $this->array;
        if ($array === null) {
            $done = true;
            return JsUndefined::instance();
        }
        if ($array instanceof JsTypedArray) {
            if ($array->getBuffer()->isDetached()) {
                throw new TypeError('Cannot perform Array Iterator.prototype.next on a detached ArrayBuffer');
            }
            if ($array->isOutOfBounds()) {
                throw new TypeError('Cannot perform Array Iterator.prototype.next on an out-of-bounds TypedArray');
            }
            $length = $array->getLength();
        } else {
            $length = $array instanceof JsArray
                ? $array->getLength()
                : TypeConversion::toLength($array->get('length'));
        }
        $index = $this->nextIndex;
        if ($index >= $length) {
            $this->array = null;
            $done = true;
            return JsUndefined::instance();
        }
        // A getter can reenter this iterator, so advance before the element read.
        $this->nextIndex = $index + 1;
        $done = false;
        if ($this->kind === 'key') {
            return JsNumber::of((float) $index);
        }
        $value = $array->get((string) $index);
        return $this->kind === 'key+value'
            ? JsArray::fromArray([JsNumber::of((float) $index), $value])
            : $value;
    }
}
