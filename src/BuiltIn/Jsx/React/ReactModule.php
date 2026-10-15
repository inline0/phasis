<?php

/**
 * Native `react` and `react/jsx-runtime` for React 19.3.1.
 *
 * Ported from React's react.production.js and react-jsx-runtime.production.js:
 *
 * Copyright (c) Meta Platforms, Inc. and affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree
 * (third-party/react/LICENSE in Phasis).
 */

declare(strict_types=1);

namespace Phasis\BuiltIn\Jsx\React;

use Phasis\BuiltIn\Jsx\Js;
use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Value\JsArray;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsElement;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

final class ReactModule
{
    public const VERSION = '19.3.1';

    /** The hooks dispatcher of the render in progress (React's ReactSharedInternals.H). */
    public static ?Fizz\Hooks $dispatcher = null;

    /** @var array<string, JsValue>|null */
    private ?array $exports = null;

    /** @var array<string, JsValue>|null */
    private ?array $jsxRuntime = null;

    public function __construct()
    {
        ReactSymbols::init();
    }

    /** @return array<string, JsValue> */
    public function exports(): array
    {
        return $this->exports ??= $this->build();
    }

    /** @return array<string, JsValue> */
    public function jsxRuntimeExports(): array
    {
        if ($this->jsxRuntime === null) {
            $jsx = Js::fn('jsxProd', static fn (JsValue $this_, array $args): JsValue => self::jsx(
                $args[0] ?? JsUndefined::instance(),
                $args[1] ?? JsUndefined::instance(),
                $args[2] ?? JsUndefined::instance(),
            ), 3);
            $this->jsxRuntime = [
                'Fragment' => ReactSymbols::$fragment,
                'jsx' => $jsx,
                'jsxs' => $jsx,
            ];
        }
        return $this->jsxRuntime;
    }

    /** React's jsxProd: the automatic runtime's element factory. */
    public static function jsx(JsValue $type, JsValue $config, JsValue $maybeKey): JsElement
    {
        if (!$config instanceof JsObject) {
            // `"key" in config` throws for primitives; `config.key` reads them.
            throw new TypeError("Cannot use 'in' operator to search for 'key' in " . Js::str($config));
        }
        $key = JsNull::instance();
        if (!$maybeKey instanceof JsUndefined) {
            $key = new JsString(Js::str($maybeKey));
        }
        $configKey = Js::get($config, 'key');
        if (!$configKey instanceof JsUndefined) {
            $key = new JsString(Js::str($configKey));
        }
        if (Js::has($config, 'key')) {
            $props = new JsObject();
            foreach (Js::forInKeys($config) as $name) {
                if ($name !== 'key') {
                    $props->set($name, Js::get($config, $name));
                }
            }
        } else {
            $props = $config;
        }
        $ref = Js::get($props, 'ref');
        return new JsElement($type, $key, $ref instanceof JsUndefined ? JsNull::instance() : $ref, $props);
    }

    /** React's ReactElement(type, key, props). */
    public static function element(JsValue $type, JsValue $key, JsObject $props): JsElement
    {
        $ref = Js::get($props, 'ref');
        return new JsElement($type, $key, $ref instanceof JsUndefined ? JsNull::instance() : $ref, $props);
    }

    public static function isValidElement(JsValue $object): bool
    {
        if ($object instanceof JsElement) {
            return Js::get($object, '$$typeof') === ReactSymbols::$element;
        }
        return $object instanceof JsObject && !$object instanceof JsFunction
            && Js::get($object, '$$typeof') === ReactSymbols::$element;
    }

    /** @param list<JsValue> $args */
    public static function createElement(array $args): JsElement
    {
        $type = $args[0] ?? JsUndefined::instance();
        $config = $args[1] ?? JsUndefined::instance();
        $props = new JsObject();
        $key = JsNull::instance();
        if (!Js::isNullish($config)) {
            $configKey = Js::get($config, 'key');
            if (!$configKey instanceof JsUndefined) {
                $key = new JsString(Js::str($configKey));
            }
            if ($config instanceof JsObject) {
                foreach (Js::forInKeys($config) as $name) {
                    if (Js::hasOwn($config, $name) && $name !== 'key' && $name !== '__self' && $name !== '__source') {
                        $props->set($name, Js::get($config, $name));
                    }
                }
            }
        }
        $childrenLength = count($args) - 2;
        if ($childrenLength === 1) {
            $props->set('children', $args[2]);
        } elseif ($childrenLength > 1) {
            $props->set('children', new JsArray(array_slice($args, 2)));
        }
        if ($type instanceof JsObject) {
            $defaultProps = Js::get($type, 'defaultProps');
            if ($defaultProps->toBoolean() && $defaultProps instanceof JsObject) {
                foreach (Js::forInKeys($defaultProps) as $name) {
                    if (Js::get($props, $name) instanceof JsUndefined) {
                        $props->set($name, Js::get($defaultProps, $name));
                    }
                }
            }
        }
        return self::element($type, $key, $props);
    }

