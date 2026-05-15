<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\AsyncContextStorage;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * `AsyncContext` — TC39 Stage 3 proposal.
 *
 * Exposes a namespace with `AsyncContext.Variable` and
 * `AsyncContext.Snapshot`. The realm carries a `AsyncContextStorage`
 * map keyed by Variable instances; `JsPromise::scheduleCallback`
 * and `EventLoop::setTimeout/setInterval` capture the active map at
 * schedule time and restore it before invoking the callback, so the
 * value flows naturally across async boundaries.
 *
 * Surface:
 *
 *   const ctx = new AsyncContext.Variable({ name?, defaultValue? });
 *   ctx.run(value, () => { ... });   // sync + async
 *   ctx.get();                        // returns current or default
 *
 *   const snap = AsyncContext.Snapshot.wrap(fn);
 *   snap();                          // restores at call time
 *
 * Not yet wired: generator-resume propagation (the value at the
 * `yield` resumption point reflects whoever called `.next()`, not
 * whoever called the generator originally — matches the proposal's
 * "Per-thread storage" model).
 */
final class AsyncContextConstructor
{
    public static function install(Environment $env): void
    {
        $ns = new JsObject();
        $ns->defineOwnProperty(
            'Variable',
            PropertyDescriptor::data(self::variableCtor(), true, false, true),
        );
        $ns->defineOwnProperty(
            'Snapshot',
            PropertyDescriptor::data(self::snapshotCtor(), true, false, true),
        );
        $env->defineVar('AsyncContext', $ns);
    }

    // -----------------------------------------------------------------
    // AsyncContext.Variable
    // -----------------------------------------------------------------

