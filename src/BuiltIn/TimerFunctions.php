<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * WindowOrWorkerGlobalScope timer surface — setTimeout / setInterval /
 * clearTimeout / clearInterval — plus queueMicrotask.
 *
 * Timers delegate to the active realm's EventLoop, identified via
 * `Engine::getCurrentRealm()` at call time. The handle id flows
 * through the JS surface as a plain integer (an `i32`-ish number)
 * because the spec types these as `long` / `unsigned long`.
 *
 * queueMicrotask routes directly through JsPromise::scheduleCallback —
 * which is the same path Promise.then handlers take. So a microtask
 * queued via `queueMicrotask(cb)` runs in the same FIFO drain as a
 * promise handler.
 *
 * Per spec, the callback can also be a code string (legacy
 * setTimeout(\"alert('x')\", 100) form). We do NOT implement that;
 * non-callable first argument throws TypeError, matching how strict
 * environments and most modern style guides treat it.
 */
final class TimerFunctions
{
    public static function install(Environment $env): void
    {
        $env->defineVar('setTimeout', JsFunction::fromCallable(
            'setTimeout',
            static function (JsValue $this_, array $args): JsValue {
                unset($this_);
                return new JsNumber(self::schedule($args, repeating: false));
            },
            2,
        ));

        $env->defineVar('setInterval', JsFunction::fromCallable(
            'setInterval',
            static function (JsValue $this_, array $args): JsValue {
                unset($this_);
                return new JsNumber(self::schedule($args, repeating: true));
            },
            2,
        ));

        $clearImpl = static function (JsValue $this_, array $args): JsValue {
            unset($this_);
            $id = isset($args[0]) ? (int) TypeConversion::toNumber($args[0]) : 0;
            $realm = Engine::getCurrentRealm();
            if ($realm !== null && $id > 0) {
                $realm->getEventLoop()->clear($id);
            }
            return JsUndefined::instance();
        };
        $env->defineVar(
            'clearTimeout',
            JsFunction::fromCallable('clearTimeout', $clearImpl, 1),
        );
        $env->defineVar(
            'clearInterval',
            JsFunction::fromCallable('clearInterval', $clearImpl, 1),
        );

        // reportError(error) — HTML spec global that surfaces an error
        // through the global "error" event handler (or the host's
        // uncaught-exception channel when none is registered). We
        // don't have a host event handler hook here, so we mirror the
        // same error_log path the microtask drainer uses; that's the
        // observable behaviour every other host uses too (Node logs
        // to stderr, Workers route to globalThis.onerror).
        $env->defineVar('reportError', JsFunction::fromCallable(
            'reportError',
            static function (JsValue $this_, array $args): JsValue {
                unset($this_);
                $err = $args[0] ?? JsUndefined::instance();
                $msg = '';
                if ($err instanceof JsObject) {
                    $messageProp = $err->get('message');
                    if ($messageProp instanceof JsString && $messageProp->value !== '') {
                        $msg = $messageProp->value;
                    }
                    $nameProp = $err->get('name');
                    if ($nameProp instanceof JsString && $nameProp->value !== '') {
                        $msg = $nameProp->value . ($msg !== '' ? ': ' . $msg : '');
                    }
                }
                if ($msg === '') {
                    $msg = TypeConversion::toString($err);
                }
                error_log('Phasis: reportError: ' . $msg);
                return JsUndefined::instance();
            },
            1,
        ));

        $env->defineVar('queueMicrotask', JsFunction::fromCallable(
            'queueMicrotask',
            static function (JsValue $this_, array $args): JsValue {
                unset($this_);
                $cb = $args[0] ?? JsUndefined::instance();
                if (!$cb instanceof JsFunction) {
                    throw new TypeError(
                        'queueMicrotask: argument is not a function',
                    );
                }
                $realm = Engine::getCurrentRealm();
                $interp = $realm?->getInterpreter();
                JsPromise::scheduleCallback(static function () use ($interp, $cb): void {
                    if ($interp === null) {
                        return;
                    }
                    try {
                        $interp->callFunction($cb, JsUndefined::instance(), []);
                    } catch (\Throwable $e) {
                        // Same uncaught-microtask policy as the
                        // EventLoop: surface via error_log, keep
                        // draining the rest of the queue.
                        error_log(
                            'Phasis: uncaught in queueMicrotask: ' . $e->getMessage(),
                        );
                    }
                });
                return JsUndefined::instance();
            },
            1,
        ));
    }

    /**
     * @param list<JsValue> $args
     */
    private static function schedule(array $args, bool $repeating): int
    {
        $cb = $args[0] ?? JsUndefined::instance();
        if (!$cb instanceof JsFunction) {
            throw new TypeError(
                ($repeating ? 'setInterval' : 'setTimeout')
                . ': callback argument is not a function',
            );
        }
        $delay = isset($args[1]) ? (float) TypeConversion::toNumber($args[1]) : 0.0;
        if (is_nan($delay) || $delay < 0.0) {
            $delay = 0.0;
        }
        // Spec: setTimeout(cb, delay, ...args) forwards `args` to `cb`
        // on every invocation. The `setimmediate` npm polyfill (JSZip,
        // promise-polyfill, etc.) relies on this — it ships its task
        // id through the third argument and looks it up in a queue on
        // callback dispatch. Dropping args here makes those libraries
        // silently hang because their dispatcher gets `cb(undefined)`.
        $callbackArgs = array_slice($args, 2);
        $realm = Engine::getCurrentRealm();
        if ($realm === null) {
            return 0;
        }
        $loop = $realm->getEventLoop();
        return $repeating
            ? $loop->setInterval($cb, $delay, $callbackArgs)
            : $loop->setTimeout($cb, $delay, $callbackArgs);
    }
}
