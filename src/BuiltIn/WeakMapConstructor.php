<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;
use PhpJs\Value\JsWeakMap;
use PhpJs\Object\PropertyDescriptor;

/**
 * WeakMap constructor and prototype methods.
 *
 * new WeakMap() creates an empty weak map.
 * new WeakMap(iterable) creates a weak map from [key, value] pairs.
 */
class WeakMapConstructor
{
    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable(
            'WeakMap',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor WeakMap requires \'new\'');
                }
                $effectiveProto = $proto;
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsFunction) {
                    $ntProto = $newTarget->get('prototype');
                    if ($ntProto instanceof JsObject) {
                        $effectiveProto = $ntProto;
                    }
                }
                $map = new JsWeakMap($effectiveProto);
                self::populateFromArgs($map, $args);
                return $map;
            },
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );

        $env->defineVar('WeakMap', $constructor);
    }

    /**
     * Populate a WeakMap from constructor arguments (iterable of [key, value] pairs).
     *
     * @param list<JsValue> $args
     */
    /** Spec: CanBeHeldWeakly — JsObject or non-registered Symbol. */
    private static function canBeHeldWeakly(JsValue $key): bool
    {
        if ($key instanceof JsObject) {
            return true;
        }
        if ($key instanceof \PhpJs\Value\JsSymbol && !SymbolConstructor::isRegisteredSymbol($key)) {
            return true;
        }
        return false;
    }

    private static function populateFromArgs(JsWeakMap $map, array $args): void
    {
        if (empty($args)) {
            return;
        }

        $iterable = $args[0];
        if ($iterable instanceof JsUndefined || $iterable instanceof JsNull) {
            return;
        }

        // Per spec: get the set method BEFORE iterating
        $adder = $map->get('set');
        if (!$adder instanceof JsFunction) {
            throw new TypeError('WeakMap.prototype.set is not a function');
        }

        if ($iterable instanceof JsArray) {
            $length = $iterable->getLength();
            for ($i = 0; $i < $length; $i++) {
                $entry = $iterable->get((string) $i);
                if (!$entry instanceof JsObject) {
                    throw new TypeError('Iterator value is not an entry object');
                }
                $key = $entry->get('0');
                $value = $entry->get('1');
                $adder->call($map, [$key, $value]);
            }
            return;
        }

        if (!$iterable instanceof JsObject) {
            throw new TypeError('object is not iterable');
        }

        $iterSym = SymbolConstructor::iterator();
        $iteratorMethod = $iterable->getBySymbol($iterSym);
        if (!$iteratorMethod instanceof JsFunction) {
            throw new TypeError('object is not iterable');
        }

        $iterator = $iteratorMethod->call($iterable, []);
        if (!$iterator instanceof JsObject) {
            // Spec GetIterator: if iteratorMethod returns a non-object, throw.
            throw new TypeError('Iterator method returned a non-object');
        }

        $nextMethod = $iterator->get('next');
        if (!$nextMethod instanceof JsFunction) {
            return;
        }

        $returnMethod = $iterator->get('return');

        while (true) {
            $result = $nextMethod->call($iterator, []);
            // Per spec IteratorStep: a non-object next() result is TypeError.
            if (!$result instanceof JsObject) {
                throw new TypeError('Iterator result is not an object');
            }
            if (TypeConversion::toBoolean($result->get('done'))) {
                break;
            }
            $entry = $result->get('value');
            if (!$entry instanceof JsObject) {
                // Close the iterator, then throw.
                if ($returnMethod instanceof JsFunction) {
                    $returnMethod->call($iterator, []);
                }
                throw new TypeError('Iterator value is not an entry object');
            }
            try {
                $key = $entry->get('0');
                $value = $entry->get('1');
                $adder->call($map, [$key, $value]);
            } catch (\Throwable $e) {
                // Per spec: if getting key/value or set throws, close the iterator.
                if ($returnMethod instanceof JsFunction) {
                    try {
                        $returnMethod->call($iterator, []);
                    } catch (\Throwable) {
                        // Ignore errors from return method per spec.
                    }
                }
                throw $e;
            }
        }
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        $getFn = JsFunction::fromCallable('get', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsWeakMap) {
                throw new TypeError('Method WeakMap.prototype.get called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            return $this_->weakMapGet($key);
        }, 1);
        $proto->defineOwnProperty('get', PropertyDescriptor::data($getFn, true, false, true));

        $setFn = JsFunction::fromCallable('set', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsWeakMap) {
                throw new TypeError('Method WeakMap.prototype.set called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            $value = $args[1] ?? JsUndefined::instance();
            $this_->weakMapSet($key, $value);
            return $this_;
        }, 2);
        $proto->defineOwnProperty('set', PropertyDescriptor::data($setFn, true, false, true));

        $hasFn = JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsWeakMap) {
                throw new TypeError('Method WeakMap.prototype.has called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->weakMapHas($key));
        }, 1);
        $proto->defineOwnProperty('has', PropertyDescriptor::data($hasFn, true, false, true));

        $deleteFn = JsFunction::fromCallable('delete', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsWeakMap) {
                throw new TypeError('Method WeakMap.prototype.delete called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->weakMapDelete($key));
        }, 1);
        $proto->defineOwnProperty('delete', PropertyDescriptor::data($deleteFn, true, false, true));

        // WeakMap.prototype.getOrInsert(key, value)
        $getOrInsertFn = JsFunction::fromCallable('getOrInsert', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsWeakMap) {
                throw new TypeError('Method WeakMap.prototype.getOrInsert called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            if (!self::canBeHeldWeakly($key)) {
                throw new TypeError('Invalid value used as weak map key');
            }
            $value = $args[1] ?? JsUndefined::instance();
            if ($this_->weakMapHas($key)) {
                return $this_->weakMapGet($key);
            }
            $this_->weakMapSet($key, $value);
            return $value;
        }, 2);
        $proto->defineOwnProperty('getOrInsert', PropertyDescriptor::data($getOrInsertFn, true, false, true));

        // WeakMap.prototype.getOrInsertComputed(key, callbackfn)
        $getOrInsertComputedFn = JsFunction::fromCallable('getOrInsertComputed', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsWeakMap) {
                throw new TypeError('Method WeakMap.prototype.getOrInsertComputed called on incompatible receiver');
            }
            $key = $args[0] ?? JsUndefined::instance();
            if (!self::canBeHeldWeakly($key)) {
                throw new TypeError('Invalid value used as weak map key');
            }
            $callbackfn = $args[1] ?? JsUndefined::instance();
            if (!$callbackfn instanceof JsFunction) {
                throw new TypeError('callbackfn is not a function');
            }
            if ($this_->weakMapHas($key)) {
                return $this_->weakMapGet($key);
            }
            $value = $callbackfn->call(JsUndefined::instance(), [$key]);
            $this_->weakMapSet($key, $value);
            return $value;
        }, 2);
        $proto->defineOwnProperty('getOrInsertComputed', PropertyDescriptor::data($getOrInsertComputedFn, true, false, true));

        // Symbol.toStringTag = "WeakMap".
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('WeakMap'), false, false, true),
        );

        return $proto;
    }
}
