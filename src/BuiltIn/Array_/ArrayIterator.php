<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Array_;

use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Value\JsArrayIterator;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsValue;

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

        $nextFn = JsFunction::fromCallable('next', static function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsArrayIterator) {
                throw new TypeError('Method Array Iterator.prototype.next called on incompatible receiver');
            }
            $done = false;
            $value = $this_->nextValue($done);
            $result = new JsObject();
            $result->defineOwnDataPropertyFast('value', $value);
            $result->defineOwnDataPropertyFast('done', JsBoolean::of($done));
            return $result;
        }, 0);
        $nextFn->setNonConstructable();
        $proto->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));
        $proto->setInternalProperty('ArrayIteratorNext', $nextFn);

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
        $intrinsicNext = $proto->getInternalProperty('ArrayIteratorNext');
        if (!$intrinsicNext instanceof JsFunction) {
            throw new \LogicException('Array iterator intrinsic is missing');
        }
        return new JsArrayIterator($array, $kind, $intrinsicNext, $proto);
    }
}
