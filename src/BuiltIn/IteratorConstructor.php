<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsArray;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNull;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsSymbol;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class IteratorConstructor
{
    private static ?JsObject $iteratorHelperPrototype = null;
    private static ?JsObject $wrapForValidIteratorPrototype = null;

    public static function install(Environment $env): void
    {
        $iteratorPrototype = $env->has('__IteratorPrototype__')
            ? $env->get('__IteratorPrototype__')
            : null;
        if (!$iteratorPrototype instanceof JsObject) {
            return;
        }

        $iteratorCtor = JsFunction::fromCallable(
            'Iterator',
            function (JsValue $this_, array $args) use (&$iteratorCtor): JsValue {
                if (!$this_ instanceof JsObject || $this_->get('[[NewTarget]]') instanceof JsUndefined) {
                    throw new TypeError('Iterator is not callable');
                }
                $ntDesc = $this_->getOwnPropertyDescriptor('[[NewTarget]]');
                if ($ntDesc !== null && $ntDesc->value === $iteratorCtor) {
                    throw new TypeError('Abstract class Iterator not directly constructable');
                }
                return $this_;
            },
        );
        $iteratorCtor->setConstructable();

        $iteratorCtor->defineOwnProperty('prototype', PropertyDescriptor::data(
            $iteratorPrototype,
            false,
            false,
            false,
        ));

        // constructor: accessor with SetterThatIgnoresPrototypeProperties.
        $ctorGetter = JsFunction::fromCallable('get constructor', function () use (&$iteratorCtor): JsValue {
            return $iteratorCtor;
        }, 0);
        $ctorSetter = JsFunction::fromCallable('set constructor', function (JsValue $this_, array $args) use ($iteratorPrototype): JsValue {
            $v = $args[0] ?? JsUndefined::instance();
            self::setterThatIgnores($this_, $iteratorPrototype, 'constructor', $v);
            return JsUndefined::instance();
        }, 1);
        $iteratorPrototype->defineOwnProperty('constructor', PropertyDescriptor::accessor(
            get: $ctorGetter,
            set: $ctorSetter,
            enumerable: false,
            configurable: true,
        ));

        // Symbol.toStringTag: accessor with SetterThatIgnoresPrototypeProperties.
        $tagGetter = JsFunction::fromCallable('get [Symbol.toStringTag]', function (): JsValue {
            return new JsString('Iterator');
        }, 0);
        $tagSetter = JsFunction::fromCallable('set [Symbol.toStringTag]', function (JsValue $this_, array $args) use ($iteratorPrototype): JsValue {
            $v = $args[0] ?? JsUndefined::instance();
            self::setterThatIgnores($this_, $iteratorPrototype, SymbolConstructor::toStringTag(), $v);
            return JsUndefined::instance();
        }, 1);
        $iteratorPrototype->definePropertyBySymbol(SymbolConstructor::toStringTag(), PropertyDescriptor::accessor(
            get: $tagGetter,
            set: $tagSetter,
            enumerable: false,
            configurable: true,
        ));

        self::$iteratorHelperPrototype = new JsObject($iteratorPrototype);
        self::$iteratorHelperPrototype->definePropertyBySymbol(
            SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('Iterator Helper'), false, false, true),
        );
        self::$wrapForValidIteratorPrototype = new JsObject($iteratorPrototype);

        self::installMethod($iteratorPrototype, 'map', self::mapMethod(), 1);
        self::installMethod($iteratorPrototype, 'filter', self::filterMethod(), 1);
        self::installMethod($iteratorPrototype, 'take', self::takeMethod(), 1);
        self::installMethod($iteratorPrototype, 'drop', self::dropMethod(), 1);
        self::installMethod($iteratorPrototype, 'flatMap', self::flatMapMethod(), 1);
        self::installMethod($iteratorPrototype, 'reduce', self::reduceMethod(), 1);
        self::installMethod($iteratorPrototype, 'toArray', self::toArrayMethod(), 0);
        self::installMethod($iteratorPrototype, 'forEach', self::forEachMethod(), 1);
        self::installMethod($iteratorPrototype, 'some', self::someMethod(), 1);
        self::installMethod($iteratorPrototype, 'every', self::everyMethod(), 1);
        self::installMethod($iteratorPrototype, 'find', self::findMethod(), 1);

        $fromFn = JsFunction::fromCallable('from', self::fromMethod($iteratorPrototype), 1);
        $fromFn->setNonConstructable();
        $iteratorCtor->defineOwnProperty('from', PropertyDescriptor::data($fromFn, true, false, true));

        $concatFn = JsFunction::fromCallable('concat', self::concatMethod(), 0);
        $concatFn->setNonConstructable();
        $iteratorCtor->defineOwnProperty('concat', PropertyDescriptor::data($concatFn, true, false, true));

        // %IteratorPrototype% [@@dispose] per spec sec-%iteratorprototype%-@@dispose.
        $disposeFn = JsFunction::fromCallable('[Symbol.dispose]', function (JsValue $this_, array $args): JsValue {
            $returnMethod = ($this_ instanceof JsObject) ? $this_->get('return') : JsUndefined::instance();
            if ($returnMethod instanceof JsFunction) {
                $returnMethod->call($this_, []);
            }
            return JsUndefined::instance();
        }, 0);
        $disposeFn->setNonConstructable();
        $iteratorPrototype->definePropertyBySymbol(
            SymbolConstructor::dispose(),
            PropertyDescriptor::data($disposeFn, true, false, true),
        );

        $env->defineVar('Iterator', $iteratorCtor);
    }

    private static function setterThatIgnores(JsValue $this_, JsObject $home, string|JsSymbol $p, JsValue $v): void
    {
        if (!$this_ instanceof JsObject) {
            throw new TypeError('Cannot set property on a non-object');
        }
        if ($this_ === $home) {
            throw new TypeError('Cannot assign to read only property');
        }
        $desc = $p instanceof JsSymbol ? $this_->getSymbolPropertyDescriptor($p) : $this_->getOwnPropertyDescriptor($p);
        if ($desc === null) {
            if ($p instanceof JsSymbol) {
                $this_->definePropertyBySymbol($p, PropertyDescriptor::data($v, true, true, true));
            } else {
                $this_->defineOwnProperty($p, PropertyDescriptor::data($v, true, true, true));
            }
            return;
        }
        if ($p instanceof JsSymbol) {
            $this_->setBySymbol($p, $v, true);
        } else {
            $this_->set($p, $v, true);
        }
    }

    private static function installMethod(JsObject $proto, string $name, \Closure $cb, int $length): void
    {
        $fn = JsFunction::fromCallable($name, $cb, $length);
        $fn->setNonConstructable();
        $proto->defineOwnProperty($name, PropertyDescriptor::data($fn, true, false, true));
    }

    /** @return array{JsObject, JsValue} */
    private static function getIteratorDirect(JsObject $obj): array
    {
        return [$obj, $obj->get('next')];
    }

    /**
     * Create an Iterator helper.
     *
     * @param ?\Closure $onReturn Optional callback invoked before closing
     *     the underlying iterator; used by flatMap to also forward return()
     *     to the currently-active inner iterator.
     */
    private static function createIteratorHelper(
        JsObject $ui,
        JsValue $un,
        \Closure $step,
        ?\Closure $onReturn = null,
    ): JsObject {
        $helper = new JsObject(self::$iteratorHelperPrototype);
        $done = false;
        $alive = true;
        $running = false;

        $nextFn = JsFunction::fromCallable('next', function (JsValue $this_, array $args) use (&$done, &$alive, &$running, $step): JsValue {
            if ($running) {
                throw new TypeError('Cannot call next on a running iterator helper');
            }
            if (!$alive || $done) {
                return self::iterResult(JsUndefined::instance(), true);
            }
            $running = true;
            try {
                $result = $step($done, $alive);
            } catch (\Throwable $e) {
                $done = true;
                $alive = false;
                $running = false;
                throw $e;
            }
            $running = false;
            return $result;
        }, 0);

        $returnFn = JsFunction::fromCallable(
            'return',
            function (JsValue $this_, array $args) use ($ui, &$done, &$alive, &$running, $onReturn): JsValue {
                if ($running) {
                    throw new TypeError('Cannot call return on a running iterator helper');
                }
                $alive = false;
                if (!$done) {
                    $done = true;
                    // Forward return() to any active inner iterator (e.g. flatMap).
                    if ($onReturn !== null) {
                        $onReturn();
                    }
                    $returnMethod = $ui->get('return');
                    if ($returnMethod instanceof JsFunction) {
                        return $returnMethod->call($ui, []);
                    }
                }
                return self::iterResult(JsUndefined::instance(), true);
            },
            0,
        );

        $helper->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));
        $helper->defineOwnProperty('return', PropertyDescriptor::data($returnFn, true, false, true));
        return $helper;
    }

    private static function iteratorStep(JsObject $it, JsValue $nx, bool &$done): array
    {
        if ($done) {
            return [JsUndefined::instance(), true];
        }
        if (!$nx instanceof JsFunction) {
            $done = true;
            throw new TypeError('Iterator next is not a function');
        }
        $result = $nx->call($it, []);
        if (!$result instanceof JsObject) {
            $done = true;
            throw new TypeError('Iterator result is not an object');
        }
        if (TypeConversion::toBoolean($result->get('done'))) {
            $done = true;
            return [JsUndefined::instance(), true];
        }
        return [$result->get('value'), false];
    }

    private static function iterResult(JsValue $value, bool $done): JsObject
    {
        $r = new JsObject();
        $r->set('value', $value);
        $r->set('done', new JsBoolean($done));
        return $r;
    }

    private static function closeIterator(JsObject $it, bool $suppress = false): void
    {
        $rm = $it->get('return');
        if ($rm instanceof JsFunction) {
            if ($suppress) {
                try {
                    $rm->call($it, []);
                } catch (\Throwable) {
                }
            } else {
                $rm->call($it, []);
            }
        }
    }

    private static function toNonNegInt(JsValue $arg): float
    {
        $n = TypeConversion::toNumber($arg);
        if (is_nan($n)) {
            throw new \PhpJs\Exceptions\RangeError('Invalid limit');
        }
        if ($n === INF) {
            return INF;
        }
        $i = $n >= 0 ? floor($n) : ceil($n);
        if ($i < 0) {
            throw new \PhpJs\Exceptions\RangeError('Invalid limit');
        }
        return $i;
    }

    // Lazy methods

    private static function mapMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.map called on non-object');
            }
            $mapper = $args[0] ?? JsUndefined::instance();
            if (!$mapper instanceof JsFunction) {
                self::closeIterator($this_, true);
                throw new TypeError('Iterator.prototype.map callback is not a function');
            }
            [$itObj, $nextMethod] = self::getIteratorDirect($this_);
            $counter = 0;
            $done = false;
            return self::createIteratorHelper(
                $itObj,
                $nextMethod,
                function (bool &$done, bool &$alive) use ($itObj, &$nextMethod, $mapper, &$counter): JsObject {
                    [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                    if ($isDone) {
                        $alive = false;
                        return self::iterResult(JsUndefined::instance(), true);
                    }
                    try {
                        $mapped = $mapper->call(JsUndefined::instance(), [$value, new JsNumber((float) $counter)]);
                    } catch (\Throwable $e) {
                        self::closeIterator($itObj, true);
                        throw $e;
                    }
                    $counter++;
                    return self::iterResult($mapped, false);
                }
            );
        };
    }

    private static function filterMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.filter called on non-object');
            }
            $predicate = $args[0] ?? JsUndefined::instance();
            if (!$predicate instanceof JsFunction) {
                self::closeIterator($this_, true);
                throw new TypeError('Iterator.prototype.filter callback is not a function');
            }
            [$itObj, $nextMethod] = self::getIteratorDirect($this_);
            $counter = 0;
            $done = false;
            return self::createIteratorHelper(
                $itObj,
                $nextMethod,
                function (bool &$done, bool &$alive) use ($itObj, &$nextMethod, $predicate, &$counter): JsObject {
                    while (true) {
                        [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                        if ($isDone) {
                            $alive = false;
                            return self::iterResult(JsUndefined::instance(), true);
                        }
                        try {
                            $selected = $predicate->call(JsUndefined::instance(), [$value, new JsNumber((float) $counter)]);
                        } catch (\Throwable $e) {
                            self::closeIterator($itObj, true);
                            throw $e;
                        }
                        $counter++;
                        if (TypeConversion::toBoolean($selected)) {
                            return self::iterResult($value, false);
                        }
                    }
                }
            );
        };
    }

    private static function takeMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.take called on non-object');
            }
            $limitArg = $args[0] ?? JsUndefined::instance();
            try {
                $numLimit = self::toNonNegInt($limitArg);
            } catch (\Throwable $e) {
                self::closeIterator($this_, true);
                throw $e;
            }
            [$itObj, $nextMethod] = self::getIteratorDirect($this_);
            $remaining = $numLimit;
            $done = false;
            return self::createIteratorHelper(
                $itObj,
                $nextMethod,
                function (bool &$done, bool &$alive) use ($itObj, &$nextMethod, &$remaining): JsObject {
                    if ($remaining <= 0) {
                        $done = true;
                        $alive = false;
                        self::closeIterator($itObj);
                        return self::iterResult(JsUndefined::instance(), true);
                    }
                    [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                    if ($isDone) {
                        $alive = false;
                        return self::iterResult(JsUndefined::instance(), true);
                    }
                    $remaining--;
                    return self::iterResult($value, false);
                }
            );
        };
    }

    private static function dropMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.drop called on non-object');
            }
            $limitArg = $args[0] ?? JsUndefined::instance();
            try {
                $numLimit = self::toNonNegInt($limitArg);
            } catch (\Throwable $e) {
                self::closeIterator($this_, true);
                throw $e;
            }
            [$itObj, $nextMethod] = self::getIteratorDirect($this_);
            $toDrop = $numLimit;
            $dropped = false;
            $done = false;
            return self::createIteratorHelper(
                $itObj,
                $nextMethod,
                function (bool &$done, bool &$alive) use ($itObj, &$nextMethod, &$toDrop, &$dropped): JsObject {
                    while (!$dropped) {
                        if ($toDrop <= 0) {
                            $dropped = true;
                            break;
                        }
                        [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                        if ($isDone) {
                            $alive = false;
                            return self::iterResult(JsUndefined::instance(), true);
                        }
                        $toDrop--;
                    }
                    [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                    if ($isDone) {
                        $alive = false;
                        return self::iterResult(JsUndefined::instance(), true);
                    }
                    return self::iterResult($value, false);
                }
            );
        };
    }

    private static function flatMapMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.flatMap called on non-object');
            }
            $mapper = $args[0] ?? JsUndefined::instance();
            if (!$mapper instanceof JsFunction) {
                self::closeIterator($this_, true);
                throw new TypeError('Iterator.prototype.flatMap callback is not a function');
            }
            [$itObj, $nextMethod] = self::getIteratorDirect($this_);
            $counter = 0;
            $done = false;
            $innerIt = null;
            $innerNx = null;
            $innerDone = true;

            return self::createIteratorHelper(
                $itObj,
                $nextMethod,
                function (bool &$done, bool &$alive) use ($itObj, &$nextMethod, $mapper, &$counter, &$innerIt, &$innerNx, &$innerDone): JsObject {
                    while (true) {
                        if ($innerIt !== null && !$innerDone) {
                            // Per spec Iterator Helpers 2.1.5.7 step 1.i: if
                            // any inner step (innerNext, innerComplete = .done
                            // getter, or .value getter) throws, the outer
                            // iterator must be closed before propagating.
                            try {
                                $innerResult = $innerNx->call($innerIt, []);
                                if (!$innerResult instanceof JsObject) {
                                    $innerDone = true;
                                    throw new TypeError('Iterator result is not an object');
                                }
                                $innerIsDone = TypeConversion::toBoolean($innerResult->get('done'));
                                if (!$innerIsDone) {
                                    $innerValue = $innerResult->get('value');
                                }
                            } catch (\Throwable $e) {
                                self::closeIterator($itObj, true);
                                throw $e;
                            }
                            if (!$innerIsDone) {
                                return self::iterResult($innerValue, false);
                            }
                            $innerIt = null;
                            $innerNx = null;
                            $innerDone = true;
                        }

                        [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                        if ($isDone) {
                            $alive = false;
                            return self::iterResult(JsUndefined::instance(), true);
                        }

                        try {
                            $mapped = $mapper->call(JsUndefined::instance(), [$value, new JsNumber((float) $counter)]);
                        } catch (\Throwable $e) {
                            self::closeIterator($itObj, true);
                            throw $e;
                        }
                        $counter++;

                        if ($mapped instanceof JsString) {
                            self::closeIterator($itObj, true);
                            throw new TypeError('flatMap: strings are not flattenable');
                        }
                        if (!$mapped instanceof JsObject) {
                            self::closeIterator($itObj, true);
                            throw new TypeError('flatMap: mapper must return an object');
                        }

                        $iterSym = SymbolConstructor::iterator();
                        $iterMethod = $mapped->getBySymbol($iterSym);

                        if (!($iterMethod instanceof JsUndefined) && !($iterMethod instanceof JsNull)) {
                            if (!$iterMethod instanceof JsFunction) {
                                self::closeIterator($itObj, true);
                                throw new TypeError('Symbol.iterator is not a function');
                            }
                            $innerObj = $iterMethod->call($mapped, []);
                            if (!$innerObj instanceof JsObject) {
                                self::closeIterator($itObj, true);
                                throw new TypeError('Symbol.iterator result is not an object');
                            }
                            $innerIt = $innerObj;
                        } else {
                            $innerIt = $mapped;
                        }

                        $nxMethod = $innerIt->get('next');
                        if (!$nxMethod instanceof JsFunction) {
                            self::closeIterator($itObj, true);
                            throw new TypeError('flatMap inner has no next method');
                        }
                        $innerNx = $nxMethod;
                        $innerDone = false;
                    }
                },
                // onReturn: close the currently-active inner iterator first.
                function () use (&$innerIt, &$innerDone): void {
                    if ($innerIt !== null && !$innerDone) {
                        $ii = $innerIt;
                        $innerIt = null;
                        $innerDone = true;
                        self::closeIterator($ii, true);
                    }
                },
            );
        };
    }

    // Eager methods

    private static function validateEager(JsValue $this_, string $name, JsValue $fn): array
    {
        if (!$this_ instanceof JsObject) {
            throw new TypeError("Iterator.prototype.{$name} called on non-object");
        }
        if (!$fn instanceof JsFunction) {
            self::closeIterator($this_, true);
            throw new TypeError("Iterator.prototype.{$name} callback is not a function");
        }
        [$itObj, $nextMethod] = self::getIteratorDirect($this_);
        if (!$nextMethod instanceof JsFunction) {
            throw new TypeError('Iterator next is not a function');
        }
        return [$itObj, $nextMethod];
    }

    private static function reduceMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $reducer = $args[0] ?? JsUndefined::instance();
            [$itObj, $nextMethod] = self::validateEager($this_, 'reduce', $reducer);
            $hasInit = count($args) >= 2;
            $acc = $hasInit ? $args[1] : null;
            $done = false;
            $counter = 0;
            if (!$hasInit) {
                [$first, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    throw new TypeError('Reduce of empty iterator with no initial value');
                }
                $acc = $first;
                $counter = 1;
            }
            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    break;
                }
                try {
                    $acc = $reducer->call(JsUndefined::instance(), [$acc, $value, new JsNumber((float) $counter)]);
                } catch (\Throwable $e) {
                    self::closeIterator($itObj, true);
                    throw $e;
                }
                $counter++;
            }
            return $acc;
        };
    }

    private static function toArrayMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject) {
                throw new TypeError('Iterator.prototype.toArray called on non-object');
            }
            [$itObj, $nextMethod] = self::getIteratorDirect($this_);
            if (!$nextMethod instanceof JsFunction) {
                throw new TypeError('Iterator next is not a function');
            }
            $done = false;
            $items = [];
            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    break;
                }
                $items[] = $value;
            }
            return JsArray::fromArray($items);
        };
    }

    private static function forEachMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $fn = $args[0] ?? JsUndefined::instance();
            [$itObj, $nextMethod] = self::validateEager($this_, 'forEach', $fn);
            $done = false;
            $counter = 0;
            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    break;
                }
                try {
                    $fn->call(JsUndefined::instance(), [$value, new JsNumber((float) $counter)]);
                } catch (\Throwable $e) {
                    self::closeIterator($itObj, true);
                    throw $e;
                }
                $counter++;
            }
            return JsUndefined::instance();
        };
    }

    private static function someMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $predicate = $args[0] ?? JsUndefined::instance();
            [$itObj, $nextMethod] = self::validateEager($this_, 'some', $predicate);
            $done = false;
            $counter = 0;
            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    return new JsBoolean(false);
                }
                try {
                    $result = $predicate->call(JsUndefined::instance(), [$value, new JsNumber((float) $counter)]);
                } catch (\Throwable $e) {
                    self::closeIterator($itObj, true);
                    throw $e;
                }
                $counter++;
                if (TypeConversion::toBoolean($result)) {
                    self::closeIterator($itObj);
                    return new JsBoolean(true);
                }
            }
        };
    }

    private static function everyMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $predicate = $args[0] ?? JsUndefined::instance();
            [$itObj, $nextMethod] = self::validateEager($this_, 'every', $predicate);
            $done = false;
            $counter = 0;
            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    return new JsBoolean(true);
                }
                try {
                    $result = $predicate->call(JsUndefined::instance(), [$value, new JsNumber((float) $counter)]);
                } catch (\Throwable $e) {
                    self::closeIterator($itObj, true);
                    throw $e;
                }
                $counter++;
                if (!TypeConversion::toBoolean($result)) {
                    self::closeIterator($itObj);
                    return new JsBoolean(false);
                }
            }
        };
    }

    private static function findMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $predicate = $args[0] ?? JsUndefined::instance();
            [$itObj, $nextMethod] = self::validateEager($this_, 'find', $predicate);
            $done = false;
            $counter = 0;
            while (true) {
                [$value, $isDone] = self::iteratorStep($itObj, $nextMethod, $done);
                if ($isDone) {
                    return JsUndefined::instance();
                }
                try {
                    $result = $predicate->call(JsUndefined::instance(), [$value, new JsNumber((float) $counter)]);
                } catch (\Throwable $e) {
                    self::closeIterator($itObj, true);
                    throw $e;
                }
                $counter++;
                if (TypeConversion::toBoolean($result)) {
                    self::closeIterator($itObj);
                    return $value;
                }
            }
        };
    }

    // Iterator.from

    private static function fromMethod(JsObject $iteratorPrototype): \Closure
    {
        return function (JsValue $this_, array $args) use ($iteratorPrototype): JsValue {
            $obj = $args[0] ?? JsUndefined::instance();

            // Per Iterator.from spec: GetIteratorFlattenable(O, iterate-string-primitives).
            // For String primitives, @@iterator is looked up on String.prototype
            // with the primitive as the receiver (so getters observe typeof this === 'string').
            // For other primitives (Number, etc.), throw TypeError.
            $iterSym = SymbolConstructor::iterator();
            if ($obj instanceof JsString) {
                $stringProto = \PhpJs\Value\JsString::getStringPrototype();
                $iterMethod = $stringProto !== null
                    ? $stringProto->getBySymbolWithReceiver($iterSym, $obj)
                    : JsUndefined::instance();
                $receiver = $obj;
            } elseif (!$obj instanceof JsObject) {
                throw new TypeError('Iterator.from requires an object');
            } else {
                $iterMethod = $obj->getBySymbol($iterSym);
                $receiver = $obj;
            }

            $iterator = null;
            if (!($iterMethod instanceof JsUndefined) && !($iterMethod instanceof JsNull)) {
                if (!$iterMethod instanceof JsFunction) {
                    throw new TypeError('Symbol.iterator is not a function');
                }
                $iterator = $iterMethod->call($receiver, []);
                if (!$iterator instanceof JsObject) {
                    throw new TypeError('Symbol.iterator result is not an object');
                }
            } else {
                // No @@iterator: use the input directly. For String primitives,
                // coerce to a String wrapper per GetIteratorFlattenable step 5.
                if ($obj instanceof JsString) {
                    $obj = TypeConversion::toObject($obj);
                }
                $iterator = $obj;
            }

            // Per Iterator.from spec, GetIteratorDirect runs before the
            // OrdinaryHasInstance(%Iterator%, ...) check. createWrapForValidIterator
            // reads `next` first; isIteratorInstance walks the prototype
            // chain afterwards, observing the spec-mandated order including
            // the proxy's getPrototypeOf trap firing after the next read.
            $wrapped = self::createWrapForValidIterator($iterator);
            if (self::isIteratorInstance($iterator, $iteratorPrototype)) {
                return $iterator;
            }
            return $wrapped;
        };
    }

    // Iterator.concat

    private static function concatMethod(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            // Step 1-2: Validate all items eagerly and collect {openMethod, iterable} records.
            $iterables = [];
            foreach ($args as $item) {
                if (!$item instanceof JsObject) {
                    throw new TypeError('Iterator.concat requires object arguments');
                }
                $iterSym = SymbolConstructor::iterator();
                $method = $item->getBySymbol($iterSym);
                if ($method instanceof JsUndefined || $method instanceof JsNull) {
                    throw new TypeError('Iterator.concat requires iterable arguments');
                }
                if (!$method instanceof JsFunction) {
                    throw new TypeError('Symbol.iterator is not a function');
                }
                $iterables[] = ['openMethod' => $method, 'iterable' => $item];
            }

            // Step 3-6: Create an IteratorHelper that lazily iterates through each iterable.
            $helper = new JsObject(self::$iteratorHelperPrototype);
            $done = false;
            $started = false;
            $running = false;
            $iterableIndex = 0;
            $innerIt = null;

            $nextFn = JsFunction::fromCallable(
                'next',
                function (
                    JsValue $thisVal,
                    array $nextArgs
                ) use (
                    &$done,
                    &$started,
                    &$running,
                    &$iterables,
                    &$iterableIndex,
                    &$innerIt,
                ): JsValue {
                    if ($running) {
                        throw new TypeError('Cannot call next on a running iterator helper');
                    }
                    if ($done) {
                        return self::iterResult(JsUndefined::instance(), true);
                    }
                    $running = true;
                    $started = true;
                    try {
                        while (true) {
                            // If we have an active inner iterator, try to get next value from it.
                            if ($innerIt !== null) {
                                $nextMethod = $innerIt->get('next');
                                if (!$nextMethod instanceof JsFunction) {
                                    $done = true;
                                    throw new TypeError('Iterator next is not a function');
                                }
                                $result = $nextMethod->call($innerIt, []);
                                if (!$result instanceof JsObject) {
                                    $done = true;
                                    throw new TypeError('Iterator result is not an object');
                                }
                                // Check done. If the done getter throws, that propagates.
                                if (!TypeConversion::toBoolean($result->get('done'))) {
                                    // Not done: yield the raw result object from the inner iterator.
                                    // Per spec, GeneratorYield passes the iterator result directly.
                                    $running = false;
                                    return $result;
                                }
                                // Inner iterator exhausted. Move to next iterable.
                                $innerIt = null;
                            }

                            // Move to next iterable in the list.
                            if ($iterableIndex >= count($iterables)) {
                                $done = true;
                                $running = false;
                                return self::iterResult(JsUndefined::instance(), true);
                            }

                            $record = $iterables[$iterableIndex];
                            $iterableIndex++;

                            // Call the open method to get an iterator.
                            $iter = $record['openMethod']->call($record['iterable'], []);
                            if (!$iter instanceof JsObject) {
                                $done = true;
                                throw new TypeError('Symbol.iterator result is not an object');
                            }
                            $innerIt = $iter;
                        }
                    } catch (\Throwable $e) {
                        $done = true;
                        $innerIt = null;
                        $running = false;
                        throw $e;
                    }
                },
                0,
            );

            $returnFn = JsFunction::fromCallable(
                'return',
                function (
                    JsValue $thisVal,
                    array $returnArgs
                ) use (
                    &$done,
                    &$started,
                    &$running,
                    &$innerIt,
                ): JsValue {
                    if ($running) {
                        throw new TypeError('Cannot call return on a running iterator helper');
                    }
                    if (!$started || $done) {
                        $done = true;
                        return self::iterResult(JsUndefined::instance(), true);
                    }
                    $done = true;
                    if ($innerIt !== null) {
                        $currentInner = $innerIt;
                        $innerIt = null;
                        $returnMethod = $currentInner->get('return');
                        if ($returnMethod instanceof JsFunction) {
                            $running = true;
                            try {
                                $returnMethod->call($currentInner, []);
                            } catch (\Throwable $e) {
                                $running = false;
                                throw $e;
                            }
                            $running = false;
                        }
                    }
                    return self::iterResult(JsUndefined::instance(), true);
                },
                0,
            );

            $helper->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));
            $helper->defineOwnProperty('return', PropertyDescriptor::data($returnFn, true, false, true));
            return $helper;
        };
    }

    private static function isIteratorInstance(JsObject $obj, JsObject $iteratorPrototype): bool
    {
        $proto = $obj->getPrototype();
        while ($proto !== null) {
            if ($proto === $iteratorPrototype) {
                return true;
            }
            $proto = $proto->getPrototype();
        }
        return false;
    }

    private static function createWrapForValidIterator(JsObject $iterator): JsObject
    {
        $wrapper = new JsObject(self::$wrapForValidIteratorPrototype);
        // Spec WrapForValidIteratorPrototype methods require an [[Iterated]]
        // internal slot. Mark the wrapper with a non-enumerable, non-configurable
        // slot so the next/return methods can verify `this` is a proper wrap.
        $wrapper->defineOwnProperty(
            '[[WrapForValidIterator]]',
            PropertyDescriptor::data($iterator, false, false, false),
        );
        $nextMethod = $iterator->get('next');

        $nextFn = JsFunction::fromCallable('next', function (JsValue $this_, array $args) use ($nextMethod): JsValue {
            // Validate `this` has [[WrapForValidIterator]] (set on creation).
            if (!$this_ instanceof JsObject || $this_->getOwnPropertyDescriptor('[[WrapForValidIterator]]') === null) {
                throw new TypeError('Iterator wrap method called on incompatible receiver');
            }
            if (!$nextMethod instanceof JsFunction) {
                throw new TypeError('Iterator next is not a function');
            }
            $iter = $this_->getOwnPropertyDescriptor('[[WrapForValidIterator]]')->value;
            // Spec WrapForValidIteratorPrototype.next: invoke the inner next
            // method with no arguments; the wrapper's caller-supplied args
            // are discarded ("next argument is ignored" semantic). The spec
            // does NOT validate that the result is an object — that
            // validation is the caller's responsibility.
            return $nextMethod->call($iter, []);
        }, 0);

        $returnFn = JsFunction::fromCallable('return', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsObject || $this_->getOwnPropertyDescriptor('[[WrapForValidIterator]]') === null) {
                throw new TypeError('Iterator wrap method called on incompatible receiver');
            }
            $iter = $this_->getOwnPropertyDescriptor('[[WrapForValidIterator]]')->value;
            if (!$iter instanceof JsObject) {
                return self::iterResult(JsUndefined::instance(), true);
            }
            $returnMethod = $iter->get('return');
            if ($returnMethod instanceof JsFunction) {
                return $returnMethod->call($iter, []);
            }
            return self::iterResult(JsUndefined::instance(), true);
        }, 0);

        $wrapper->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));
        $wrapper->defineOwnProperty('return', PropertyDescriptor::data($returnFn, true, false, true));
        return $wrapper;
    }
}
