<?php

/**
 * The general HTML profile: serialization ported from preact-render-to-string
 * 6.8.1 (src/index.js and src/lib/util.js).
 *
 * Copyright (c) 2015 Jason Miller
 *
 * This source code is licensed under the MIT license found in
 * third-party/preact-render-to-string/LICENSE in Phasis.
 */

declare(strict_types=1);

namespace Phasis\BuiltIn\Jsx\Html;

use Phasis\BuiltIn\Jsx\Js;
use Phasis\BuiltIn\Jsx\Precompile\Template;
use Phasis\BuiltIn\Jsx\React\Fizz\Hooks;
use Phasis\BuiltIn\Jsx\React\Fizz\Server;
use Phasis\BuiltIn\Jsx\React\ReactModule;
use Phasis\BuiltIn\Jsx\React\ReactSymbols;
use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Engine;
use Phasis\HtmlRenderOptions;
use Phasis\Spec\AbstractOperations;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsElement;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsPromise;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Renders the shared element model to HTML the way preact-render-to-string
 * does: no hydration markers, `<br/>`-style void elements, HTML attribute
 * spellings, `prop:value;` styles. Components run through the shared
 * React-compatible hooks dispatcher.
 *
 * Phasis extensions, outside the byte-compat oracle: raw() inserts trusted
 * HTML verbatim, and renderToStringAsync() awaits async components.
 */