    /** @return array<string, JsValue> */
    private function build(): array
    {
        $undefined = JsUndefined::instance();
        $arg = static fn (array $args, int $i): JsValue => $args[$i] ?? $undefined;

        $exports = [];
        $exports['Activity'] = ReactSymbols::$activity;
        $exports['Children'] = $this->children();
        [$component, $pureComponent] = $this->componentClasses();
        $exports['Component'] = $component;
        $exports['Fragment'] = ReactSymbols::$fragment;
        $exports['Profiler'] = ReactSymbols::$profiler;
        $exports['PureComponent'] = $pureComponent;
        $exports['StrictMode'] = ReactSymbols::$strictMode;
        $exports['Suspense'] = ReactSymbols::$suspense;
        $exports['ViewTransition'] = ReactSymbols::$viewTransition;
        $exports['addTransitionType'] = Js::fn('addTransitionType', static fn (): JsValue => JsUndefined::instance(), 1);
        $exports['cache'] = Js::fn('cache', static function (JsValue $this_, array $args): JsValue {
            $fn = $args[0] ?? JsUndefined::instance();
            return Js::fn('', static fn (JsValue $t, array $a): JsValue => Js::call($fn, JsNull::instance(), $a));
        }, 1);
        $exports['cacheSignal'] = Js::fn('cacheSignal', static fn (): JsValue => JsNull::instance());
        $exports['cloneElement'] = Js::fn('cloneElement', static fn (JsValue $this_, array $args): JsValue => self::cloneElement($args), 3);
        $exports['createContext'] = Js::fn(
            'createContext',
            static fn (JsValue $this_, array $args): JsValue => self::createContext($arg($args, 0)),
            1,
        );
        $exports['createElement'] = Js::fn('createElement', static fn (JsValue $this_, array $args): JsValue => self::createElement($args), 3);
        $exports['createRef'] = Js::fn('createRef', static fn (): JsValue => Js::object(['current' => JsNull::instance()]));
        $exports['forwardRef'] = Js::fn('forwardRef', static fn (JsValue $this_, array $args): JsValue => Js::object([
            '$$typeof' => ReactSymbols::$forwardRef,
            'render' => $arg($args, 0),
        ]), 1);
        $exports['isValidElement'] = Js::fn(
            'isValidElement',
            static fn (JsValue $this_, array $args): JsValue => JsBoolean::of(self::isValidElement($arg($args, 0))),
            1,
        );
        $lazyInit = Js::fn('lazyInitializer', static fn (JsValue $this_, array $args): JsValue => self::lazyInitializer($arg($args, 0)), 1);
        $exports['lazy'] = Js::fn('lazy', static fn (JsValue $this_, array $args): JsValue => Js::object([
            '$$typeof' => ReactSymbols::$lazy,
            '_payload' => Js::object(['_status' => JsNumber::of(-1), '_result' => $arg($args, 0)]),
            '_init' => $lazyInit,
        ]), 1);
        $exports['memo'] = Js::fn('memo', static function (JsValue $this_, array $args) use ($arg): JsValue {
            $compare = $arg($args, 1);
            return Js::object([
                '$$typeof' => ReactSymbols::$memo,
                'type' => $arg($args, 0),
                'compare' => $compare instanceof JsUndefined ? JsNull::instance() : $compare,
            ]);
        }, 2);
        $exports['startTransition'] = Js::fn(
            'startTransition',
            static fn (JsValue $this_, array $args): JsValue => self::startTransition($arg($args, 0)),
            1,
        );

        $hook = static function (string $name, int $length, \Closure $impl): JsFunction {
            return Js::fn($name, static function (JsValue $this_, array $args) use ($name, $impl): JsValue {
                $dispatcher = self::$dispatcher;
                if ($dispatcher === null) {
                    throw new TypeError("Cannot read properties of null (reading '{$name}')");
                }
                return $impl($dispatcher, $args);
            }, $length);
        };
        $exports['unstable_useCacheRefresh'] = $hook('useCacheRefresh', 0, static fn (Fizz\Hooks $h): JsValue => $h->useCacheRefresh());
        $exports['use'] = $hook('use', 1, static fn (Fizz\Hooks $h, array $a): JsValue => $h->use($arg($a, 0)));
        $exports['useActionState'] = $hook(
            'useActionState',
            3,
            static fn (Fizz\Hooks $h, array $a): JsValue => $h->useActionState($arg($a, 0), $arg($a, 1), $arg($a, 2)),
        );
        $exports['useCallback'] = $hook('useCallback', 2, static fn (Fizz\Hooks $h, array $a): JsValue => $h->useCallback($arg($a, 0), $arg($a, 1)));
        $exports['useContext'] = $hook('useContext', 1, static fn (Fizz\Hooks $h, array $a): JsValue => $h->useContext($arg($a, 0)));
        $exports['useDebugValue'] = Js::fn('useDebugValue', static fn (): JsValue => JsUndefined::instance());
        $exports['useDeferredValue'] = $hook(
            'useDeferredValue',
            2,
            static fn (Fizz\Hooks $h, array $a): JsValue => $h->useDeferredValue($arg($a, 0), $arg($a, 1)),
        );
        $exports['useEffect'] = $hook('useEffect', 2, static fn (Fizz\Hooks $h): JsValue => JsUndefined::instance());
        $exports['useEffectEvent'] = $hook('useEffectEvent', 1, static fn (Fizz\Hooks $h, array $a): JsValue => $h->useEffectEvent($arg($a, 0)));
        $exports['useId'] = $hook('useId', 0, static fn (Fizz\Hooks $h): JsValue => $h->useId());
        $exports['useImperativeHandle'] = $hook('useImperativeHandle', 3, static fn (Fizz\Hooks $h): JsValue => JsUndefined::instance());
        $exports['useInsertionEffect'] = $hook('useInsertionEffect', 2, static fn (Fizz\Hooks $h): JsValue => JsUndefined::instance());
        $exports['useLayoutEffect'] = $hook('useLayoutEffect', 2, static fn (Fizz\Hooks $h): JsValue => JsUndefined::instance());
        $exports['useMemo'] = $hook('useMemo', 2, static fn (Fizz\Hooks $h, array $a): JsValue => $h->useMemo($arg($a, 0), $arg($a, 1)));
        $exports['useOptimistic'] = $hook('useOptimistic', 2, static fn (Fizz\Hooks $h, array $a): JsValue => $h->useOptimistic($arg($a, 0)));
        $exports['useReducer'] = $hook(
            'useReducer',
            3,
            static fn (Fizz\Hooks $h, array $a): JsValue => $h->useReducer($arg($a, 0), $arg($a, 1), $arg($a, 2)),
        );
        $exports['useRef'] = $hook('useRef', 1, static fn (Fizz\Hooks $h, array $a): JsValue => $h->useRef($arg($a, 0)));
        $exports['useState'] = $hook('useState', 1, static fn (Fizz\Hooks $h, array $a): JsValue => $h->useState($arg($a, 0)));
        $exports['useSyncExternalStore'] = $hook(
            'useSyncExternalStore',
            3,
            static fn (Fizz\Hooks $h, array $a): JsValue => $h->useSyncExternalStore($arg($a, 0), $arg($a, 1), $arg($a, 2)),
        );
        $exports['useTransition'] = $hook('useTransition', 0, static fn (Fizz\Hooks $h): JsValue => $h->useTransition());
        $exports['version'] = new JsString(self::VERSION);
        return $exports;
    }

