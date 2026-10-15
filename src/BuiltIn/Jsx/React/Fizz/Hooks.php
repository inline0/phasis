<?php

/**
 * Ported from React 19.3.0's Fizz hooks (ReactFizzHooks), as built into
 * react-dom-server.node.production.js.
 *
 * Copyright (c) Meta Platforms, Inc. and affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree
 * (third-party/react/LICENSE in Phasis).
 */

declare(strict_types=1);

namespace Phasis\BuiltIn\Jsx\React\Fizz;

use Phasis\BuiltIn\Jsx\Js;
use Phasis\BuiltIn\Jsx\React\ReactSymbols;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * The server hooks dispatcher. Hooks are stateful only within one
 * component render: state is the initial state, render-phase updates
 * re-run the component, effects never run.
 */
final class Hooks
{
    private static ?Hooks $instance = null;

    private static ?\stdClass $currentlyRenderingComponent = null;
    private static ?Task $currentlyRenderingTask = null;

    /** @var array<int, mixed>|null */
    private static ?array $currentlyRenderingKeyPath = null;

    private static ?\stdClass $firstWorkInProgressHook = null;
    private static ?\stdClass $workInProgressHook = null;
    private static bool $isReRender = false;
    private static bool $didScheduleRenderPhaseUpdate = false;
    public static int $localIdCounter = 0;
    public static int $actionStateCounter = 0;
    public static int $actionStateMatchingIndex = -1;
    private static int $thenableIndexCounter = 0;

    /** @var list<JsValue>|null */
    private static ?array $thenableState = null;

    /** @var \SplObjectStorage<\stdClass, \stdClass>|null */
    private static ?\SplObjectStorage $renderPhaseUpdates = null;

    private static int $numberOfReRenders = 0;

    public static ?ResumableState $currentResumableState = null;

    /** Id factory for useId outside a Fizz render (the HTML profile). */
    private static ?\Closure $idFactory = null;

    private static ?JsFunction $basicStateReducer = null;

    public static function dispatcher(): self
    {
        return self::$instance ??= new self();
    }

    private static function resolveCurrentlyRenderingComponent(): \stdClass
    {
        if (self::$currentlyRenderingComponent === null) {
            throw Js::error(
                "Invalid hook call. Hooks can only be called inside of the body of a function component. This could "
                . "happen for one of the following reasons:\n1. You might have mismatching versions of React and the "
                . "renderer (such as React DOM)\n2. You might be breaking the Rules of Hooks\n3. You might have more "
                . "than one copy of React in the same app\nSee https://react.dev/link/invalid-hook-call for tips about "
                . "how to debug and fix this problem.",
            );
        }
        return self::$currentlyRenderingComponent;
    }

    private static function createHook(): \stdClass
    {
        if (self::$numberOfReRenders > 0) {
            throw Js::error('Rendered more hooks than during the previous render');
        }
        $hook = new \stdClass();
        $hook->memoizedState = null;
        $hook->queue = null;
        $hook->next = null;
        return $hook;
    }

    private static function createWorkInProgressHook(): \stdClass
    {
        if (self::$workInProgressHook === null) {
            if (self::$firstWorkInProgressHook === null) {
                self::$isReRender = false;
                self::$firstWorkInProgressHook = self::$workInProgressHook = self::createHook();
            } else {
                self::$isReRender = true;
                self::$workInProgressHook = self::$firstWorkInProgressHook;
            }
        } elseif (self::$workInProgressHook->next === null) {
            self::$isReRender = false;
            self::$workInProgressHook = self::$workInProgressHook->next = self::createHook();
        } else {
            self::$isReRender = true;
            self::$workInProgressHook = self::$workInProgressHook->next;
        }
        return self::$workInProgressHook;
    }

    /** @return list<JsValue>|null */
    public static function getThenableStateAfterSuspending(): ?array
    {
        $state = self::$thenableState;
        self::$thenableState = null;
        return $state;
    }

    public static function resetHooksState(): void
    {
        self::$currentlyRenderingKeyPath = null;
        self::$currentlyRenderingTask = null;
        self::$currentlyRenderingComponent = null;
        self::$didScheduleRenderPhaseUpdate = false;
        self::$firstWorkInProgressHook = null;
        self::$numberOfReRenders = 0;
        self::$workInProgressHook = null;
        self::$renderPhaseUpdates = null;
    }

