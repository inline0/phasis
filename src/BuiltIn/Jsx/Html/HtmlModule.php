<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Jsx\Html;

use Phasis\BuiltIn\Jsx\Js;
use Phasis\BuiltIn\Jsx\React\ReactModule;
use Phasis\BuiltIn\Jsx\React\ReactSymbols;
use Phasis\Value\JsElement;
use Phasis\Value\JsNull;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Native `phasis` and `phasis/jsx-runtime`: the general JSX renderer for
 * server-only HTML. Elements are the shared React 19 element model; hooks
 * and context are the React profile's, so components can move between the
 * two profiles. Only serialization differs.
 */
final class HtmlModule
{
    /** @return array<string, JsValue> */
    public static function exports(ReactModule $react): array
    {
        $reactExports = $react->exports();
        $exports = [
            'createElement' => $reactExports['createElement'],
            'Fragment' => ReactSymbols::$fragment,
            'createContext' => $reactExports['createContext'],
        ];
        foreach (
            [
                'use', 'useCallback', 'useContext', 'useDebugValue', 'useDeferredValue', 'useEffect', 'useId',
                'useImperativeHandle', 'useInsertionEffect', 'useLayoutEffect', 'useMemo', 'useReducer', 'useRef',
                'useState', 'useSyncExternalStore', 'useTransition',
            ] as $hook
        ) {
            $exports[$hook] = $reactExports[$hook];
        }
        $exports['renderToString'] = Js::fn('renderToString', static fn (JsValue $this_, array $args): JsValue => Js::string(
            HtmlRenderer::renderToString($args[0] ?? JsUndefined::instance(), $args[1] ?? JsUndefined::instance()),
        ), 2);
        $renderAsync = static fn (JsValue $this_, array $args): JsValue => HtmlRenderer::renderToStringAsync(
            $args[0] ?? JsUndefined::instance(),
            $args[1] ?? JsUndefined::instance(),
        );
        $exports['renderToStringAsync'] = Js::fn('renderToStringAsync', $renderAsync, 2);
        $exports['raw'] = Js::fn('raw', static fn (JsValue $this_, array $args): JsValue => new JsElement(
            HtmlRenderer::rawSymbol(),
            JsNull::instance(),
            JsNull::instance(),
            Js::object(['__html' => Js::string(Js::str($args[0] ?? Js::string('')))]),
        ), 1);
        return $exports;
    }

    /** @return array<string, JsValue> */
    public static function jsxRuntimeExports(ReactModule $react): array
    {
        return $react->jsxRuntimeExports();
    }
}