    /** @param list<JsValue> $args */
    private static function cloneElement(array $args): JsElement
    {
        $element = $args[0] ?? JsUndefined::instance();
        $config = $args[1] ?? JsUndefined::instance();
        if (Js::isNullish($element)) {
            throw Js::error('The argument must be a React element, but you passed ' . Js::str($element) . '.');
        }
        $props = new JsObject();
        $oldProps = Js::get($element, 'props');
        if ($oldProps instanceof JsObject) {
            foreach (Js::ownEnumerableKeys($oldProps) as $name) {
                $props->set($name, Js::get($oldProps, $name));
            }
        }
        $key = Js::get($element, 'key');
        if (!Js::isNullish($config)) {
            $configKey = Js::get($config, 'key');
            if (!$configKey instanceof JsUndefined) {
                $key = new JsString(Js::str($configKey));
            }
            if ($config instanceof JsObject) {
                foreach (Js::forInKeys($config) as $name) {
                    if (
                        !Js::hasOwn($config, $name)
                        || $name === 'key' || $name === '__self' || $name === '__source'
                        || ($name === 'ref' && Js::get($config, 'ref') instanceof JsUndefined)
                    ) {
                        continue;
                    }
                    $props->set($name, Js::get($config, $name));
                }
            }
        }
        $childrenLength = count($args) - 2;
        if ($childrenLength === 1) {
            $props->set('children', $args[2]);
        } elseif ($childrenLength > 1) {
            $props->set('children', new JsArray(array_slice($args, 2)));
        }
        return self::element(Js::get($element, 'type'), $key, $props);
    }