    /**
     * Call a function component with hooks, re-running it while it
     * schedules render-phase updates.
     *
     * @param array<int, mixed>|null $keyPath
     */
    public static function renderWithHooks(
        Request $request,
        Task $task,
        ?array $keyPath,
        JsValue $component,
        JsValue $props,
        JsValue $secondArg,
    ): JsValue {
        $prevThenableState = $task->thenableState;
        $task->thenableState = null;
        self::$currentlyRenderingComponent = new \stdClass();
        self::$currentlyRenderingTask = $task;
        self::$currentlyRenderingKeyPath = $keyPath;
        self::$actionStateCounter = self::$localIdCounter = 0;
        self::$actionStateMatchingIndex = -1;
        self::$thenableIndexCounter = 0;
        self::$thenableState = $prevThenableState;
        $undefined = JsUndefined::instance();
        $children = Js::call($component, $undefined, [$props, $secondArg]);
        while (self::$didScheduleRenderPhaseUpdate) {
            self::$didScheduleRenderPhaseUpdate = false;
            self::$actionStateCounter = self::$localIdCounter = 0;
            self::$actionStateMatchingIndex = -1;
            self::$thenableIndexCounter = 0;
            self::$numberOfReRenders++;
            self::$workInProgressHook = null;
            $children = Js::call($component, $undefined, [$props, $secondArg]);
        }
        self::resetHooksState();
        return $children;
    }

    /**
     * Call a function component outside a Fizz request (the HTML profile),
     * with the same hooks semantics; useId takes its ids from $idFactory.
     *
     * @param \Closure(): string $idFactory
     */
    public static function renderStandalone(JsValue $component, JsValue $props, JsValue $secondArg, \Closure $idFactory): JsValue
    {
        $previousFactory = self::$idFactory;
        $previousTask = self::$currentlyRenderingTask;
        self::$idFactory = $idFactory;
        self::$currentlyRenderingTask = null;
        self::$currentlyRenderingComponent = new \stdClass();
        self::$actionStateCounter = self::$localIdCounter = 0;
        self::$actionStateMatchingIndex = -1;
        self::$thenableIndexCounter = 0;
        self::$thenableState = null;
        $undefined = JsUndefined::instance();
        try {
            $children = Js::call($component, $undefined, [$props, $secondArg]);
            while (self::$didScheduleRenderPhaseUpdate) {
                self::$didScheduleRenderPhaseUpdate = false;
                self::$actionStateCounter = self::$localIdCounter = 0;
                self::$actionStateMatchingIndex = -1;
                self::$thenableIndexCounter = 0;
                self::$numberOfReRenders++;
                self::$workInProgressHook = null;
                $children = Js::call($component, $undefined, [$props, $secondArg]);
            }
            return $children;
        } finally {
            self::resetHooksState();
            self::$idFactory = $previousFactory;
            self::$currentlyRenderingTask = $previousTask;
        }
    }

    public static function unwrapThenable(JsObject $thenable): JsValue
    {
        $index = self::$thenableIndexCounter;
        self::$thenableIndexCounter++;
        self::$thenableState ??= [];
        return Server::trackUsedThenable(self::$thenableState, $thenable, $index);
    }

    private static function basicStateReducer(): JsFunction
    {
        return self::$basicStateReducer ??= Js::fn('basicStateReducer', static function (JsValue $this_, array $args): JsValue {
            $state = $args[0] ?? JsUndefined::instance();
            $action = $args[1] ?? JsUndefined::instance();
            return $action instanceof JsFunction ? Js::call($action, JsUndefined::instance(), [$state]) : $action;
        }, 2);
    }

    // The dispatcher.

    public function readContext(JsValue $context): JsValue
    {
        return Js::get($context, Server::$contextField);
    }

    public function use(JsValue $usable): JsValue
    {
        if ($usable instanceof JsObject && !$usable instanceof JsFunction) {
            if (Js::get($usable, 'then') instanceof JsFunction) {
                return self::unwrapThenable($usable);
            }
            $typeof = Js::get($usable, '$$typeof');
            if ($typeof === ReactSymbols::$recoverable) {
                Js::throw(Server::createRecoverableError($usable));
            }
            if ($typeof === ReactSymbols::$context) {
                return Js::get($usable, Server::$contextField);
            }
        }
        throw Js::error('An unsupported type was passed to use(): ' . Js::str(Server::stringOf($usable)));
    }

    public function useContext(JsValue $context): JsValue
    {
        self::resolveCurrentlyRenderingComponent();
        return Js::get($context, Server::$contextField);
    }