    private static function variableCtor(): JsFunction
    {
        $proto = new JsObject();

        $ctor = JsFunction::fromCallable(
            'Variable',
            static function (JsValue $this_, array $args) use ($proto): JsValue {
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor AsyncContext.Variable requires 'new'");
                }
                $this_->setPrototype($proto);
                $opts = $args[0] ?? JsUndefined::instance();
                $name = '';
                $defaultValue = JsUndefined::instance();
                if ($opts instanceof JsObject) {
                    $n = $opts->get('name');
                    if (!$n instanceof JsUndefined) {
                        $name = TypeConversion::toString($n);
                    }
                    $dv = $opts->get('defaultValue');
                    if (!$dv instanceof JsUndefined) {
                        $defaultValue = $dv;
                    }
                }
                $this_->setInternalProperty('[[IsAsyncContextVariable]]', true);
                $this_->setInternalProperty('[[VarName]]', $name);
                $this_->setInternalProperty('[[VarDefault]]', $defaultValue);
                return $this_;
            },
            1,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true),
        );

        // name getter
        $proto->defineOwnProperty(
            'name',
            PropertyDescriptor::accessor(
                JsFunction::fromCallable('get name', static function (JsValue $this_): JsValue {
                    $v = self::requireVariable($this_, 'name');
                    return new JsString((string) ($v->getInternalProperty('[[VarName]]') ?? ''));
                }, 0),
                null,
                false,
                true,
            ),
        );

        // defaultValue getter
        $proto->defineOwnProperty(
            'defaultValue',
            PropertyDescriptor::accessor(
                JsFunction::fromCallable('get defaultValue', static function (JsValue $this_): JsValue {
                    $v = self::requireVariable($this_, 'defaultValue');
                    $d = $v->getInternalProperty('[[VarDefault]]');
                    return $d instanceof JsValue ? $d : JsUndefined::instance();
                }, 0),
                null,
                false,
                true,
            ),
        );

        // run(value, fn, ...args) — set value, call fn, restore.
        $proto->defineOwnProperty(
            'run',
            PropertyDescriptor::data(
                JsFunction::fromCallable('run', static function (JsValue $this_, array $args): JsValue {
                    $v = self::requireVariable($this_, 'run');
                    $value = $args[0] ?? JsUndefined::instance();
                    $fn = $args[1] ?? JsUndefined::instance();
                    if (!$fn instanceof JsFunction) {
                        throw new TypeError('AsyncContext.Variable.run: 2nd argument must be a function');
                    }
                    $fnArgs = array_slice($args, 2);
                    $storage = AsyncContextStorage::active();
                    $previous = $storage->getRaw($v);
                    $storage->set($v, $value);
                    try {
                        return Engine::getCurrentRealm()
                            ?->getInterpreter()
                            ?->callFunction($fn, JsUndefined::instance(), $fnArgs)
                            ?? JsUndefined::instance();
                    } finally {
                        $storage->restoreSlot($v, $previous);
                    }
                }, 2),
                true,
                false,
                true,
            ),
        );

        // get() — current value or defaultValue.
        $proto->defineOwnProperty(
            'get',
            PropertyDescriptor::data(
                JsFunction::fromCallable('get', static function (JsValue $this_): JsValue {
                    $v = self::requireVariable($this_, 'get');
                    $storage = AsyncContextStorage::active();
                    $val = $storage->getRaw($v);
                    if ($val !== null) {
                        return $val;
                    }
                    $d = $v->getInternalProperty('[[VarDefault]]');
                    return $d instanceof JsValue ? $d : JsUndefined::instance();
                }, 0),
                true,
                false,
                true,
            ),
        );

        return $ctor;
    }

    // -----------------------------------------------------------------
    // AsyncContext.Snapshot
    // -----------------------------------------------------------------

    private static function snapshotCtor(): JsFunction
    {
        $proto = new JsObject();

        $ctor = JsFunction::fromCallable(
            'Snapshot',
            static function (JsValue $this_, array $args) use ($proto): JsValue {
                unset($args);
                if (!$this_ instanceof JsObject || !$this_->has('[[NewTarget]]')) {
                    throw new TypeError("Constructor AsyncContext.Snapshot requires 'new'");
                }
                $this_->setPrototype($proto);
                $this_->setInternalProperty('[[IsAsyncContextSnapshot]]', true);
                $this_->setInternalProperty(
                    '[[SnapState]]',
                    AsyncContextStorage::active()->snapshot(),
                );
                return $this_;
            },
            0,
        );
        $ctor->setConstructable();
        $ctor->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data($proto, false, false, false),
        );
        $proto->defineOwnProperty(
            'constructor',
            PropertyDescriptor::data($ctor, true, false, true),
        );

        // run(fn, ...args) — invoke fn with the captured context map.
        $proto->defineOwnProperty(
            'run',
            PropertyDescriptor::data(
                JsFunction::fromCallable('run', static function (JsValue $this_, array $args): JsValue {
                    $snap = self::requireSnapshot($this_, 'run');
                    $fn = $args[0] ?? JsUndefined::instance();
                    if (!$fn instanceof JsFunction) {
                        throw new TypeError('AsyncContext.Snapshot.run: 1st argument must be a function');
                    }
                    $fnArgs = array_slice($args, 1);
                    $state = $snap->getInternalProperty('[[SnapState]]');
                    $storage = AsyncContextStorage::active();
                    $previous = $storage->snapshot();
                    if (is_array($state)) {
                        $storage->restore($state);
                    }
                    try {
                        return Engine::getCurrentRealm()
                            ?->getInterpreter()
                            ?->callFunction($fn, JsUndefined::instance(), $fnArgs)
                            ?? JsUndefined::instance();
                    } finally {
                        $storage->restore($previous);
                    }
                }, 1),
                true,
                false,
                true,
            ),
        );

        // Static wrap(fn) — returns a function that captures the
        // current snapshot at wrap-time and restores on each call.
        $ctor->defineOwnProperty(
            'wrap',
            PropertyDescriptor::data(
                JsFunction::fromCallable('wrap', static function (JsValue $this_, array $args): JsValue {
                    unset($this_);
                    $fn = $args[0] ?? JsUndefined::instance();
                    if (!$fn instanceof JsFunction) {
                        throw new TypeError('AsyncContext.Snapshot.wrap: 1st argument must be a function');
                    }
                    $capturedState = AsyncContextStorage::active()->snapshot();
                    return JsFunction::fromCallable(
                        'wrapped',
                        static function (JsValue $thisArg, array $cargs) use ($fn, $capturedState): JsValue {
                            $storage = AsyncContextStorage::active();
                            $previous = $storage->snapshot();
                            $storage->restore($capturedState);
                            try {
                                return Engine::getCurrentRealm()
                                    ?->getInterpreter()
                                    ?->callFunction($fn, $thisArg, $cargs)
                                    ?? JsUndefined::instance();
                            } finally {
                                $storage->restore($previous);
                            }
                        },
                        count($fn->getParams()),
                    );
                }, 1),
                true,
                false,
                true,
            ),
        );

        return $ctor;
    }

    private static function requireVariable(JsValue $val, string $op): JsObject
    {
        if (!$val instanceof JsObject || $val->getInternalProperty('[[IsAsyncContextVariable]]') !== true) {
            throw new TypeError("AsyncContext.Variable.{$op} called on non-Variable");
        }
        return $val;
    }

    private static function requireSnapshot(JsValue $val, string $op): JsObject
    {
        if (!$val instanceof JsObject || $val->getInternalProperty('[[IsAsyncContextSnapshot]]') !== true) {
            throw new TypeError("AsyncContext.Snapshot.{$op} called on non-Snapshot");
        }
        return $val;
    }
}
