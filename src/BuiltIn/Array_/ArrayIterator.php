<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Array_;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * ArrayConstructor trait part: ArrayIterator. Composed into
 * ArrayConstructor via `use Array_\ArrayIterator;`. `self::`/`$this->`
 * resolve into the composing class.
 */
trait ArrayIterator
{
    /**
     * Build a fresh %ArrayIteratorPrototype% intrinsic for the current realm.
     *
     * Its [[Prototype]] is %IteratorPrototype%. Each Engine instance owns a
     * distinct prototype object so cross-realm iterators retain identity
     * against their owning realm's intrinsic (see iterator-next-with-detached
     * in test262 staging/sm/TypedArray).
     */
    public static function buildArrayIteratorPrototype(?JsObject $iteratorPrototype = null): JsObject
    {
        $proto = new JsObject($iteratorPrototype);

        // next method on the prototype. Validates internal slots via hidden property.
        $nextFn = JsFunction::fromCallable('next', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new \Phasis\Exceptions\TypeError(
                    'Method Array Iterator.prototype.next called on incompatible receiver',
                );
            }
            $slotDesc = $this_->getOwnPropertyDescriptor('[[ArrayIteratorData]]');
            if ($slotDesc === null) {
                throw new \Phasis\Exceptions\TypeError(
                    'Method Array Iterator.prototype.next called on incompatible receiver',
                );
            }
            $data = $slotDesc->value;
            $isExhausted = $data instanceof JsObject
                && $data->get('exhausted') instanceof JsBoolean
                && $data->get('exhausted')->value;
            if (!$data instanceof JsObject || $isExhausted) {
                $result = new JsObject();
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
                return $result;
            }
            $array = $data->get('array');
            if (!$array instanceof JsObject) {
                $result = new JsObject();
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
                return $result;
            }
            $kind = ($data->get('kind') instanceof JsString) ? $data->get('kind')->value : 'value';
            $indexVal = $data->get('index');
            $index = ($indexVal instanceof JsNumber) ? (int) $indexVal->value : 0;

            // Per spec 23.1.5.2.1 step 8: if the array is a TypedArray
            // with a detached buffer, throw TypeError. For resizable buffers
            // also throw if the view is now out of bounds.
            if ($array instanceof \Phasis\Value\JsTypedArray) {
                $buffer = $array->getBuffer();
                if ($buffer->isDetached()) {
                    throw new \Phasis\Exceptions\TypeError(
                        'Cannot perform Array Iterator.prototype.next on a detached ArrayBuffer',
                    );
                }
                if ($array->isOutOfBounds()) {
                    throw new \Phasis\Exceptions\TypeError(
                        'Cannot perform Array Iterator.prototype.next on an out-of-bounds TypedArray',
                    );
                }
            }

            // Re-read length each time for mutable iteration. Per spec
            // 23.2.5.1.1 TypedArray iterators source [[ArrayLength]] from
            // the internal slot, NOT the observable `length` property, so
            // a defineProperty override on length cannot fake the
            // iteration count.
            if ($array instanceof \Phasis\Value\JsTypedArray) {
                $len = $array->getLength();
            } else {
                $len = self::getLen($array);
            }

            $result = new JsObject();
            if ($index < $len) {
                $data->set('index', JsNumber::of((float) ($index + 1)));
                $key = JsNumber::of((float) $index);
                $value = $array->get((string) $index);
                $result->set('done', new JsBoolean(false));
                $result->set('value', match ($kind) {
                    'key' => $key,
                    'value' => $value,
                    'key+value' => JsArray::fromArray([$key, $value]),
                    default => $value,
                });
            } else {
                // Mark as exhausted so subsequent calls stay done.
                $data->set('exhausted', new JsBoolean(true));
                $result->set('value', JsUndefined::instance());
                $result->set('done', new JsBoolean(true));
            }
            return $result;
        }, 0);
        $nextFn->setNonConstructable();
        $proto->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));

        // Symbol.toStringTag = "Array Iterator" per spec 23.1.5.2.2.
        $proto->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Array Iterator'), false, false, true),
        );

        return $proto;
    }

    /** Public entry point for creating array iterators (used by JsArray Symbol.iterator). */
    public static function createArrayIteratorFromSymbol(JsObject $array, string $kind): JsObject
    {
        return self::createArrayIterator($array, $kind);
    }

    /** Create an iterator object for keys, values, or entries. */
    private static function createArrayIterator(JsObject $array, string $kind): JsObject
    {
        // Look up %ArrayIteratorPrototype% on the active realm's globalEnv.
        // Each Engine stores its own prototype; if no realm env is available
        // (very early bootstrap or detached call) build a fresh one rather
        // than reuse a stale process-global cache.
        $proto = null;
        $interp = \Phasis\Engine::getCurrentInterpreter();
        if ($interp !== null) {
            $env = $interp->getGlobalEnv();
            if ($env->has('__ArrayIteratorPrototype__')) {
                $stashed = $env->get('__ArrayIteratorPrototype__');
                if ($stashed instanceof JsObject) {
                    $proto = $stashed;
                }
            }
        }
        if ($proto === null) {
            $iteratorProto = null;
            if ($interp !== null) {
                $env = $interp->getGlobalEnv();
                if ($env->has('__IteratorPrototype__')) {
                    $stashedIter = $env->get('__IteratorPrototype__');
                    if ($stashedIter instanceof JsObject) {
                        $iteratorProto = $stashedIter;
                    }
                }
            }
            $proto = self::buildArrayIteratorPrototype($iteratorProto);
        }
        $iterator = new JsObject($proto);

        // Store iteration state as internal data.
        $data = new JsObject();
        $data->set('array', $array);
        $data->set('kind', new JsString($kind));
        $data->set('index', JsNumber::of(0.0));
        $iterator->defineOwnProperty(
            '[[ArrayIteratorData]]',
            PropertyDescriptor::data($data, false, false, false),
        );

        return $iterator;
    }
}