    public function useMemo(JsValue $nextCreate, JsValue $deps): JsValue
    {
        self::$currentlyRenderingComponent = self::resolveCurrentlyRenderingComponent();
        $hook = self::createWorkInProgressHook();
        $depsList = $deps instanceof JsUndefined || $deps instanceof JsNull ? null : $deps;
        $prevState = $hook->memoizedState;
        if ($prevState !== null && $depsList !== null) {
            $prevDeps = $prevState[1];
            $same = false;
            if ($prevDeps !== null) {
                $same = true;
                $next = Js::elements($depsList);
                $prev = Js::elements($prevDeps);
                $n = min(count($next), count($prev));
                for ($i = 0; $i < $n; $i++) {
                    if (!Js::is($next[$i], $prev[$i])) {
                        $same = false;
                        break;
                    }
                }
            }
            if ($same) {
                return $prevState[0];
            }
        }
        $value = Js::call($nextCreate, JsUndefined::instance());
        $hook->memoizedState = [$value, $depsList];
        return $value;
    }

    public function useReducer(JsValue $reducer, JsValue $initialArg, JsValue $init): JsValue
    {
        self::$currentlyRenderingComponent = self::resolveCurrentlyRenderingComponent();
        $hook = self::createWorkInProgressHook();
        if (self::$isReRender) {
            $queue = $hook->queue;
            $dispatch = $queue->dispatch;
            if (self::$renderPhaseUpdates !== null && self::$renderPhaseUpdates->offsetExists($queue)) {
                $update = self::$renderPhaseUpdates[$queue];
                self::$renderPhaseUpdates->offsetUnset($queue);
                $state = $hook->memoizedState;
                do {
                    $state = Js::call($reducer, JsUndefined::instance(), [$state, $update->action]);
                    $update = $update->next;
                } while ($update !== null);
                $hook->memoizedState = $state;
                return new JsArray([$state, $dispatch]);
            }
            return new JsArray([$hook->memoizedState, $dispatch]);
        }
        if ($reducer === self::basicStateReducer()) {
            $state = $initialArg instanceof JsFunction ? Js::call($initialArg, JsUndefined::instance()) : $initialArg;
        } else {
            $state = $init instanceof JsUndefined ? $initialArg : Js::call($init, JsUndefined::instance(), [$initialArg]);
        }
        $hook->memoizedState = $state;
        $queue = new \stdClass();
        $queue->last = null;
        $queue->dispatch = null;
        $hook->queue = $queue;
        $component = self::$currentlyRenderingComponent;
        $queue->dispatch = Js::fn('', static function (JsValue $this_, array $args) use ($component, $queue): JsValue {
            self::dispatchAction($component, $queue, $args[0] ?? JsUndefined::instance());
            return JsUndefined::instance();
        }, 1);
        return new JsArray([$hook->memoizedState, $queue->dispatch]);
    }

    private static function dispatchAction(\stdClass $componentIdentity, \stdClass $queue, JsValue $action): void
    {
        if (self::$numberOfReRenders >= 25) {
            throw Js::error('Too many re-renders. React limits the number of renders to prevent an infinite loop.');
        }
        if ($componentIdentity !== self::$currentlyRenderingComponent) {
            return;
        }
        self::$didScheduleRenderPhaseUpdate = true;
        $update = new \stdClass();
        $update->action = $action;
        $update->next = null;
        self::$renderPhaseUpdates ??= new \SplObjectStorage();
        if (!self::$renderPhaseUpdates->offsetExists($queue)) {
            self::$renderPhaseUpdates[$queue] = $update;
            return;
        }
        $last = self::$renderPhaseUpdates[$queue];
        while ($last->next !== null) {
            $last = $last->next;
        }
        $last->next = $update;
    }

    public function useRef(JsValue $initialValue): JsValue
    {
        self::$currentlyRenderingComponent = self::resolveCurrentlyRenderingComponent();
        $hook = self::createWorkInProgressHook();
        if ($hook->memoizedState === null) {
            $hook->memoizedState = Js::object(['current' => $initialValue]);
        }
        return $hook->memoizedState;
    }

    public function useState(JsValue $initialState): JsValue
    {
        return $this->useReducer(self::basicStateReducer(), $initialState, JsUndefined::instance());
    }

    public function useCallback(JsValue $callback, JsValue $deps): JsValue
    {
        return $this->useMemo(Js::fn('', static fn (): JsValue => $callback), $deps);
    }

    public function useDeferredValue(JsValue $value, JsValue $initialValue): JsValue
    {
        self::resolveCurrentlyRenderingComponent();
        return $initialValue instanceof JsUndefined ? $value : $initialValue;
    }

    public function useTransition(): JsValue
    {
        self::resolveCurrentlyRenderingComponent();
        return new JsArray([JsBoolean::of(false), Js::fn('', static function (): JsValue {
            throw Js::error('startTransition cannot be called during server rendering.');
        })]);
    }