final class HtmlRenderer
{
    private const UNSAFE_NAME = '/[\s\n\\\\\/=\'"\0<>]/';
    private const NAMESPACE_REPLACE = '/^(xlink|xmlns|xml)([A-Z])/';
    private const HTML_LOWER_CASE = '/^(?:accessK|auto[A-Z]|cell|ch|col|cont|cross|dateT|encT|form[A-Z]|frame|hrefL|inputM|maxL|minL|noV'
        . '|playsI|popoverT|readO|rowS|src[A-Z]|tabI|useM|item[A-Z])/';
    private const SVG_CAMEL_CASE = '/^ac|^ali|arabic|basel|cap|clipPath$|clipRule$|color|dominant|enable|fill|flood|font|glyph[^R]|horiz|image'
        . '|letter|lighting|marker[^WUH]|overline|panose|pointe|paint|rendering|shape|stop|strikethrough|stroke|text[^L]|transform|underline'
        . '|unicode|units|^v[^i]|^w|^xH/';
    private const HTML_ENUMERATED = ['draggable' => true, 'spellcheck' => true];
    private const SELF_CLOSING = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true, 'command' => true, 'embed' => true, 'hr' => true,
        'img' => true, 'input' => true, 'keygen' => true, 'link' => true, 'meta' => true, 'param' => true,
        'source' => true, 'track' => true, 'wbr' => true,
    ];
    private const NON_DIMENSIONAL = [
        'animation-iteration-count' => true, 'border-image-outset' => true, 'border-image-slice' => true,
        'border-image-width' => true, 'box-flex' => true, 'box-flex-group' => true, 'box-ordinal-group' => true,
        'column-count' => true, 'fill-opacity' => true, 'flex' => true, 'flex-grow' => true, 'flex-negative' => true,
        'flex-order' => true, 'flex-positive' => true, 'flex-shrink' => true, 'flood-opacity' => true,
        'font-weight' => true, 'grid-column' => true, 'grid-row' => true, 'line-clamp' => true, 'line-height' => true,
        'opacity' => true, 'order' => true, 'orphans' => true, 'stop-opacity' => true, 'stroke-dasharray' => true,
        'stroke-dashoffset' => true, 'stroke-miterlimit' => true, 'stroke-opacity' => true, 'stroke-width' => true,
        'tab-size' => true, 'widows' => true, 'z-index' => true, 'zoom' => true,
    ];

    /** @var array<string, string> */
    private static array $cssNames = [];

    private static ?JsSymbol $rawSymbol = null;

    /** @var \WeakMap<JsObject, true>|null Element objects whose component suspended (prts's vnode._suspended). */
    private static ?\WeakMap $suspended = null;

    private static int $idCounter = 0;

    /** The element type raw() uses: trusted HTML, rendered verbatim. */
    public static function rawSymbol(): JsSymbol
    {
        return self::$rawSymbol ??= SymbolConstructor::registered('phasis.raw');
    }

    /** renderToString(vnode, context): synchronous; a suspending component is an error. */
    public static function renderToString(JsValue $vnode, JsValue $context, ?HtmlRenderOptions $options = null): string
    {
        self::$idCounter = 0;
        $htmlContext = new HtmlRenderContext($options);
        try {
            $rendered = self::withDispatcher(static fn () => self::render($vnode, self::rootContext($context), [], false, null, false, $htmlContext));
        } catch (\Throwable $e) {
            $thrown = Js::thrownValue($e);
            if (Server::isSuspenseException($thrown) || ($thrown instanceof JsObject && Js::get($thrown, 'then')->toBoolean())) {
                throw Js::error('Use "renderToStringAsync" for suspenseful rendering.');
            }
            throw $e;
        }
        return is_array($rendered) ? self::join($rendered) : $rendered;
    }

    /** renderToStringAsync(vnode, context): a promise of the HTML, waiting for suspended components. */
    public static function renderToStringAsync(JsValue $vnode, JsValue $context, ?HtmlRenderOptions $options = null): JsPromise
    {
        self::$idCounter = 0;
        $htmlContext = new HtmlRenderContext($options);
        $result = new JsPromise();
        try {
            $rendered = self::withDispatcher(static fn () => self::render($vnode, self::rootContext($context), [], false, null, true, $htmlContext));
        } catch (\Throwable $e) {
            $result->reject(Js::thrownValue($e));
            return $result;
        }
        if (!is_array($rendered)) {
            $result->resolve(new JsString($rendered));
            return $result;
        }
        self::settle($rendered, 0, $result);
        return $result;
    }

    /**
     * Render a component with PHP-side props (Engine::render with the HTML
     * profile), waiting for suspended and async components.
     */
    public static function renderComponent(Engine $engine, JsValue $component, JsObject $props, ?HtmlRenderOptions $options = null): string
    {
        $element = ReactModule::element($component, JsNull::instance(), $props);
        $html = null;
        $error = null;
        $promise = self::renderToStringAsync($element, JsUndefined::instance(), $options);
        $promise->addFulfillHandler(static function (JsValue $value) use (&$html): void {
            $html = Js::str($value);
        });
        $promise->addRejectHandler(static function (JsValue $reason) use (&$error): void {
            $error = $reason;
        });
        $engine->getEventLoop()->runUntilEmpty();
        if ($error !== null) {
            throw new \Phasis\Exceptions\JsThrowable($error);
        }
        if ($html === null) {
            throw new \Phasis\Exceptions\RuntimeError('render did not settle: a promise the render waits for never resolved');
        }
        return $html;
    }

    /**
     * prts awaits the parts up to 25 rounds, flattening one level each time.
     *
     * @param list<string|JsValue> $parts
     */
    private static function settle(array $parts, int $count, JsPromise $result): void
    {
        $pending = false;
        foreach ($parts as $part) {
            if ($part instanceof JsObject && Js::get($part, 'then') instanceof JsFunction) {
                $pending = true;
                break;
            }
        }
        if (!$pending || $count >= 25) {
            $result->resolve(new JsString(self::join($parts)));
            return;
        }
        $values = [];
        foreach ($parts as $part) {
            $values[] = is_string($part) ? new JsString($part) : $part;
        }
        $all = self::promiseAll($values);
        Js::call(Js::get($all, 'then'), $all, [
            Js::fn('', static function (JsValue $this_, array $args) use ($count, $result): JsValue {
                $flat = [];
                foreach (Js::elements($args[0] ?? new JsArray()) as $value) {
                    if (Js::isArray($value)) {
                        foreach (Js::elements($value) as $inner) {
                            $flat[] = $inner;
                        }
                    } else {
                        $flat[] = $value;
                    }
                }
                self::settle($flat, $count + 1, $result);
                return JsUndefined::instance();
            }, 1),
            Js::fn('', static function (JsValue $this_, array $args) use ($result): JsValue {
                $result->reject($args[0] ?? JsUndefined::instance());
                return JsUndefined::instance();
            }, 1),
        ]);
    }

    /** @param list<JsValue> $values */
    private static function promiseAll(array $values): JsValue
    {
        $promise = Engine::getCurrentInterpreter()?->getGlobalEnv()->get('Promise');
        return Js::call(Js::get($promise ?? JsUndefined::instance(), 'all'), $promise ?? JsUndefined::instance(), [new JsArray($values)]);
    }

    /** @param list<string|JsValue> $parts */
    private static function join(array $parts): string
    {
        $out = '';
        foreach ($parts as $part) {
            $out .= is_string($part) ? $part : (Js::isNullish($part) ? '' : Js::str($part));
        }
        return $out;
    }

    private static function rootContext(JsValue $context): JsObject
    {
        return $context instanceof JsObject ? $context : new JsObject();
    }

    /**
     * Run with the shared hooks dispatcher installed.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private static function withDispatcher(\Closure $fn): mixed
    {
        ReactSymbols::init();
        $previous = ReactModule::$dispatcher;
        ReactModule::$dispatcher = Hooks::dispatcher();
        $previousField = Server::$contextField;
        Server::$contextField = '_currentValue';
        try {
            return $fn();
        } finally {
            ReactModule::$dispatcher = $previous;
            Server::$contextField = $previousField;
        }
    }

    public static function encodeEntities(string $text): string
    {
        if ($text === '' || strpbrk($text, '"&<') === false) {
            return $text;
        }
        return strtr($text, ['"' => '&quot;', '&' => '&amp;', '<' => '&lt;']);
    }

    private static function encodeText(string $text, HtmlRenderContext $context): string
    {
        if ($context->inNoscript && $context->options?->escapeNoscriptText) {
            return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
        }
        return self::encodeEntities($text);
    }

    /** `value + ''`: ToPrimitive with the default hint, then ToString. */
    private static function concatString(JsValue $value): string
    {
        if ($value instanceof JsString) {
            return $value->value;
        }
        return Js::str(AbstractOperations::add($value, new JsString('')));
    }

    private static function isElement(JsObject $value): bool
    {
        if ($value instanceof JsElement) {
            return true;
        }
        return Js::get($value, '$$typeof') === ReactSymbols::$element;
    }

    /**
     * prts's _renderToString. Returns a string, or (async mode) a list of
     * strings and promises.
     *
     * @param list<array{0: JsObject, 1: JsValue}> $providers Context values in scope, innermost last.
     * @return string|list<string|JsValue>
     */
    private static function render(
        JsValue $vnode,
        JsObject $context,
        array $providers,
        bool $isSvgMode,
        ?JsValue $selectValue,
        bool $asyncMode,
        HtmlRenderContext $options,
    ): string|array {
        if ($vnode instanceof JsUndefined || $vnode instanceof JsNull || $vnode instanceof JsBoolean) {
            return '';
        }
        if ($vnode instanceof JsString) {
            return self::encodeText($vnode->value, $options);
        }
        if (!$vnode instanceof JsObject) {
            return Js::str($vnode);
        }
        if ($vnode instanceof JsFunction) {
            return '';
        }
        if (Js::isArray($vnode)) {
            return self::renderArray(Js::elements($vnode), $context, $providers, $isSvgMode, $selectValue, $asyncMode, $options);
        }
        if (!self::isElement($vnode)) {
            return '';
        }
        $type = Js::get($vnode, 'type');
        $props = Js::get($vnode, 'props');
        if (!$props instanceof JsObject) {
            $props = new JsObject();
        }
        if ($type instanceof JsString) {
            return self::renderElement($vnode, $type->value, $props, $context, $providers, $isSvgMode, $selectValue, $asyncMode, $options);
        }
        return self::renderComponentNode($vnode, $type, $props, $context, $providers, $isSvgMode, $selectValue, $asyncMode, $options);
    }

    /**
     * @param list<JsValue> $children
     * @param list<array{0: JsObject, 1: JsValue}> $providers
     * @return string|list<string|JsValue>
     */
    private static function renderArray(
        array $children,
        JsObject $context,
        array $providers,
        bool $isSvgMode,
        ?JsValue $selectValue,
        bool $asyncMode,
        HtmlRenderContext $options,
    ): string|array {
        $rendered = '';
        $parts = null;
        foreach ($children as $child) {
            if ($child instanceof JsUndefined || $child instanceof JsNull || $child instanceof JsBoolean) {
                continue;
            }
            $childRender = self::render($child, $context, $providers, $isSvgMode, $selectValue, $asyncMode, $options);
            if (is_string($childRender)) {
                $rendered .= $childRender;
                continue;
            }
            $parts ??= [];
            if ($rendered !== '') {
                $parts[] = $rendered;
            }
            $rendered = '';
            foreach ($childRender as $part) {
                $parts[] = $part;
            }
        }
        if ($parts !== null) {
            if ($rendered !== '') {
                $parts[] = $rendered;
            }
            return $parts;
        }
        return $rendered;
    }

    /**
     * Components, fragments, context providers and consumers, raw HTML.
     *
     * @param list<array{0: JsObject, 1: JsValue}> $providers
     * @return string|list<string|JsValue>
     */
    private static function renderComponentNode(
        JsObject $vnode,
        JsValue $type,
        JsObject $props,
        JsObject $context,
        array $providers,
        bool $isSvgMode,
        ?JsValue $selectValue,
        bool $asyncMode,
        HtmlRenderContext $options,
    ): string|array {
        if ($type === ReactSymbols::$fragment || $type === ReactSymbols::$strictMode || $type === ReactSymbols::$profiler) {
            return self::render(Js::get($props, 'children'), $context, $providers, $isSvgMode, $selectValue, $asyncMode, $options);
        }
        if ($type === Template::templateSymbol()) {
            return self::renderTemplate($props, $context, $providers, $isSvgMode, $selectValue, $asyncMode, $options);
        }
        if ($type === self::rawSymbol()) {
            $html = Js::get($props, '__html');
            return Js::isNullish($html) ? '' : Js::str($html);
        }
        if ($type instanceof JsObject && !$type instanceof JsFunction) {
            $typeof = Js::get($type, '$$typeof');
            if ($typeof === ReactSymbols::$context) {
                $providers[] = [$type, Js::get($props, 'value')];
                return self::render(Js::get($props, 'children'), $context, $providers, $isSvgMode, $selectValue, $asyncMode, $options);
            }
            if ($typeof === ReactSymbols::$consumer) {
                $ctx = Js::get($type, '_context');
                $value = $ctx instanceof JsObject ? self::contextValue($ctx, $providers) : JsUndefined::instance();
                $children = Js::call(Js::get($props, 'children'), JsUndefined::instance(), [$value]);
                return self::render($children, $context, $providers, $isSvgMode, $selectValue, $asyncMode, $options);
            }
            if ($typeof === ReactSymbols::$memo) {
                $inner = ReactModule::element(Js::get($type, 'type'), Js::get($vnode, 'key'), $props);
                return self::render($inner, $context, $providers, $isSvgMode, $selectValue, $asyncMode, $options);
            }
            if ($typeof === ReactSymbols::$forwardRef) {
                return self::renderFunction(
                    $vnode,
                    Js::get($type, 'render'),
                    $props,
                    Js::get($props, 'ref'),
                    $context,
                    $providers,
                    $isSvgMode,
                    $selectValue,
                    $asyncMode,
                    $options,
                );
            }
            return '';
        }
        if (!$type instanceof JsFunction) {
            return '';
        }
        return self::renderFunction($vnode, $type, $props, $context, $context, $providers, $isSvgMode, $selectValue, $asyncMode, $options);
    }

    /**
     * A precompiled template: static parts as they are, holes rendered in
     * place (attributes with prts's attribute rules).
     *
     * @param list<array{0: JsObject, 1: JsValue}> $providers
     * @return string|list<string|JsValue>
     */
    private static function renderTemplate(
        JsObject $props,
        JsObject $context,
        array $providers,
        bool $isSvgMode,
        ?JsValue $selectValue,
        bool $asyncMode,
        HtmlRenderContext $options,
    ): string|array {
        if (
            $options->options !== null
            && (
                $options->options->quoteEmptyAttributes !== []
                || ($options->inNoscript && $options->options->escapeNoscriptText)
                || $options->options->reactScriptText
            )
        ) {
            throw Js::error('HTML render options cannot change precompiled static output.');
        }
        $template = Template::parse(Js::get($props, 'tpl'));
        if ($template['profile'] !== 'html') {
            throw Js::error('This JSX was precompiled for the React profile and cannot render with the phasis HTML profile.');
        }
        $exprs = Js::elements(Js::get($props, 'exprs'));
        $out = '';
        $parts = null;
        foreach ($template['parts'] as $i => $part) {
            $out .= $part;
            if (!isset($exprs[$i])) {
                continue;
            }
            $value = $exprs[$i];
            if (($template['steps'][$i] ?? null) === null) {
                $attribute = $template['attributes'][$i] ?? null;
                $out .= $attribute === null
                    ? self::attributeChunk(Js::str(Js::get($value, 'name')), Js::get($value, 'value'), null, false)
                    : self::attributeChunk($attribute[0], $value, null, false);
                continue;
            }
            if ($value instanceof JsUndefined || $value instanceof JsNull || $value instanceof JsBoolean) {
                continue;
            }
            $rendered = self::render($value, $context, $providers, $isSvgMode, $selectValue, $asyncMode, $options);
            if (is_string($rendered)) {
                $out .= $rendered;
                continue;
            }
            $parts ??= [];
            if ($out !== '') {
                $parts[] = $out;
            }
            $out = '';
            foreach ($rendered as $piece) {
                $parts[] = $piece;
            }
        }
        if ($parts === null) {
            return $out;
        }
        if ($out !== '') {
            $parts[] = $out;
        }
        return $parts;
    }

    /** @param list<array{0: JsObject, 1: JsValue}> $providers */
    private static function contextValue(JsObject $ctx, array $providers): JsValue
    {
        for ($i = count($providers) - 1; $i >= 0; $i--) {
            if ($providers[$i][0] === $ctx) {
                return $providers[$i][1];
            }
        }
        return Js::get($ctx, '_currentValue');
    }

    /**
     * Call a function component with the hooks dispatcher and the context
     * values in scope, then render what it returns.
     *
     * @param list<array{0: JsObject, 1: JsValue}> $providers
     * @return string|list<string|JsValue>
     */
    private static function renderFunction(
        JsObject $vnode,
        JsValue $type,
        JsObject $props,
        JsValue $secondArg,
        JsObject $context,
        array $providers,
        bool $isSvgMode,
        ?JsValue $selectValue,
        bool $asyncMode,
        HtmlRenderContext $options,
    ): string|array {
        try {
            $rendered = self::callComponent($type, $props, $secondArg, $providers);
        } catch (\Throwable $e) {
            $thrown = Js::thrownValue($e);
            if ($asyncMode && Server::isSuspenseException($thrown)) {
                self::$suspended ??= new \WeakMap();
                self::$suspended[$vnode] = true;
                // prts rethrows the thenable for the nearest component above to await.
                Js::throw(Server::takeSuspendedThenable());
            }
            if ($asyncMode && $thrown instanceof JsObject && Js::get($thrown, 'then') instanceof JsFunction) {
                self::$suspended ??= new \WeakMap();
                self::$suspended[$vnode] = true;
            }
            throw $e;
        }
        if ($asyncMode && $rendered instanceof JsObject && Js::get($rendered, 'then') instanceof JsFunction) {
            // An async component (a Phasis extension: prts renders nothing for it).
            return [self::then($rendered, static function (JsValue $value) use ($context, $providers, $isSvgMode, $selectValue, $options): JsValue {
                return self::toJs(self::render($value, $context, $providers, $isSvgMode, $selectValue, true, $options));
            })];
        }
        if (
            $rendered instanceof JsObject && self::isElement($rendered)
            && Js::get($rendered, 'type') === ReactSymbols::$fragment
            && Js::isNullish(Js::get($rendered, 'key'))
        ) {
            $rendered = Js::get(Js::get($rendered, 'props'), 'children');
        }
        try {
            $str = self::render($rendered, $context, $providers, $isSvgMode, $selectValue, $asyncMode, $options);
            if (self::$suspended !== null && isset(self::$suspended[$vnode])) {
                return self::wrapWithSuspenseMarkers($str);
            }
            return $str;
        } catch (\Throwable $e) {
            if (!$asyncMode) {
                throw $e;
            }
            $thrown = Js::thrownValue($e);
            if (!$thrown instanceof JsObject || !Js::get($thrown, 'then') instanceof JsFunction) {
                throw $e;
            }
            $renderNestedChildren = null;
            $renderNestedChildren = static function () use (
                &$renderNestedChildren,
                $rendered,
                $context,
                $providers,
                $isSvgMode,
                $selectValue,
                $vnode,
                $options,
            ): JsValue {
                try {
                    $result = self::withDispatcher(
                        static fn () => self::render($rendered, $context, $providers, $isSvgMode, $selectValue, true, $options),
                    );
                    if (self::$suspended !== null && isset(self::$suspended[$vnode])) {
                        $result = self::wrapWithSuspenseMarkers($result);
                    }
                    return self::toJs($result);
                } catch (\Throwable $inner) {
                    $again = Js::thrownValue($inner);
                    if (!$again instanceof JsObject || !Js::get($again, 'then') instanceof JsFunction) {
                        throw $inner;
                    }
                    return self::then($again, static fn (): JsValue => $renderNestedChildren());
                }
            };
            return [self::then($thrown, static fn (): JsValue => $renderNestedChildren())];
        }
    }

    /**
     * @param list<array{0: JsObject, 1: JsValue}> $providers
     */
    private static function callComponent(JsValue $type, JsObject $props, JsValue $secondArg, array $providers): JsValue
    {
        $restore = [];
        foreach ($providers as [$ctx, $value]) {
            $restore[] = [$ctx, Js::get($ctx, '_currentValue')];
            $ctx->set('_currentValue', $value);
        }
        try {
            return Hooks::renderStandalone($type, $props, $secondArg, static fn (): string => 'P' . self::$idCounter++);
        } finally {
            for ($i = count($restore) - 1; $i >= 0; $i--) {
                $restore[$i][0]->set('_currentValue', $restore[$i][1]);
            }
        }
    }

    /** @param \Closure(JsValue): JsValue $callback */
    private static function then(JsObject $thenable, \Closure $callback): JsValue
    {
        return Js::call(Js::get($thenable, 'then'), $thenable, [
            Js::fn('', static fn (JsValue $this_, array $args): JsValue => $callback($args[0] ?? JsUndefined::instance()), 1),
        ]);
    }

    /** @param string|list<string|JsValue> $rendered */
    private static function toJs(string|array $rendered): JsValue
    {
        if (is_string($rendered)) {
            return new JsString($rendered);
        }
        return new JsArray(array_map(static fn (string|JsValue $part): JsValue => is_string($part) ? new JsString($part) : $part, $rendered));
    }

    /**
     * @param string|list<string|JsValue> $result
     * @return string|list<string|JsValue>
     */
    private static function wrapWithSuspenseMarkers(string|array $result): string|array
    {
        if (is_string($result)) {
            return '<!--$s-->' . $result . '<!--/$s-->';
        }
        array_unshift($result, '<!--$s-->');
        $result[] = '<!--/$s-->';
        return $result;
    }

    /**
     * Serialize an HTML or SVG element.
     *
     * @param list<array{0: JsObject, 1: JsValue}> $providers
     * @return string|list<string|JsValue>
     */
    private static function renderElement(
        JsObject $vnode,
        string $type,
        JsObject $props,
        JsObject $context,
        array $providers,
        bool $isSvgMode,
        ?JsValue $selectValue,
        bool $asyncMode,
        HtmlRenderContext $options,
    ): string|array {
        $s = '<' . $type;
        $html = '';
        $children = null;
        foreach (Js::forInKeys($props) as $name) {
            $v = Js::get($props, $name);
            if ($v instanceof JsObject && !$v instanceof JsFunction && Js::get($v, 'peek') instanceof JsFunction && Js::has($v, 'value')) {
                $v = Js::get($v, 'value');
            }
            if ($v instanceof JsFunction && $name !== 'class' && $name !== 'className') {
                continue;
            }
            switch ($name) {
                case 'children':
                    $children = $v;
                    continue 2;
                case 'defaultValue':
                case 'value':
                    if ($type === 'textarea') {
                        $children = $v;
                        continue 2;
                    }
                    if ($type === 'select') {
                        $selectValue = $v;
                        continue 2;
                    }
                    $selected = $selectValue ?? JsUndefined::instance();
                    if ($type === 'option' && AbstractOperations::abstractEquals($selected, $v) && !Js::has($props, 'selected')) {
                        $s .= ' selected';
                    }
                    break;
                case 'dangerouslySetInnerHTML':
                    // prts: `html = v && v.__html`, used only when truthy.
                    $innerHtml = $v->toBoolean() ? Js::get($v, '__html') : $v;
                    $html = $innerHtml->toBoolean() ? self::concatString($innerHtml) : '';
                    continue 2;
            }
            $s .= self::attributeChunk($name, $v, $props, $isSvgMode, $options->options);
        }
        if (preg_match(self::UNSAFE_NAME, $type) === 1) {
            throw Js::error($type . ' is not a valid HTML tag name in ' . $s . '>');
        }
        $childOptions = $options->childrenOf($type);
        if ($html !== '') {
            // dangerouslySetInnerHTML defined the contents.
        } elseif ($type === 'script' && $options->options?->reactScriptText) {
            if ($children instanceof JsString) {
                // HTML parses script contents as raw text. Escape both opening
                // and closing script spellings as React does, preserving case.
                $html = (string) preg_replace_callback(
                    '/(<\/?)(s)(cript)/i',
                    static fn (array $match): string => $match[1] . '\\u00' . bin2hex($match[2]) . $match[3],
                    $children->value,
                );
            } elseif ($children !== null && !Js::isNullish($children) && !$children instanceof JsBoolean) {
                throw Js::error('A script element requires a single string child when reactScriptText is enabled.');
            }
        } elseif ($children instanceof JsString) {
            $html = self::encodeText($children->value, $childOptions);
        } elseif ($children !== null && !Js::isNullish($children) && !$children instanceof JsBoolean) {
            $childSvgMode = $type === 'svg' || ($type !== 'foreignObject' && $isSvgMode);
            $html = self::render($children, $context, $providers, $childSvgMode, $selectValue, $asyncMode, $childOptions);
        }
        if ($html === '' && isset(self::SELF_CLOSING[$type])) {
            return $s . '/>';
        }
        $endTag = '</' . $type . '>';
        if (is_array($html)) {
            return [$s . '>', ...$html, $endTag];
        }
        return $s . '>' . $html . $endTag;
    }

    /**
     * One attribute as prts writes it (` name`, ` name="value"` or nothing),
     * after the element-specific cases (children, value on form fields,
     * dangerouslySetInnerHTML) are handled. $props, when given, is checked for
     * `for` and `class`, which win over htmlFor and className.
     */
    public static function attributeChunk(string $name, JsValue $v, ?JsObject $props, bool $isSvgMode, ?HtmlRenderOptions $options = null): string
    {
        if ($v instanceof JsFunction && $name !== 'class' && $name !== 'className') {
            return '';
        }
        switch ($name) {
            case 'children':
            case 'key':
            case 'ref':
            case '__self':
            case '__source':
            case 'dangerouslySetInnerHTML':
                return '';
            case 'htmlFor':
                if ($props !== null && Js::has($props, 'for')) {
                    return '';
                }
                $name = 'for';
                break;
            case 'className':
                if ($props !== null && Js::has($props, 'class')) {
                    return '';
                }
                $name = 'class';
                break;
            case 'defaultChecked':
                $name = 'checked';
                break;
            case 'defaultSelected':
                $name = 'selected';
                break;
            case 'defaultValue':
            case 'value':
                $name = 'value';
                break;
            case 'style':
                if ($v instanceof JsObject && !$v instanceof JsFunction) {
                    $css = self::styleObjToCss($v);
                    $v = $css === '' ? JsUndefined::instance() : new JsString($css);
                }
                break;
            case 'acceptCharset':
                $name = 'accept-charset';
                break;
            case 'httpEquiv':
                $name = 'http-equiv';
                break;
            default:
                if (preg_match(self::UNSAFE_NAME, $name) === 1) {
                    return '';
                }
                if (preg_match(self::NAMESPACE_REPLACE, $name) === 1) {
                    $name = strtolower((string) preg_replace(self::NAMESPACE_REPLACE, '$1:$2', $name));
                } elseif ((($name[4] ?? '') === '-' || isset(self::HTML_ENUMERATED[$name])) && !Js::isNullish($v)) {
                    $v = new JsString(self::concatString($v));
                } elseif ($isSvgMode) {
                    if (preg_match(self::SVG_CAMEL_CASE, $name) === 1) {
                        $name = $name === 'panose1' ? 'panose-1' : strtolower((string) preg_replace('/([A-Z])/', '-$1', $name));
                    }
                } elseif (preg_match(self::HTML_LOWER_CASE, $name) === 1) {
                    $name = strtolower($name);
                }
        }
        if (Js::isNullish($v) || ($v instanceof JsBoolean && !$v->value)) {
            return '';
        }
        if ($v instanceof JsString && $v->value === '' && $options !== null && in_array($name, $options->quoteEmptyAttributes, true)) {
            return ' ' . $name . '=""';
        }
        if (($v instanceof JsBoolean && $v->value) || ($v instanceof JsString && $v->value === '')) {
            return ' ' . $name;
        }
        return ' ' . $name . '="' . self::encodeEntities(self::concatString($v)) . '"';
    }

    private static function styleObjToCss(JsObject $style): string
    {
        $str = '';
        foreach (Js::forInKeys($style) as $prop) {
            $val = Js::get($style, $prop);
            if (Js::isNullish($val) || ($val instanceof JsString && $val->value === '')) {
                continue;
            }
            $name = ($prop[0] ?? '') === '-'
                ? $prop
                : (self::$cssNames[$prop] ??= mb_strtolower((string) preg_replace('/[A-Z]/', '-$0', $prop), 'UTF-8'));
            $suffix = ';';
            if ($val instanceof JsNumber && !str_starts_with($name, '--') && !isset(self::NON_DIMENSIONAL[$name])) {
                $suffix = 'px;';
            }
            $str .= $name . ':' . self::concatString($val) . $suffix;
        }
        return $str;
    }
}