    private static function createContext(JsValue $defaultValue): JsObject
    {
        $context = Js::object([
            '$$typeof' => ReactSymbols::$context,
            '_currentValue' => $defaultValue,
            '_currentValue2' => $defaultValue,
            '_threadCount' => JsNumber::of(0),
            'Provider' => JsNull::instance(),
            'Consumer' => JsNull::instance(),
        ]);
        $context->set('Provider', $context);
        $context->set('Consumer', Js::object([
            '$$typeof' => ReactSymbols::$consumer,
            '_context' => $context,
        ]));
        return $context;
    }

    private static function lazyInitializer(JsValue $payload): JsValue
    {
        $status = Js::get($payload, '_status');
        if ($status instanceof JsNumber && $status->value === -1.0 && $payload instanceof JsObject) {
            $ctor = Js::get($payload, '_result');
            $thenable = Js::call($ctor, JsUndefined::instance());
            $settle = static function (int $code, string $field, string $state, JsValue $value) use ($payload, $thenable): void {
                $current = Js::get($payload, '_status');
                if ($current instanceof JsNumber && ($current->value === 0.0 || $current->value === -1.0)) {
                    $payload->set('_status', JsNumber::of($code));
                    $payload->set('_result', $value);
                    if ($thenable instanceof JsObject && Js::get($thenable, 'status') instanceof JsUndefined) {
                        $thenable->set('status', new JsString($state));
                        $thenable->set($field, $value);
                    }
                }
            };
            Js::call(Js::get($thenable, 'then'), $thenable, [
                Js::fn('', static function (JsValue $t, array $a) use ($settle): JsValue {
                    $settle(1, 'value', 'fulfilled', $a[0] ?? JsUndefined::instance());
                    return JsUndefined::instance();
                }, 1),
                Js::fn('', static function (JsValue $t, array $a) use ($settle): JsValue {
                    $settle(2, 'reason', 'rejected', $a[0] ?? JsUndefined::instance());
                    return JsUndefined::instance();
                }, 1),
            ]);
            $status = Js::get($payload, '_status');
            if ($status instanceof JsNumber && $status->value === -1.0) {
                $payload->set('_status', JsNumber::of(0));
                $payload->set('_result', $thenable);
            }
        }
        $status = Js::get($payload, '_status');
        if ($status instanceof JsNumber && $status->value === 1.0) {
            return Js::get(Js::get($payload, '_result'), 'default');
        }
        Js::throw(Js::get($payload, '_result'));
    }

    private static function startTransition(JsValue $scope): JsValue
    {
        try {
            $result = Js::call($scope, JsUndefined::instance());
            if ($result instanceof JsObject && Js::get($result, 'then') instanceof JsFunction) {
                $noop = Js::fn('', static fn (): JsValue => JsUndefined::instance());
                Js::call(Js::get($result, 'then'), $result, [$noop, self::reportGlobalError()]);
            }
        } catch (\Throwable $e) {
            Js::call(self::reportGlobalError(), JsUndefined::instance(), [Js::thrownValue($e)]);
        }
        return JsUndefined::instance();
    }