    public function useId(): JsValue
    {
        if (self::$idFactory !== null && self::$currentlyRenderingTask === null) {
            self::resolveCurrentlyRenderingComponent();
            self::$localIdCounter++;
            return new JsString((self::$idFactory)());
        }
        $task = self::$currentlyRenderingTask;
        $treeId = $task === null ? '' : Server::getTreeId($task->treeContext);
        $resumableState = self::$currentResumableState;
        if ($resumableState === null || $task === null) {
            throw Js::error('Invalid hook call. Hooks can only be called inside of the body of a function component.');
        }
        $localId = self::$localIdCounter++;
        return new JsString(Dom::makeId($resumableState, $treeId, $localId));
    }

    public function useSyncExternalStore(JsValue $subscribe, JsValue $getSnapshot, JsValue $getServerSnapshot): JsValue
    {
        if ($getServerSnapshot instanceof JsUndefined) {
            throw Js::error('Missing getServerSnapshot, which is required for server-rendered content. Will revert to client rendering.');
        }
        return Js::call($getServerSnapshot, JsUndefined::instance());
    }

    public function useOptimistic(JsValue $passthrough): JsValue
    {
        self::resolveCurrentlyRenderingComponent();
        return new JsArray([$passthrough, Js::fn('', static function (): JsValue {
            throw Js::error('Cannot update optimistic state while rendering.');
        })]);
    }

    public function useActionState(JsValue $action, JsValue $initialState, JsValue $permalink): JsValue
    {
        self::resolveCurrentlyRenderingComponent();
        $actionStateHookIndex = self::$actionStateCounter++;
        $formAction = Js::get($action, '$$FORM_ACTION');
        $bind = static fn (JsValue $fn, JsValue $arg): JsValue => Js::call(Js::get($fn, 'bind'), $fn, [JsNull::instance(), $arg]);
        if ($formAction instanceof JsFunction) {
            // React keys the postback state with an md5 of the key path;
            // Phasis renders have no form state to match it against, so the
            // postback branch is unreachable and only the binding remains.
            $boundAction = $bind($action, $initialState);
            $wrapped = Js::fn('', static function (JsValue $this_, array $args) use ($boundAction): JsValue {
                Js::call($boundAction, JsUndefined::instance(), [$args[0] ?? JsUndefined::instance()]);
                return JsUndefined::instance();
            }, 1);
            $boundFormAction = Js::get($boundAction, '$$FORM_ACTION');
            if ($boundFormAction instanceof JsFunction) {
                $keyPath = self::$currentlyRenderingKeyPath;
                $wrapped->set('$$FORM_ACTION', Js::fn('', static function (
                    JsValue $this_,
                    array $args,
                ) use (
                    $boundAction,
                    $permalink,
                    $keyPath,
                    $actionStateHookIndex,
                ): JsValue {
                    $prefix = Js::call(Js::get($boundAction, '$$FORM_ACTION'), $boundAction, [$args[0] ?? JsUndefined::instance()]);
                    if (!$permalink instanceof JsUndefined && $prefix instanceof JsObject) {
                        $prefix->set('action', new JsString(Js::str($permalink)));
                    }
                    $formData = Js::get($prefix, 'data');
                    if ($formData->toBoolean()) {
                        $key = Server::postbackActionStateKey($permalink, $keyPath, $actionStateHookIndex);
                        Js::call(Js::get($formData, 'append'), $formData, [new JsString('$ACTION_KEY'), new JsString($key)]);
                    }
                    return $prefix;
                }, 1));
            }
            return new JsArray([$initialState, $wrapped, JsBoolean::of(false)]);
        }
        $boundAction = $bind($action, $initialState);
        return new JsArray([
            $initialState,
            Js::fn('', static function (JsValue $this_, array $args) use ($boundAction): JsValue {
                Js::call($boundAction, JsUndefined::instance(), [$args[0] ?? JsUndefined::instance()]);
                return JsUndefined::instance();
            }, 1),
            JsBoolean::of(false),
        ]);
    }

    public function useHostTransitionStatus(): JsValue
    {
        self::resolveCurrentlyRenderingComponent();
        return Server::sharedNotPendingObject();
    }

    public function useMemoCache(JsValue $size): JsValue
    {
        $n = (int) \Phasis\Spec\TypeConversion::toNumber($size);
        return new JsArray(array_fill(0, max(0, $n), ReactSymbols::$memoCacheSentinel));
    }

    public function useCacheRefresh(): JsValue
    {
        return Js::fn('', static function (): JsValue {
            throw Js::error('Cache cannot be refreshed during server rendering.');
        });
    }

    public function useEffectEvent(JsValue $callback): JsValue
    {
        return Js::fn('', static function (): JsValue {
            throw Js::error("A function wrapped in useEffectEvent can't be called during rendering.");
        });
    }
}
