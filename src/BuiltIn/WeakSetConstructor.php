<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;
use PhpJs\Value\JsWeakSet;
use PhpJs\Object\PropertyDescriptor;

/**
 * WeakSet constructor and prototype methods.
 *
 * new WeakSet() creates an empty weak set.
 * new WeakSet(iterable) creates a weak set from iterable values.
 */
class WeakSetConstructor
{
    public static function install(Environment $env): void
    {
        $proto = self::createPrototype();

        $constructor = JsFunction::fromCallable(
            'WeakSet',
            function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Constructor WeakSet requires \'new\'');
                }
                $effectiveProto = $proto;
                $newTarget = $this_->get('[[NewTarget]]');
                if ($newTarget instanceof JsFunction) {
                    $ntProto = $newTarget->get('prototype');
                    if ($ntProto instanceof JsObject) {
                        $effectiveProto = $ntProto;
                    }
                }
                $set = new JsWeakSet($effectiveProto);
                self::populateFromArgs($set, $args);
                return $set;
            },
        );
        $constructor->setConstructable();

        $constructor->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($constructor, true, false, true),
        );

        $env->defineVar('WeakSet', $constructor);
    }

    /**
     * Populate a WeakSet from constructor arguments (iterable of values).
     *
     * @param list<JsValue> $args
     */
    private static function populateFromArgs(JsWeakSet $set, array $args): void
    {
        if (empty($args)) {
            return;
        }

        $iterable = $args[0];
        if ($iterable instanceof JsUndefined || $iterable instanceof JsNull) {
            return;
        }

        // Per spec: get the add method BEFORE iterating
        $adder = $set->get('add');
        if (!$adder instanceof JsFunction) {
            throw new TypeError('WeakSet.prototype.add is not a function');
        }

        if ($iterable instanceof \PhpJs\Value\JsArray) {
            $length = $iterable->getLength();
            for ($i = 0; $i < $length; $i++) {
                $adder->call($set, [$iterable->get((string) $i)]);
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
            // Spec GetIterator: throw if iteratorMethod returns a non-object.
            throw new TypeError('Iterator method returned a non-object');
        }

        $nextMethod = $iterator->get('next');
        if (!$nextMethod instanceof JsFunction) {
            return;
        }

        while (true) {
            $result = $nextMethod->call($iterator, []);
            // Per spec IteratorStep: a non-object next() result is TypeError.
            if (!$result instanceof JsObject) {
                throw new TypeError('Iterator result is not an object');
            }
            if (TypeConversion::toBoolean($result->get('done'))) {
                break;
            }
            $nextValue = $result->get('value');
            // Per spec step 9f: Let status be Call(adder, set, nextValue).
            // Step 9g: If status is an abrupt completion, return IteratorClose(iter, status).
            try {
                $adder->call($set, [$nextValue]);
            } catch (\Throwable $e) {
                // IteratorClose: original throw takes precedence over
                // anything thrown by the `return` accessor or its call.
                try {
                    $returnMethod = $iterator->get('return');
                } catch (\Throwable) {
                    $returnMethod = null;
                }
                if ($returnMethod instanceof JsFunction) {
                    try {
                        $returnMethod->call($iterator, []);
                    } catch (\Throwable) {
                        // Per spec: ignore errors from the return method; re-throw original.
                    }
                }
                throw $e;
            }
        }
    }

    private static function createPrototype(): JsObject
    {
        $proto = new JsObject();

        $addFn = JsFunction::fromCallable('add', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsWeakSet) {
                throw new TypeError('Method WeakSet.prototype.add called on incompatible receiver');
            }
            $value = $args[0] ?? JsUndefined::instance();
            $this_->weakSetAdd($value);
            return $this_;
        }, 1);
        $proto->defineOwnProperty('add', PropertyDescriptor::data($addFn, true, false, true));

        $hasFn = JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsWeakSet) {
                throw new TypeError('Method WeakSet.prototype.has called on incompatible receiver');
            }
            $value = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->weakSetHas($value));
        }, 1);
        $proto->defineOwnProperty('has', PropertyDescriptor::data($hasFn, true, false, true));

        $deleteFn = JsFunction::fromCallable('delete', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsWeakSet) {
                throw new TypeError('Method WeakSet.prototype.delete called on incompatible receiver');
            }
            $value = $args[0] ?? JsUndefined::instance();
            return new JsBoolean($this_->weakSetDelete($value));
        }, 1);
        $proto->defineOwnProperty('delete', PropertyDescriptor::data($deleteFn, true, false, true));

        // Symbol.toStringTag = "WeakSet".
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('WeakSet'), false, false, true),
        );

        return $proto;
    }
}
