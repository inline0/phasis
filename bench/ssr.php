<?php

/**
 * SSR bench: bench/ssr.jsx rendered five ways inside Phasis.
 *
 *   native     the built-in React 19.3 profile (Engine::render)
 *   precompile native, with JsxMode::Precompile
 *   real-react real React 19.3 (vendored edge build) through an import map
 *   html       the phasis HTML profile
 *   floor      the components alone: every component called and every
 *              element created, no HTML produced
 *
 * Load is the time to import the page's module graph on a fresh engine
 * (after a warm-up engine has loaded Phasis's own PHP classes); render is
 * the median of warm renders on that engine. Targets (PRD Layer 10, as ratios so they
 * hold across machines): native renders at least 10x faster than real
 * React and within 1.5x of the floor.
 *
 *   php -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M bench/ssr.php [runs]
 */

declare(strict_types=1);

use Phasis\BuiltIn\Jsx\Js;
use Phasis\BuiltIn\Jsx\React\Fizz\Hooks;
use Phasis\BuiltIn\Jsx\React\ReactModule;
use Phasis\BuiltIn\Jsx\React\ReactSymbols;
use Phasis\Engine;
use Phasis\Interop\PhpToJs;
use Phasis\Parser\Jsx\JsxMode;
use Phasis\Parser\Jsx\JsxOptions;
use Phasis\RenderProfile;
use Phasis\Value\JsElement;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsValue;

require __DIR__ . '/../vendor/autoload.php';
ini_set('memory_limit', '2G');
set_time_limit(0);

const PAGE = __DIR__ . '/ssr.jsx';
$runs = max(3, (int) ($argv[1] ?? 7));

/** Deterministic page data. @return array<string, mixed> */
function pageProps(): array
{
    $posts = [];
    for ($i = 1; $i <= 100; $i++) {
        $posts[] = [
            'id' => $i,
            'slug' => "post-{$i}",
            'title' => "Post number {$i}: rendering <JSX> & PHP",
            'author' => ['Ada Lovelace', 'Grace Hopper', 'Alan Turing'][$i % 3],
            'date' => sprintf('2026-10-%02d', 1 + $i % 28),
            'excerpt' => "Excerpt {$i}. Server-rendered components, byte-identical to React 19.3, hydrated on the client.",
            'comments' => $i * 3 % 17,
            'featured' => $i % 10 === 0,
            'verified' => $i % 4 === 0,
            'tags' => ['php', 'react', 'ssr'],
        ];
    }
    return [
        'site' => ['name' => 'Phasis Blog', 'tagline' => 'JavaScript in PHP', 'year' => 2026],
        'links' => [['href' => '/', 'label' => 'Home'], ['href' => '/posts', 'label' => 'Posts'], ['href' => '/about', 'label' => 'About']],
        'posts' => $posts,
    ];
}

/**
 * @param \Closure(): Engine $makeEngine
 * @param \Closure(Engine): void $load Imports the module graph.
 * @param \Closure(Engine): string $render
 * @return array{0: float, 1: float, 2: int} [load ms, median render ms, html bytes]
 */
function measure(Closure $makeEngine, Closure $load, Closure $render, int $runs): array
{
    // Warm-up: PHP compiles and autoloads Phasis's classes on first use.
    $warm = $makeEngine();
    $load($warm);
    $render($warm);
    $engine = $makeEngine();
    $t = hrtime(true);
    $load($engine);
    $loadMs = (hrtime(true) - $t) / 1e6;
    $html = $render($engine);
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $t = hrtime(true);
        $render($engine);
        $times[] = (hrtime(true) - $t) / 1e6;
    }
    sort($times);
    return [$loadMs, $times[intdiv(count($times), 2)], strlen($html)];
}

/** Import a module graph on an engine (what Engine::render does first). */
function importPage(Engine $engine, string $path): JsObject
{
    $namespace = (new ReflectionMethod(Engine::class, 'importModule'))->invoke($engine, $path);
    \Phasis\Engine::setCurrentInterpreter($engine->getInterpreter());
    return $namespace;
}

$props = pageProps();
$results = [];

$loadPage = static function (Engine $e): void {
    importPage($e, PAGE);
};

$results['native'] = measure(
    static fn (): Engine => new Engine(),
    $loadPage,
    static fn (Engine $e): string => $e->render(PAGE, $props),
    $runs,
);

