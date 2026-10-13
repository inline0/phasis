<?php

/**
 * Native `react-dom`, `react-dom/server` and `react-dom/static` for
 * React 19.3.0, ported from react-dom.production.js and the Node entry
 * points of react-dom's server builds.
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
use Phasis\BuiltIn\Jsx\React\Fizz\Hooks;
use Phasis\BuiltIn\Jsx\React\Fizz\Request;
use Phasis\BuiltIn\Jsx\React\Fizz\Resources;
use Phasis\BuiltIn\Jsx\React\Fizz\Server;
use Phasis\Engine;
use Phasis\Exceptions\TypeError;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

final class ReactDomModule
{
    /** @return array<string, JsValue> */
    public static function reactDomExports(): array
    {
        ReactSymbols::init();
        $undefined = JsUndefined::instance();
        $arg = static fn (array $args, int $i): JsValue => $args[$i] ?? $undefined;
        $str = static fn (JsValue $v): ?JsString => $v instanceof JsString ? $v : null;
        $optional = static fn (JsValue $options, string $name): JsValue => $str(Js::get($options, $name)) ?? $undefined;
        $crossOriginAs = static function (JsValue $as, JsValue $input) use ($undefined): JsValue {
            if ($as instanceof JsString && $as->value === 'font') {
                return new JsString('');
            }
            if ($input instanceof JsString) {
                return $input->value === 'use-credentials' ? $input : new JsString('');
            }
            return $undefined;
        };

        return [
            'browser' => Js::fn('browser', static fn (JsValue $this_, array $args): JsValue => Js::object([
                '$$typeof' => ReactSymbols::$recoverable,
                '_reason' => $arg($args, 0),
            ]), 1),
            'createPortal' => Js::fn('createPortal', static function (JsValue $this_, array $args) use ($arg): JsValue {
                $container = $arg($args, 1);
                $nodeType = $container->toBoolean() ? Js::get($container, 'nodeType') : JsUndefined::instance();
                $valid = $nodeType instanceof JsNumber && in_array($nodeType->value, [1.0, 9.0, 11.0], true);
                if (!$valid) {
                    throw Js::error(self::minifiedError(299));
                }
                $key = count($args) > 2 && !$args[2] instanceof JsUndefined ? $args[2] : JsNull::instance();
                return Js::object([
                    '$$typeof' => ReactSymbols::$portal,
                    'key' => Js::isNullish($key) ? JsNull::instance() : ($key === ReactSymbols::$optimisticKey ? $key : new JsString(Js::str($key))),
                    'children' => $arg($args, 0),
                    'containerInfo' => $container,
                    'implementation' => JsNull::instance(),
                ]);
            }, 2),
            'flushSync' => Js::fn('flushSync', static function (JsValue $this_, array $args) use ($arg): JsValue {
                $fn = $arg($args, 0);
                return $fn->toBoolean() ? Js::call($fn, JsUndefined::instance()) : JsUndefined::instance();
            }, 1),
            'preconnect' => Js::fn('preconnect', static function (JsValue $this_, array $args) use ($arg, $undefined): JsValue {
                $href = $arg($args, 0);
                $options = $arg($args, 1);
                if ($href instanceof JsString) {
                    $crossOrigin = JsNull::instance();
                    if ($options->toBoolean()) {
                        $input = Js::get($options, 'crossOrigin');
                        $crossOrigin = $input instanceof JsString
                            ? ($input->value === 'use-credentials' ? $input : new JsString(''))
                            : $undefined;
                    }
                    Resources::preconnect($href, $crossOrigin);
                }
                return $undefined;
            }, 2),
            'prefetchDNS' => Js::fn('prefetchDNS', static function (JsValue $this_, array $args) use ($arg, $undefined): JsValue {
                $href = $arg($args, 0);
                if ($href instanceof JsString) {
                    Resources::prefetchDNS($href);
                }
                return $undefined;
            }, 1),
            'preinit' => Js::fn('preinit', static function (JsValue $this_, array $args) use ($arg, $optional, $crossOriginAs, $undefined): JsValue {
                $href = $arg($args, 0);
                $options = $arg($args, 1);
                if (!$href instanceof JsString || !$options->toBoolean()) {
                    return $undefined;
                }
                $as = Js::get($options, 'as');
                if (!$as instanceof JsString) {
                    return $undefined;
                }
                $crossOrigin = $crossOriginAs($as, Js::get($options, 'crossOrigin'));
                $integrity = $optional($options, 'integrity');
                $fetchPriority = $optional($options, 'fetchPriority');
                if ($as->value === 'style') {
                    Resources::preinitStyle($href, $optional($options, 'precedence'), Js::object([
                        'crossOrigin' => $crossOrigin,
                        'integrity' => $integrity,
                        'fetchPriority' => $fetchPriority,
                    ]));
                } elseif ($as->value === 'script') {
                    Resources::preinitScript($href, Js::object([
                        'crossOrigin' => $crossOrigin,
                        'integrity' => $integrity,
                        'fetchPriority' => $fetchPriority,
                        'nonce' => $optional($options, 'nonce'),
                    ]), false);
                }
                return $undefined;
            }, 2),
            'preinitModule' => Js::fn('preinitModule', static function (
                JsValue $this_,
                array $args,
            ) use (
                $arg,
                $optional,
                $crossOriginAs,
                $undefined,
            ): JsValue {
                $href = $arg($args, 0);
                $options = $arg($args, 1);
                if (!$href instanceof JsString) {
                    return $undefined;
                }
                if ($options instanceof JsObject && !$options instanceof JsFunction) {
                    $as = Js::get($options, 'as');
                    if (Js::isNullish($as) || ($as instanceof JsString && $as->value === 'script')) {
                        Resources::preinitScript($href, Js::object([
                            'crossOrigin' => $crossOriginAs($as, Js::get($options, 'crossOrigin')),
                            'integrity' => $optional($options, 'integrity'),
                            'nonce' => $optional($options, 'nonce'),
                            'fetchPriority' => $optional($options, 'fetchPriority'),
                        ]), true);
                    }
                } elseif (Js::isNullish($options)) {
                    Resources::preinitScript($href, $undefined, true);
                }
                return $undefined;
            }, 2),
            'preload' => Js::fn('preload', static function (JsValue $this_, array $args) use ($arg, $optional, $crossOriginAs, $undefined): JsValue {
                $href = $arg($args, 0);
                $options = $arg($args, 1);
                if (!$href instanceof JsString || !$options instanceof JsObject || $options instanceof JsFunction) {
                    return $undefined;
                }
                $as = Js::get($options, 'as');
                if (!$as instanceof JsString) {
                    return $undefined;
                }
                Resources::preload($href, $as, Js::object([
                    'crossOrigin' => $crossOriginAs($as, Js::get($options, 'crossOrigin')),
                    'integrity' => $optional($options, 'integrity'),
                    'nonce' => $optional($options, 'nonce'),
                    'type' => $optional($options, 'type'),
                    'fetchPriority' => $optional($options, 'fetchPriority'),
                    'referrerPolicy' => $optional($options, 'referrerPolicy'),
                    'imageSrcSet' => $optional($options, 'imageSrcSet'),
                    'imageSizes' => $optional($options, 'imageSizes'),
                    'media' => $optional($options, 'media'),
                ]));
                return $undefined;
            }, 2),
            'preloadModule' => Js::fn('preloadModule', static function (
                JsValue $this_,
                array $args,
            ) use (
                $arg,
                $optional,
                $crossOriginAs,
                $undefined,
            ): JsValue {
                $href = $arg($args, 0);
                $options = $arg($args, 1);
                if (!$href instanceof JsString) {
                    return $undefined;
                }
                if ($options->toBoolean()) {
                    $as = Js::get($options, 'as');
                    Resources::preloadModule($href, Js::object([
                        'as' => $as instanceof JsString && $as->value !== 'script' ? $as : $undefined,
                        'crossOrigin' => $crossOriginAs($as, Js::get($options, 'crossOrigin')),
                        'integrity' => $optional($options, 'integrity'),
                        'nonce' => $optional($options, 'nonce'),
                        'fetchPriority' => $optional($options, 'fetchPriority'),
                    ]));
                } else {
                    Resources::preloadModule($href, $undefined);
                }
                return $undefined;
            }, 2),
            'requestFormReset' => Js::fn('requestFormReset', static function (): JsValue {
                throw Js::error(self::minifiedError(522));
            }, 1),
            'unstable_batchedUpdates' => Js::fn(
                'unstable_batchedUpdates',
                static fn (JsValue $this_, array $args): JsValue => Js::call($arg($args, 0), JsUndefined::instance(), [$arg($args, 1)]),
                2,
            ),
            'useFormState' => Js::fn('useFormState', static function (JsValue $this_, array $args) use ($arg): JsValue {
                $dispatcher = ReactModule::$dispatcher;
                if ($dispatcher === null) {
                    throw new TypeError("Cannot read properties of null (reading 'useFormState')");
                }
                return $dispatcher->useActionState($arg($args, 0), $arg($args, 1), $arg($args, 2));
            }, 3),
            'useFormStatus' => Js::fn('useFormStatus', static function (): JsValue {
                $dispatcher = ReactModule::$dispatcher;
                if ($dispatcher === null) {
                    throw new TypeError("Cannot read properties of null (reading 'useHostTransitionStatus')");
                }
                return $dispatcher->useHostTransitionStatus();
            }),
            'version' => new JsString(ReactModule::VERSION),
        ];
    }

    /** @return array<string, JsValue> */
    public static function serverExports(): array
    {
        return [
            'version' => new JsString(ReactModule::VERSION),
            'renderToString' => Js::fn('renderToString', static fn (JsValue $this_, array $args): JsValue => new JsString(self::normalize(
                Server::renderToString($args[0] ?? JsUndefined::instance(), $args[1] ?? JsUndefined::instance(), false),
            )), 2),
            'renderToStaticMarkup' => Js::fn('renderToStaticMarkup', static fn (JsValue $this_, array $args): JsValue => new JsString(self::normalize(
                Server::renderToString($args[0] ?? JsUndefined::instance(), $args[1] ?? JsUndefined::instance(), true),
            )), 2),
            'renderToPipeableStream' => self::unsupported('renderToPipeableStream'),
            'renderToReadableStream' => self::unsupported('renderToReadableStream'),
            'resumeToPipeableStream' => self::unsupported('resumeToPipeableStream'),
            'resume' => self::unsupported('resume'),
        ];
    }

    /** @return array<string, JsValue> */
    public static function staticExports(): array
    {
        return [
            'version' => new JsString(ReactModule::VERSION),
            'prerenderToNodeStream' => self::unsupported('prerenderToNodeStream'),
            'prerender' => Js::fn('prerender', static fn (JsValue $this_, array $args): JsValue => self::prerender(
                $args[0] ?? JsUndefined::instance(),
                $args[1] ?? JsUndefined::instance(),
            ), 2),
            'resumeAndPrerenderToNodeStream' => self::unsupported('resumeAndPrerenderToNodeStream'),
            'resumeAndPrerender' => self::unsupported('resumeAndPrerender'),
        ];
    }

    /** react-dom/static prerender: a promise of `{ postponed, prelude }`. */
    private static function prerender(JsValue $children, JsValue $options): JsPromise
    {
        $promise = new JsPromise();
        try {
            Server::prerender(
                $children,
                $options,
                static function (Request $request) use ($promise): void {
                    $html = self::normalize(Server::flushToString($request));
                    $promise->resolve(Js::object([
                        'postponed' => JsNull::instance(),
                        'prelude' => self::byteStream($html),
                    ]));
                },
                static function (JsValue $error) use ($promise): void {
                    $promise->reject($error);
                },
            );
        } catch (\Throwable $e) {
            $promise->reject(Js::thrownValue($e));
        }
        return $promise;
    }

    /** A ReadableStream with the UTF-8 bytes of $html, encoded as TextEncoder does. */
    private static function byteStream(string $html): JsValue
    {
        $env = Engine::getCurrentInterpreter()?->getGlobalEnv();
        $encoderCtor = $env?->get('TextEncoder');
        $streamCtor = $env?->get('ReadableStream');
        $encoder = Js::construct($encoderCtor ?? JsUndefined::instance());
        $bytes = Js::call(Js::get($encoder, 'encode'), $encoder, [new JsString($html)]);
        $source = Js::object([
            'start' => Js::fn('start', static function (JsValue $this_, array $args) use ($bytes): JsValue {
                $controller = $args[0] ?? JsUndefined::instance();
                if (Js::str(Js::get($bytes, 'length')) !== '0') {
                    Js::call(Js::get($controller, 'enqueue'), $controller, [$bytes]);
                }
                Js::call(Js::get($controller, 'close'), $controller);
                return JsUndefined::instance();
            }, 1),
        ]);
        return Js::construct($streamCtor ?? JsUndefined::instance(), [$source]);
    }

    /**
     * Joins surrogate halves that ended up in separate chunks back into one
     * code point, as concatenating the chunks in JavaScript would.
     */
    public static function normalize(string $html): string
    {
        if (!str_contains($html, "\xED")) {
            return $html;
        }
        return (string) preg_replace_callback(
            '/\xED([\xA0-\xAF])([\x80-\xBF])\xED([\xB0-\xBF])([\x80-\xBF])/',
            static function (array $m): string {
                $high = 0xD000 | ((ord($m[1]) & 0x3F) << 6) | (ord($m[2]) & 0x3F);
                $low = 0xD000 | ((ord($m[3]) & 0x3F) << 6) | (ord($m[4]) & 0x3F);
                return mb_chr(0x10000 + (($high - 0xD800) << 10) + ($low - 0xDC00), 'UTF-8');
            },
            $html,
        );
    }

    private static function unsupported(string $name): JsFunction
    {
        return Js::fn($name, static function () use ($name): JsValue {
            throw new TypeError(
                "{$name}() is not supported by Phasis: streaming and resuming renders are out of scope. Use "
                . "renderToString, renderToStaticMarkup or prerender.",
            );
        }, 2);
    }

    private static function minifiedError(int $code): string
    {
        return 'Minified React error #' . $code . '; visit https://react.dev/errors/' . $code
            . ' for the full message or use the non-minified dev environment for full errors and additional helpful warnings.';
    }

    /** Keeps the hooks dispatcher class loaded with the module. */
    public static function dispatcher(): Hooks
    {
        return Hooks::dispatcher();
    }
}