    private static function reportGlobalError(): JsValue
    {
        $interpreter = \Phasis\Engine::getCurrentInterpreter();
        $env = $interpreter?->getGlobalEnv();
        if ($env !== null && $env->has('reportError')) {
            return $env->get('reportError');
        }
        return Js::get($env?->get('console') ?? Js::object(), 'error');
    }

    /** @return array{0: JsFunction, 1: JsFunction} */
    private function componentClasses(): array
    {
        $emptyObject = new JsObject();
        $noopUpdater = Js::object([
            'isMounted' => Js::fn('isMounted', static fn (): JsValue => JsBoolean::of(false)),
            'enqueueForceUpdate' => Js::fn('enqueueForceUpdate', static fn (): JsValue => JsUndefined::instance()),
            'enqueueReplaceState' => Js::fn('enqueueReplaceState', static fn (): JsValue => JsUndefined::instance()),
            'enqueueSetState' => Js::fn('enqueueSetState', static fn (): JsValue => JsUndefined::instance()),
        ]);
        $init = static function (JsValue $this_, array $args) use ($emptyObject, $noopUpdater): JsValue {
            if ($this_ instanceof JsObject) {
                $this_->set('props', $args[0] ?? JsUndefined::instance());
                $this_->set('context', $args[1] ?? JsUndefined::instance());
                $this_->set('refs', $emptyObject);
                $updater = $args[2] ?? JsUndefined::instance();
                $this_->set('updater', $updater->toBoolean() ? $updater : $noopUpdater);
            }
            return JsUndefined::instance();
        };

        $component = Js::fn('Component', $init, 3)->setConstructable();
        $proto = new JsObject();
        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($component, true, false, true));
        $proto->set('isReactComponent', new JsObject());
        $proto->set('setState', Js::fn('', static function (JsValue $this_, array $args): JsValue {
            $partialState = $args[0] ?? JsUndefined::instance();
            if (!$partialState instanceof JsObject && !Js::isNullish($partialState)) {
                throw Js::error('takes an object of state variables to update or a function which returns an object of state variables.');
            }
            $updater = Js::get($this_, 'updater');
            Js::call(
                Js::get($updater, 'enqueueSetState'),
                $updater,
                [$this_, $partialState, $args[1] ?? JsUndefined::instance(), new JsString('setState')],
            );
            return JsUndefined::instance();
        }, 2));
        $proto->set('forceUpdate', Js::fn('', static function (JsValue $this_, array $args): JsValue {
            $updater = Js::get($this_, 'updater');
            Js::call(Js::get($updater, 'enqueueForceUpdate'), $updater, [$this_, $args[0] ?? JsUndefined::instance(), new JsString('forceUpdate')]);
            return JsUndefined::instance();
        }, 1));
        $component->defineOwnProperty('prototype', PropertyDescriptor::data($proto, true, false, false));