$results['precompile'] = measure(
    static function (): Engine {
        $e = new Engine();
        $e->setJsxOptions(new JsxOptions(mode: JsxMode::Precompile));
        return $e;
    },
    $loadPage,
    static fn (Engine $e): string => $e->render(PAGE, $props),
    $runs,
);

$results['html'] = measure(
    static fn (): Engine => new Engine(),
    $loadPage,
    static fn (Engine $e): string => $e->render(PAGE, $props, 'default', RenderProfile::Html),
    $runs,
);

// Real React: the vendored edge build, rendering with its own renderToString.
$vendor = __DIR__ . '/../tests/Jsx/vendor/react-19.3.0/';
$results['real-react'] = measure(
    static function () use ($vendor, $props): Engine {
        $e = new Engine();
        $e->setImportMap([
            'react' => $vendor . 'react.mjs',
            'react/jsx-runtime' => $vendor . 'jsx-runtime.mjs',
            'react-dom' => $vendor . 'react-dom.mjs',
            'react-dom/server' => $vendor . 'server.mjs',
        ]);
        $e->setGlobal('__benchProps', $props);
        return $e;
    },
    static function (Engine $e): void {
        $e->evalAsModule(
            "import Page from './ssr.jsx';\nimport { createElement } from 'react';\nimport { renderToString } from 'react-dom/server';\n"
            . 'globalThis.__benchRender = () => renderToString(createElement(Page, globalThis.__benchProps));',
            __DIR__ . '/__bench_real_react.mjs',
        );
    },
    static fn (Engine $e): string => (string) $e->eval('globalThis.__benchRender()'),
    $runs,
);

// The floor: call every component and create every element, produce no HTML.
$results['floor'] = measure(
    static fn (): Engine => new Engine(),
    $loadPage,
    static function (Engine $e) use ($props): string {
        $page = importPage($e, PAGE)->get('default');
        $walk = static function (JsValue $node) use (&$walk): void {
            if ($node instanceof JsElement || ($node instanceof JsObject && Js::get($node, '$$typeof') === ReactSymbols::$element)) {
                $type = Js::get($node, 'type');
                $props = Js::get($node, 'props');
                if ($type instanceof JsFunction && $props instanceof JsObject) {
                    $walk(Hooks::renderStandalone($type, $props, new JsObject(), static fn (): string => 'id'));
                    return;
                }
                if ($type instanceof JsObject && !$type instanceof JsFunction && $props instanceof JsObject) {
                    $typeof = Js::get($type, '$$typeof');
                    if ($typeof === ReactSymbols::$forwardRef) {
                        $walk(Hooks::renderStandalone(Js::get($type, 'render'), $props, Js::get($props, 'ref'), static fn (): string => 'id'));
                        return;
                    }
                    if ($typeof === ReactSymbols::$memo) {
                        $walk(ReactModule::element(Js::get($type, 'type'), Js::get($node, 'key'), $props));
                        return;
                    }
                    // A context provider: render its children with the value in place.
                    $previous = Js::get($type, '_currentValue');
                    $type->set('_currentValue', Js::get($props, 'value'));
                    $walk(Js::get($props, 'children'));
                    $type->set('_currentValue', $previous);
                    return;
                }
                if ($props instanceof JsObject) {
                    $walk(Js::get($props, 'children'));
                }
                return;
            }
            if (Js::isArray($node)) {
                foreach (Js::elements($node) as $child) {
                    $walk($child);
                }
            }
        };
        $previous = ReactModule::$dispatcher;
        ReactModule::$dispatcher = Hooks::dispatcher();
        try {
            $walk(ReactModule::element($page, \Phasis\Value\JsNull::instance(), PhpToJs::convert($props)));
        } finally {
            ReactModule::$dispatcher = $previous;
        }
        return '';
    },
    $runs,
);

printf("%-11s %10s %10s %8s\n", 'variant', 'load ms', 'render ms', 'bytes');
foreach ($results as $name => [$load, $render, $bytes]) {
    printf("%-11s %10.1f %10.1f %8d\n", $name, $load, $render, $bytes);
}
$speedup = $results['real-react'][1] / $results['native'][1];
$floorRatio = $results['native'][1] / $results['floor'][1];
$precompileGain = $results['native'][1] / $results['precompile'][1];
printf("\nnative vs real React: %.1fx faster (target >= 10x)\n", $speedup);
printf("native vs floor:      %.2fx (target <= 1.5x)\n", $floorRatio);
printf("precompile vs native: %.2fx faster\n", $precompileGain);
$native = $results['native'];
exit($speedup >= 10 && $floorRatio <= 1.5 ? 0 : 1);