        $pure = Js::fn('PureComponent', $init, 3)->setConstructable();
        $pureProto = new JsObject($proto);
        $pureProto->set('constructor', $pure);
        foreach (['isReactComponent', 'setState', 'forceUpdate'] as $name) {
            $pureProto->set($name, $proto->get($name));
        }
        $pureProto->set('isPureReactComponent', JsBoolean::of(true));
        $pure->defineOwnProperty('prototype', PropertyDescriptor::data($pureProto, true, false, false));
        return [$component, $pure];
    }

    private function children(): JsObject
    {
        $undefined = JsUndefined::instance();
        $map = static function (JsValue $children, \Closure $func): JsValue {
            if (Js::isNullish($children)) {
                return $children;
            }
            $result = [];
            $count = 0;
            self::mapIntoArray($children, $result, '', '', static function (JsValue $child) use ($func, &$count): JsValue {
                return $func($child, $count++);
            });
            return new JsArray($result);
        };
        return Js::object([
            'map' => Js::fn('mapChildren', static function (JsValue $this_, array $args) use ($map, $undefined): JsValue {
                $func = $args[1] ?? $undefined;
                $context = $args[2] ?? $undefined;
                return $map(
                    $args[0] ?? $undefined,
                    static fn (JsValue $child, int $i): JsValue => Js::call($func, $context, [$child, JsNumber::of($i)]),
                );
            }, 3),
            'forEach' => Js::fn('forEach', static function (JsValue $this_, array $args) use ($map, $undefined): JsValue {
                $func = $args[1] ?? $undefined;
                $context = $args[2] ?? $undefined;
                $map($args[0] ?? $undefined, static function (JsValue $child, int $i) use ($func, $context, $undefined): JsValue {
                    Js::call($func, $context, [$child, JsNumber::of($i)]);
                    return $undefined;
                });
                return $undefined;
            }, 3),
            'count' => Js::fn('count', static function (JsValue $this_, array $args) use ($map, $undefined): JsValue {
                $n = 0;
                $map($args[0] ?? $undefined, static function () use (&$n, $undefined): JsValue {
                    $n++;
                    return $undefined;
                });
                return JsNumber::of($n);
            }, 1),
            'toArray' => Js::fn('toArray', static function (JsValue $this_, array $args) use ($map, $undefined): JsValue {
                $result = $map($args[0] ?? $undefined, static fn (JsValue $child): JsValue => $child);
                return Js::isNullish($result) ? new JsArray() : $result;
            }, 1),
            'only' => Js::fn('only', static function (JsValue $this_, array $args) use ($undefined): JsValue {
                $children = $args[0] ?? $undefined;
                if (!self::isValidElement($children)) {
                    throw Js::error('React.Children.only expected to receive a single React element child.');
                }
                return $children;
            }, 1),
        ]);
    }

    /**
     * The children React.Children.forEach visits: arrays and iterables
     * flattened, lazy elements and thenables resolved.
     *
     * @return list<JsValue>
     */
    public static function childrenToArray(JsValue $children): array
    {
        if (Js::isNullish($children)) {
            return [];
        }
        $leaves = [];
        $result = [];
        self::mapIntoArray($children, $result, '', '', static function (JsValue $child) use (&$leaves): JsValue {
            $leaves[] = $child;
            return JsUndefined::instance();
        });
        return $leaves;
    }

    private static function escapeKey(string $key): string
    {
        return '$' . strtr($key, ['=' => '=0', ':' => '=2']);
    }

    private static function getElementKey(JsValue $element, int $index): string
    {
        if ($element instanceof JsObject) {
            $key = Js::get($element, 'key');
            if (!Js::isNullish($key)) {
                return self::escapeKey(Js::str($key));
            }
        }
        return base_convert((string) $index, 10, 36);
    }

    private static function escapeUserProvidedKey(string $text): string
    {
        return (string) preg_replace('#/+#', '$0/', $text);
    }

    /**
     * React's mapIntoArray.
     *
     * @param list<JsValue> $array
     * @param \Closure(JsValue): JsValue $callback
     */
    private static function mapIntoArray(JsValue $children, array &$array, string $escapedPrefix, string $nameSoFar, \Closure $callback): int
    {
        if ($children instanceof JsUndefined || $children instanceof JsBoolean) {
            $children = JsNull::instance();
        }
        $invokeCallback = false;
        if ($children instanceof JsNull || $children instanceof JsString || $children instanceof JsNumber || $children instanceof JsBigInt) {
            $invokeCallback = true;
        } elseif ($children instanceof JsObject && !$children instanceof JsFunction) {
            $typeof = Js::get($children, '$$typeof');
            if ($typeof === ReactSymbols::$element || $typeof === ReactSymbols::$portal) {
                $invokeCallback = true;
            } elseif ($typeof === ReactSymbols::$lazy) {
                $init = Js::get($children, '_init');
                return self::mapIntoArray(
                    Js::call($init, JsUndefined::instance(), [Js::get($children, '_payload')]),
                    $array,
                    $escapedPrefix,
                    $nameSoFar,
                    $callback,
                );
            }
        }
        if ($invokeCallback) {
            $mapped = $callback($children);
            $childKey = $nameSoFar === '' ? '.' . self::getElementKey($children, 0) : $nameSoFar;
            if (Js::isArray($mapped)) {
                $prefix = self::escapeUserProvidedKey($childKey) . '/';
                self::mapIntoArray($mapped, $array, $prefix, '', static fn (JsValue $c): JsValue => $c);
            } elseif (!Js::isNullish($mapped)) {
                if (self::isValidElement($mapped)) {
                    $mappedKey = Js::get($mapped, 'key');
                    $keepKey = Js::isNullish($mappedKey)
                        || ($children->toBoolean() && Js::same(Js::get($children, 'key'), $mappedKey));
                    $mapped = self::element(
                        Js::get($mapped, 'type'),
                        new JsString($escapedPrefix . ($keepKey ? '' : self::escapeUserProvidedKey(Js::str($mappedKey)) . '/') . $childKey),
                        self::propsOf($mapped),
                    );
                }
                $array[] = $mapped;
            }
            return 1;
        }
        $subtreeCount = 0;
        $nextNamePrefix = $nameSoFar === '' ? '.' : $nameSoFar . ':';
        if (Js::isArray($children)) {
            foreach (Js::elements($children) as $i => $child) {
                $subtreeCount += self::mapIntoArray($child, $array, $escapedPrefix, $nextNamePrefix . self::getElementKey($child, $i), $callback);
            }
            return $subtreeCount;
        }
        $iteratorFn = self::getIteratorFn($children);
        if ($iteratorFn !== null) {
            $iterator = Js::call($iteratorFn, $children);
            $i = 0;
            while (true) {
                $step = Js::call(Js::get($iterator, 'next'), $iterator);
                if (Js::get($step, 'done')->toBoolean()) {
                    break;
                }
                $child = Js::get($step, 'value');
                $subtreeCount += self::mapIntoArray($child, $array, $escapedPrefix, $nextNamePrefix . self::getElementKey($child, $i++), $callback);
            }
            return $subtreeCount;
        }
        if ($children instanceof JsObject) {
            if (Js::get($children, 'then') instanceof JsFunction) {
                return self::mapIntoArray(self::resolveThenable($children), $array, $escapedPrefix, $nameSoFar, $callback);
            }
            $tag = Js::str($children);
            throw Js::error(
                'Objects are not valid as a React child (found: '
                . ($tag === '[object Object]' ? 'object with keys {' . implode(', ', Js::ownEnumerableKeys($children)) . '}' : $tag)
                . '). If you meant to render a collection of children, use an array instead.'
            );
        }
        return $subtreeCount;
    }

    private static function propsOf(JsValue $element): JsObject
    {
        $props = Js::get($element, 'props');
        return $props instanceof JsObject ? $props : new JsObject();
    }

    /** React's getIteratorFn. */
    public static function getIteratorFn(JsValue $maybeIterable): ?JsFunction
    {
        if (!$maybeIterable instanceof JsObject || $maybeIterable instanceof JsFunction) {
            return null;
        }
        $fn = $maybeIterable->getBySymbol(SymbolConstructor::iterator());
        if (!$fn->toBoolean()) {
            $fn = Js::get($maybeIterable, '@@iterator');
        }
        return $fn instanceof JsFunction ? $fn : null;
    }

    private static function resolveThenable(JsObject $thenable): JsValue
    {
        $status = Js::get($thenable, 'status');
        if ($status instanceof JsString) {
            if ($status->value === 'fulfilled') {
                return Js::get($thenable, 'value');
            }
            if ($status->value === 'rejected') {
                Js::throw(Js::get($thenable, 'reason'));
            }
        }
        $noop = Js::fn('', static fn (): JsValue => JsUndefined::instance());
        if ($status instanceof JsString) {
            Js::call(Js::get($thenable, 'then'), $thenable, [$noop, $noop]);
        } else {
            $thenable->set('status', new JsString('pending'));
            Js::call(Js::get($thenable, 'then'), $thenable, [
                Js::fn('', static function (JsValue $t, array $a) use ($thenable): JsValue {
                    if (Js::str(Js::get($thenable, 'status')) === 'pending') {
                        $thenable->set('status', new JsString('fulfilled'));
                        $thenable->set('value', $a[0] ?? JsUndefined::instance());
                    }
                    return JsUndefined::instance();
                }, 1),
                Js::fn('', static function (JsValue $t, array $a) use ($thenable): JsValue {
                    if (Js::str(Js::get($thenable, 'status')) === 'pending') {
                        $thenable->set('status', new JsString('rejected'));
                        $thenable->set('reason', $a[0] ?? JsUndefined::instance());
                    }
                    return JsUndefined::instance();
                }, 1),
            ]);
        }
        $status = Js::str(Js::get($thenable, 'status'));
        if ($status === 'fulfilled') {
            return Js::get($thenable, 'value');
        }
        if ($status === 'rejected') {
            Js::throw(Js::get($thenable, 'reason'));
        }
        Js::throw($thenable);
    }
}
