<?php

/**
 * Ported from React 19.3.0's Fizz DOM config (resources and hoistables),
 * as built into react-dom-server.node.production.js.
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
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Resources and hoistables: stylesheets with precedence, async scripts,
 * image preloads, the react-dom preload/preinit APIs, and how they are
 * flushed into the document head or a completed boundary.
 */
final class Resources
{
    /** React's PRELOAD_NO_CREDS: a preload without crossOrigin or integrity. */
    private const NO_CREDS = [];

    private static function nullish(?JsValue $value): bool
    {
        return $value === null || $value instanceof JsUndefined || $value instanceof JsNull;
    }

    /**
     * All own enumerable props, nullish ones included, as Object.assign copies them.
     *
     * @param JsObject|array<string, JsValue> $props
     * @return array<string, JsValue>
     */
    private static function ownProps(JsObject|array $props): array
    {
        if (is_array($props)) {
            return $props;
        }
        $out = [];
        foreach (Js::ownEnumerableKeys($props) as $key) {
            $out[$key] = Js::get($props, $key);
        }
        return $out;
    }

    /**
     * @param array<string, JsValue> $target
     * @param list<JsValue> $preloadState
     */
    private static function adoptPreloadCredentials(array &$target, array $preloadState): void
    {
        if (self::nullish($target['crossOrigin'] ?? null)) {
            $target['crossOrigin'] = $preloadState[0];
        }
        if (self::nullish($target['integrity'] ?? null)) {
            $target['integrity'] = $preloadState[1];
        }
    }

    /**
     * Credentials a preload records for the resource it preloads.
     *
     * @return list<JsValue>
     */
    private static function preloadCredentials(JsValue $options): array
    {
        if (!$options->toBoolean()) {
            return self::NO_CREDS;
        }
        $crossOrigin = Js::get($options, 'crossOrigin');
        $integrity = Js::get($options, 'integrity');
        if (!$crossOrigin instanceof JsString && !$integrity instanceof JsString) {
            return self::NO_CREDS;
        }
        return [$crossOrigin, $integrity];
    }

    // Hoistable elements in pushStartInstance.

    /** @param list<string> $target */
    public static function pushLink(
        array &$target,
        JsObject $props,
        ResumableState $resumableState,
        RenderState $renderState,
        ?HoistableState $hoistableState,
        FormatContext $formatContext,
        bool $textEmbedded,
    ): null {
        $isFallback = ($formatContext->tagScope & 4) !== 0;
        $rel = Dom::prop($props, 'rel');
        $href = Dom::prop($props, 'href');
        $precedence = Dom::prop($props, 'precedence');
        if (
            $formatContext->insertionMode === Dom::SVG_MODE
            || ($formatContext->tagScope & 1) !== 0
            || !self::nullish(Dom::prop($props, 'itemProp'))
            || !$rel instanceof JsString
            || !$href instanceof JsString
            || $href->value === ''
        ) {
            return Dom::pushLinkImpl($target, $props);
        }
        if ($rel->value === 'stylesheet') {
            if (
                !$precedence instanceof JsString
                || !self::nullish(Dom::prop($props, 'disabled'))
                || Dom::prop($props, 'onLoad')->toBoolean()
                || Dom::prop($props, 'onError')->toBoolean()
            ) {
                return Dom::pushLinkImpl($target, $props);
            }
            $key = $href->value;
            $styleQueue = $renderState->styles[$precedence->value] ?? null;
            $resourceState = array_key_exists($key, $resumableState->styleResources) ? $resumableState->styleResources[$key] : false;
            if ($resourceState !== null) {
                $resumableState->styleResources[$key] = null;
                if ($styleQueue === null) {
                    $styleQueue = new StyleQueue(Dom::escapeText($precedence->value));
                    $renderState->styles[$precedence->value] = $styleQueue;
                }
                $resourceProps = self::ownProps($props);
                $resourceProps['data-precedence'] = $precedence;
                $resourceProps['precedence'] = JsNull::instance();
                $resource = new StylesheetResource(0, $resourceProps);
                if (is_array($resourceState)) {
                    if (count($resourceState) === 2) {
                        self::adoptPreloadCredentials($resource->props, $resourceState);
                    }
                    $preload = $renderState->preloadStylesheets[$key] ?? null;
                    if ($preload !== null && $preload->c !== []) {
                        $preload->c = [];
                    } else {
                        $resource->state = 1;
                    }
                }
                $styleQueue->sheets[$key] = $resource;
                if ($hoistableState !== null) {
                    $hoistableState->stylesheets[spl_object_id($resource)] = $resource;
                }
            } elseif ($styleQueue !== null) {
                $existing = $styleQueue->sheets[$key] ?? null;
                if ($existing !== null && $hoistableState !== null) {
                    $hoistableState->stylesheets[spl_object_id($existing)] = $existing;
                }
            }
            if ($textEmbedded) {
                $target[] = Dom::TEXT_SEPARATOR;
            }
            return null;
        }
        if (Dom::prop($props, 'onLoad')->toBoolean() || Dom::prop($props, 'onError')->toBoolean()) {
            return Dom::pushLinkImpl($target, $props);
        }
        if ($textEmbedded) {
            $target[] = Dom::TEXT_SEPARATOR;
        }
        return $isFallback ? null : Dom::pushLinkImpl($renderState->hoistableChunks, $props);
    }

    /** @param list<string> $target */
    public static function pushScript(
        array &$target,
        JsObject $props,
        ResumableState $resumableState,
        RenderState $renderState,
        FormatContext $formatContext,
        bool $textEmbedded,
    ): null {
        $async = Dom::prop($props, 'async');
        $src = Dom::prop($props, 'src');
        if (
            !$src instanceof JsString
            || $src->value === ''
            || !$async->toBoolean()
            || $async instanceof JsFunction
            || $async instanceof JsSymbol
            || Dom::prop($props, 'onLoad')->toBoolean()
            || Dom::prop($props, 'onError')->toBoolean()
            || $formatContext->insertionMode === Dom::SVG_MODE
            || ($formatContext->tagScope & 1) !== 0
            || !self::nullish(Dom::prop($props, 'itemProp'))
        ) {
            return Dom::pushScriptImpl($target, $props);
        }
        $key = $src->value;
        $type = Dom::prop($props, 'type');
        $isModule = $type instanceof JsString && $type->value === 'module';
        if ($isModule) {
            $resourceState = array_key_exists($key, $resumableState->moduleScriptResources) ? $resumableState->moduleScriptResources[$key] : false;
        } else {
            $resourceState = array_key_exists($key, $resumableState->scriptResources) ? $resumableState->scriptResources[$key] : false;
        }
        if ($resourceState !== null) {
            if ($isModule) {
                $resumableState->moduleScriptResources[$key] = null;
            } else {
                $resumableState->scriptResources[$key] = null;
            }
            $scriptProps = $props;
            if (is_array($resourceState)) {
                if (count($resourceState) === 2) {
                    $scriptProps = self::ownProps($props);
                    self::adoptPreloadCredentials($scriptProps, $resourceState);
                }
                $preload = $isModule ? ($renderState->preloadModuleScripts[$key] ?? null) : ($renderState->preloadScripts[$key] ?? null);
                if ($preload !== null) {
                    $preload->c = [];
                }
            }
            $resource = new Chunks();
            $renderState->scripts[spl_object_id($resource)] = $resource;
            Dom::pushScriptImpl($resource->c, $scriptProps);
        }
        if ($textEmbedded) {
            $target[] = Dom::TEXT_SEPARATOR;
        }
        return null;
    }

    /** @param list<string> $target */
    public static function pushStyle(
        array &$target,
        JsObject $props,
        ResumableState $resumableState,
        RenderState $renderState,
        ?HoistableState $hoistableState,
        FormatContext $formatContext,
        bool $textEmbedded,
    ): ?JsValue {
        $precedence = Dom::prop($props, 'precedence');
        $href = Dom::prop($props, 'href');
        $nonce = Dom::prop($props, 'nonce');
        if (
            $formatContext->insertionMode === Dom::SVG_MODE
            || ($formatContext->tagScope & 1) !== 0
            || !self::nullish(Dom::prop($props, 'itemProp'))
            || !$precedence instanceof JsString
            || !$href instanceof JsString
            || $href->value === ''
        ) {
            $target[] = Dom::startChunkForTag('style');
            $children = null;
            $innerHTML = null;
            foreach (Dom::propEntries($props) as [$key, $value]) {
                switch ($key) {
                    case 'children':
                        $children = $value;
                        break;
                    case 'dangerouslySetInnerHTML':
                        $innerHTML = $value;
                        break;
                    default:
                        Dom::pushAttribute($target, $key, $value);
                }
            }
            $target[] = '>';
            self::pushStyleContents($target, $children, $innerHTML);
            $target[] = Dom::endChunkForTag('style');
            return null;
        }
        $key = $href->value;
        $styleQueue = $renderState->styles[$precedence->value] ?? null;
        $resourceState = array_key_exists($key, $resumableState->styleResources) ? $resumableState->styleResources[$key] : false;
        if ($resourceState !== null) {
            $resumableState->styleResources[$key] = null;
            if ($styleQueue === null) {
                $styleQueue = new StyleQueue(Dom::escapeText($precedence->value));
                $renderState->styles[$precedence->value] = $styleQueue;
            }
            $nonceStyle = $renderState->nonceStyle;
            if ($nonceStyle === null || $nonceStyle === '' || ($nonce instanceof JsString && $nonce->value === $nonceStyle)) {
                $styleQueue->hrefs[] = Dom::escapeText($key);
                $children = null;
                $innerHTML = null;
                foreach (Dom::propEntries($props) as [$propKey, $value]) {
                    if ($propKey === 'children') {
                        $children = $value;
                    } elseif ($propKey === 'dangerouslySetInnerHTML') {
                        $innerHTML = $value;
                    }
                }
                self::pushStyleContents($styleQueue->rules, $children, $innerHTML);
            }
        }
        if ($styleQueue !== null && $hoistableState !== null) {
            $hoistableState->styles[spl_object_id($styleQueue)] = $styleQueue;
        }
        if ($textEmbedded) {
            $target[] = Dom::TEXT_SEPARATOR;
        }
        return JsUndefined::instance();
    }

    /** @param list<string> $target */
    private static function pushStyleContents(array &$target, ?JsValue $children, ?JsValue $innerHTML): void
    {
        $child = $children;
        if ($children !== null && Js::isArray($children)) {
            $elements = Js::elements($children);
            $child = count($elements) < 2 ? ($elements[0] ?? JsUndefined::instance()) : null;
        }
        if ($child !== null && !$child instanceof JsFunction && !$child instanceof JsSymbol && !self::nullish($child)) {
            $target[] = Dom::escapeStyle(Js::str($child));
        }
        Dom::pushInnerHTML($target, $innerHTML, $children);
    }

    /** The image preload React emits for an eager `<img>` (pushStartInstance's img case). */
    public static function preloadImage(
        JsObject $props,
        ResumableState $resumableState,
        RenderState $renderState,
        ?HoistableState $hoistableState,
        FormatContext $formatContext,
    ): void {
        $src = Dom::prop($props, 'src');
        $srcSet = Dom::prop($props, 'srcSet');
        $loading = Dom::prop($props, 'loading');
        $fetchPriority = Dom::prop($props, 'fetchPriority');
        if (
            ($loading instanceof JsString && $loading->value === 'lazy')
            || (!$src->toBoolean() && !$srcSet->toBoolean())
            || (!$src instanceof JsString && !self::nullish($src))
            || (!$srcSet instanceof JsString && !self::nullish($srcSet))
            || ($fetchPriority instanceof JsString && $fetchPriority->value === 'low')
            || ($formatContext->tagScope & 3) !== 0
            || self::isDataUrl($src)
            || self::isDataUrl($srcSet)
        ) {
            return;
        }
        if ($hoistableState !== null && ($formatContext->tagScope & 64) !== 0) {
            $hoistableState->suspenseyImages = true;
        }
        $sizes = Dom::prop($props, 'sizes');
        $sizes = $sizes instanceof JsString ? $sizes : null;
        $key = $srcSet->toBoolean()
            ? Js::str($srcSet) . "\n" . ($sizes !== null && $sizes->value !== '' ? $sizes->value : '')
            : Js::str($src);
        $isHigh = $fetchPriority instanceof JsString && $fetchPriority->value === 'high';
        $resource = $renderState->preloadImages[$key] ?? null;
        if ($resource !== null) {
            if ($isHigh || count($renderState->highImagePreloads) < 10) {
                unset($renderState->preloadImages[$key]);
                $renderState->highImagePreloads[spl_object_id($resource)] = $resource;
            }
            return;
        }
        if (array_key_exists($key, $resumableState->imageResources)) {
            return;
        }
        $resumableState->imageResources[$key] = self::NO_CREDS;
        $crossOriginInput = Dom::prop($props, 'crossOrigin');
        $crossOrigin = $crossOriginInput instanceof JsString
            ? ($crossOriginInput->value === 'use-credentials' ? $crossOriginInput : new JsString(''))
            : JsUndefined::instance();
        $resource = new Chunks();
        Dom::pushLinkImpl($resource->c, [
            'rel' => new JsString('preload'),
            'as' => new JsString('image'),
            'href' => $srcSet->toBoolean() ? JsUndefined::instance() : $src,
            'imageSrcSet' => $srcSet,
            'imageSizes' => $sizes ?? JsUndefined::instance(),
            'crossOrigin' => $crossOrigin,
            'integrity' => Dom::prop($props, 'integrity'),
            'type' => Dom::prop($props, 'type'),
            'fetchPriority' => $fetchPriority,
            'referrerPolicy' => Dom::prop($props, 'referrerPolicy'),
        ]);
        if ($isHigh || count($renderState->highImagePreloads) < 10) {
            $renderState->highImagePreloads[spl_object_id($resource)] = $resource;
        } else {
            $renderState->bulkPreloads[spl_object_id($resource)] = $resource;
            $renderState->preloadImages[$key] = $resource;
        }
    }

    private static function isDataUrl(JsValue $value): bool
    {
        if (!$value instanceof JsString) {
            return false;
        }
        $s = $value->value;
        return strlen($s) > 4 && $s[4] === ':' && strtolower(substr($s, 0, 4)) === 'data';
    }

    // react-dom resource APIs (ReactDOMSharedInternals.d while rendering).

    public static function prefetchDNS(JsValue $href): void
    {
        $request = Server::resolveRequest();
        if ($request === null) {
            return;
        }
        if ($href instanceof JsString && $href->value !== '') {
            $key = $href->value;
            if (!array_key_exists($key, $request->resumableState->dnsResources)) {
                $request->resumableState->dnsResources[$key] = null;
                $chunks = new Chunks();
                Dom::pushLinkImpl($chunks->c, ['href' => $href, 'rel' => new JsString('dns-prefetch')]);
                $request->renderState->preconnects[spl_object_id($chunks)] = $chunks;
            }
            Server::enqueueFlush($request);
        }
    }

    public static function preconnect(JsValue $href, JsValue $crossOrigin): void
    {
        $request = Server::resolveRequest();
        if ($request === null) {
            return;
        }
        if ($href instanceof JsString && $href->value !== '') {
            $bucket = $crossOrigin instanceof JsString
                ? ($crossOrigin->value === 'use-credentials' ? 'credentials' : 'anonymous')
                : 'default';
            $key = $href->value;
            if (!array_key_exists($key, $request->resumableState->connectResources[$bucket])) {
                $request->resumableState->connectResources[$bucket][$key] = null;
                $chunks = new Chunks();
                Dom::pushLinkImpl($chunks->c, ['rel' => new JsString('preconnect'), 'href' => $href, 'crossOrigin' => $crossOrigin]);
                $request->renderState->preconnects[spl_object_id($chunks)] = $chunks;
            }
            Server::enqueueFlush($request);
        }
    }

    public static function preload(JsValue $href, JsValue $as, JsValue $options): void
    {
        $request = Server::resolveRequest();
        if ($request === null || !$as->toBoolean() || !$href->toBoolean()) {
            return;
        }
        $resumableState = $request->resumableState;
        $renderState = $request->renderState;
        $hrefKey = Js::str($href);
        $asKey = Js::str($as);
        $props = static function () use ($href, $as, $options): array {
            $props = ['rel' => new JsString('preload'), 'href' => $href, 'as' => $as];
            if ($options instanceof JsObject) {
                foreach (Js::ownEnumerableKeys($options) as $key) {
                    $props[$key] = Js::get($options, $key);
                }
            }
            return $props;
        };
        switch ($asKey) {
            case 'image':
                $imageSrcSet = JsUndefined::instance();
                $imageSizes = JsUndefined::instance();
                $fetchPriority = JsUndefined::instance();
                if ($options->toBoolean()) {
                    $imageSrcSet = Js::get($options, 'imageSrcSet');
                    $imageSizes = Js::get($options, 'imageSizes');
                    $fetchPriority = Js::get($options, 'fetchPriority');
                }
                $key = $imageSrcSet->toBoolean()
                    ? Js::str($imageSrcSet) . "\n" . ($imageSizes->toBoolean() ? Js::str($imageSizes) : '')
                    : $hrefKey;
                if (array_key_exists($key, $resumableState->imageResources)) {
                    return;
                }
                $resumableState->imageResources[$key] = self::NO_CREDS;
                $linkProps = ['rel' => new JsString('preload'), 'href' => $imageSrcSet->toBoolean() ? JsUndefined::instance() : $href, 'as' => $as];
                if ($options instanceof JsObject) {
                    foreach (Js::ownEnumerableKeys($options) as $optionKey) {
                        $linkProps[$optionKey] = Js::get($options, $optionKey);
                    }
                }
                $chunks = new Chunks();
                Dom::pushLinkImpl($chunks->c, $linkProps);
                if ($fetchPriority instanceof JsString && $fetchPriority->value === 'high') {
                    $renderState->highImagePreloads[spl_object_id($chunks)] = $chunks;
                } else {
                    $renderState->bulkPreloads[spl_object_id($chunks)] = $chunks;
                    $renderState->preloadImages[$key] = $chunks;
                }
                break;
            case 'style':
                if (array_key_exists($hrefKey, $resumableState->styleResources)) {
                    return;
                }
                $chunks = new Chunks();
                Dom::pushLinkImpl($chunks->c, $props());
                $resumableState->styleResources[$hrefKey] = self::preloadCredentials($options);
                $renderState->preloadStylesheets[$hrefKey] = $chunks;
                $renderState->bulkPreloads[spl_object_id($chunks)] = $chunks;
                break;
            case 'script':
                if (array_key_exists($hrefKey, $resumableState->scriptResources)) {
                    return;
                }
                $chunks = new Chunks();
                $renderState->preloadScripts[$hrefKey] = $chunks;
                $renderState->bulkPreloads[spl_object_id($chunks)] = $chunks;
                Dom::pushLinkImpl($chunks->c, $props());
                $resumableState->scriptResources[$hrefKey] = self::preloadCredentials($options);
                break;
            default:
                if (array_key_exists($asKey, $resumableState->unknownResources)) {
                    if (array_key_exists($hrefKey, $resumableState->unknownResources[$asKey])) {
                        return;
                    }
                } else {
                    $resumableState->unknownResources[$asKey] = [];
                }
                $resumableState->unknownResources[$asKey][$hrefKey] = self::NO_CREDS;
                $chunks = new Chunks();
                Dom::pushLinkImpl($chunks->c, $props());
                if ($asKey === 'font') {
                    $renderState->fontPreloads[spl_object_id($chunks)] = $chunks;
                } else {
                    $renderState->bulkPreloads[spl_object_id($chunks)] = $chunks;
                }
        }
        Server::enqueueFlush($request);
    }

    public static function preloadModule(JsValue $href, JsValue $options): void
    {
        $request = Server::resolveRequest();
        if ($request === null || !$href->toBoolean()) {
            return;
        }
        $resumableState = $request->resumableState;
        $hrefKey = Js::str($href);
        $asValue = $options->toBoolean() ? Js::get($options, 'as') : JsUndefined::instance();
        $as = $asValue instanceof JsString ? $asValue->value : 'script';
        if ($as === 'script') {
            if (array_key_exists($hrefKey, $resumableState->moduleScriptResources)) {
                return;
            }
            $chunks = new Chunks();
            $resumableState->moduleScriptResources[$hrefKey] = self::preloadCredentials($options);
            $request->renderState->preloadModuleScripts[$hrefKey] = $chunks;
        } else {
            if (array_key_exists($as, $resumableState->moduleUnknownResources)) {
                if (array_key_exists($hrefKey, $resumableState->moduleUnknownResources[$as])) {
                    return;
                }
            } else {
                $resumableState->moduleUnknownResources[$as] = [];
            }
            $chunks = new Chunks();
            $resumableState->moduleUnknownResources[$as][$hrefKey] = self::NO_CREDS;
        }
        $props = ['rel' => new JsString('modulepreload'), 'href' => $href];
        if ($options instanceof JsObject) {
            foreach (Js::ownEnumerableKeys($options) as $key) {
                $props[$key] = Js::get($options, $key);
            }
        }
        Dom::pushLinkImpl($chunks->c, $props);
        $request->renderState->bulkPreloads[spl_object_id($chunks)] = $chunks;
        Server::enqueueFlush($request);
    }

    public static function preinitStyle(JsValue $href, JsValue $precedence, JsValue $options): void
    {
        $request = Server::resolveRequest();
        if ($request === null || !$href->toBoolean()) {
            return;
        }
        $resumableState = $request->resumableState;
        $renderState = $request->renderState;
        $hrefKey = Js::str($href);
        $precedence = $precedence->toBoolean() ? $precedence : new JsString('default');
        $precedenceKey = Js::str($precedence);
        $styleQueue = $renderState->styles[$precedenceKey] ?? null;
        $resourceState = array_key_exists($hrefKey, $resumableState->styleResources) ? $resumableState->styleResources[$hrefKey] : false;
        if ($resourceState === null) {
            return;
        }
        $resumableState->styleResources[$hrefKey] = null;
        if ($styleQueue === null) {
            $styleQueue = new StyleQueue(Dom::esc($precedence));
            $renderState->styles[$precedenceKey] = $styleQueue;
        }
        $props = ['rel' => new JsString('stylesheet'), 'href' => $href, 'data-precedence' => $precedence];
        if ($options instanceof JsObject) {
            foreach (Js::ownEnumerableKeys($options) as $key) {
                $props[$key] = Js::get($options, $key);
            }
        }
        $resource = new StylesheetResource(0, $props);
        if (is_array($resourceState)) {
            if (count($resourceState) === 2) {
                self::adoptPreloadCredentials($resource->props, $resourceState);
            }
            $preload = $renderState->preloadStylesheets[$hrefKey] ?? null;
            if ($preload !== null && $preload->c !== []) {
                $preload->c = [];
            } else {
                $resource->state = 1;
            }
        }
        $styleQueue->sheets[$hrefKey] = $resource;
        Server::enqueueFlush($request);
    }

    public static function preinitScript(JsValue $src, JsValue $options, bool $module): void
    {
        $request = Server::resolveRequest();
        if ($request === null || !$src->toBoolean()) {
            return;
        }
        $resumableState = $request->resumableState;
        $renderState = $request->renderState;
        $key = Js::str($src);
        $resources = $module ? $resumableState->moduleScriptResources : $resumableState->scriptResources;
        $resourceState = array_key_exists($key, $resources) ? $resources[$key] : false;
        if ($resourceState === null) {
            return;
        }
        if ($module) {
            $resumableState->moduleScriptResources[$key] = null;
            $props = ['src' => $src, 'type' => new JsString('module'), 'async' => JsBoolean::of(true)];
        } else {
            $resumableState->scriptResources[$key] = null;
            $props = ['src' => $src, 'async' => JsBoolean::of(true)];
        }
        if ($options instanceof JsObject) {
            foreach (Js::ownEnumerableKeys($options) as $optionKey) {
                $props[$optionKey] = Js::get($options, $optionKey);
            }
        }
        if (is_array($resourceState)) {
            if (count($resourceState) === 2) {
                self::adoptPreloadCredentials($props, $resourceState);
            }
            $preload = $module ? ($renderState->preloadModuleScripts[$key] ?? null) : ($renderState->preloadScripts[$key] ?? null);
            if ($preload !== null) {
                $preload->c = [];
            }
        }
        $chunks = new Chunks();
        $renderState->scripts[spl_object_id($chunks)] = $chunks;
        Dom::pushScriptImpl($chunks->c, $props);
        Server::enqueueFlush($request);
    }

    // Hoisting and flushing.

    public static function hoistHoistables(HoistableState $parent, HoistableState $child): void
    {
        foreach ($child->styles as $id => $queue) {
            $parent->styles[$id] ??= $queue;
        }
        foreach ($child->stylesheets as $id => $sheet) {
            $parent->stylesheets[$id] ??= $sheet;
        }
        if ($child->suspenseyImages) {
            $parent->suspenseyImages = true;
        }
    }

    public static function hasSuspenseyContent(HoistableState $state, bool $flushingInShell): bool
    {
        return $flushingInShell ? $state->suspenseyImages : ($state->stylesheets !== [] || $state->suspenseyImages);
    }

    /** @param array<int, Chunks> $resources */
    public static function flushResources(Destination $destination, array $resources): void
    {
        foreach ($resources as $resource) {
            foreach ($resource->c as $chunk) {
                $destination->buffer .= $chunk;
            }
            $resource->c = [];
        }
    }

    public static function flushStylesInPreamble(Destination $destination, RenderState $renderState): void
    {
        foreach ($renderState->styles as $styleQueue) {
            $hasStylesheets = $styleQueue->sheets !== [];
            foreach ($styleQueue->sheets as $sheet) {
                $chunks = [];
                Dom::pushLinkImpl($chunks, $sheet->props);
                $destination->buffer .= implode('', $chunks);
                $sheet->state = 2;
            }
            $styleQueue->sheets = [];
            if (!$hasStylesheets || $styleQueue->hrefs !== []) {
                $out = $renderState->startInlineStyle . ' data-precedence="' . $styleQueue->precedence;
                if ($styleQueue->hrefs !== []) {
                    $out .= '" data-href="' . implode(' ', $styleQueue->hrefs);
                }
                $out .= '">' . implode('', $styleQueue->rules) . '</style>';
                $destination->buffer .= $out;
                $styleQueue->rules = [];
                $styleQueue->hrefs = [];
            }
        }
    }

    public static function preloadLateStyles(Destination $destination, RenderState $renderState): void
    {
        foreach ($renderState->styles as $styleQueue) {
            foreach ($styleQueue->sheets as $sheet) {
                if ($sheet->state !== 0) {
                    continue;
                }
                $sheet->state = 1;
                $props = $sheet->props;
                $chunks = [];
                Dom::pushLinkImpl($chunks, [
                    'rel' => new JsString('preload'),
                    'as' => new JsString('style'),
                    'href' => $props['href'] ?? JsUndefined::instance(),
                    'crossOrigin' => $props['crossOrigin'] ?? JsUndefined::instance(),
                    'fetchPriority' => $props['fetchPriority'] ?? JsUndefined::instance(),
                    'integrity' => $props['integrity'] ?? JsUndefined::instance(),
                    'media' => $props['media'] ?? JsUndefined::instance(),
                    'hrefLang' => $props['hrefLang'] ?? JsUndefined::instance(),
                    'referrerPolicy' => $props['referrerPolicy'] ?? JsUndefined::instance(),
                ]);
                $destination->buffer .= implode('', $chunks);
            }
            $styleQueue->sheets = [];
        }
    }

    /** Returns whether the destination still has capacity (always, for a string buffer). */
    public static function writeHoistablesForBoundary(Destination $destination, HoistableState $hoistableState, RenderState $renderState): bool
    {
        $hasStylesToHoist = false;
        foreach ($hoistableState->styles as $styleQueue) {
            $hrefs = $styleQueue->hrefs;
            if ($hrefs === []) {
                continue;
            }
            $destination->buffer .= $renderState->startInlineStyle . ' media="not all" data-precedence="'
                . $styleQueue->precedence . '" data-href="' . implode(' ', $hrefs) . '">'
                . implode('', $styleQueue->rules) . '</style>';
            $hasStylesToHoist = true;
            $styleQueue->rules = [];
            $styleQueue->hrefs = [];
        }
        foreach ($hoistableState->stylesheets as $sheet) {
            if ($sheet->state !== 2) {
                $hasStylesToHoist = true;
            }
        }
        if ($hasStylesToHoist) {
            $renderState->stylesToHoist = true;
        }
        return true;
    }

    public static function writeStyleResourceDependenciesInJS(Destination $destination, HoistableState $hoistableState): void
    {
        $out = '[';
        $open = '[';
        foreach ($hoistableState->stylesheets as $resource) {
            if ($resource->state === 2) {
                continue;
            }
            $props = $resource->props;
            if ($resource->state === 3) {
                $out .= $open . self::escapeJSObject(Js::str($props['href'] ?? JsUndefined::instance())) . ']';
                $open = ',[';
                continue;
            }
            $out .= $open;
            $out .= self::escapeJSObject(Dom::sanitizeURL(Js::str($props['href'] ?? JsUndefined::instance())));
            $out .= ',' . self::escapeJSObject(Js::str($props['data-precedence'] ?? JsUndefined::instance()));
            foreach ($props as $key => $value) {
                $key = (string) $key;
                if (self::nullish($value)) {
                    continue;
                }
                switch ($key) {
                    case 'href':
                    case 'rel':
                    case 'precedence':
                    case 'data-precedence':
                        break;
                    case 'children':
                    case 'dangerouslySetInnerHTML':
                        throw Js::error('link is a self-closing tag and must neither have `children` nor use `dangerouslySetInnerHTML`.');
                    default:
                        $out .= self::styleResourceAttributeInJS($key, $value);
                }
            }
            $out .= ']';
            $open = ',[';
            $resource->state = 3;
        }
        $destination->buffer .= $out . ']';
    }

    private static function styleResourceAttributeInJS(string $name, JsValue $value): string
    {
        $attributeName = strtolower($name);
        if ($value instanceof JsFunction || $value instanceof JsSymbol) {
            return '';
        }
        switch ($name) {
            case 'innerHTML':
            case 'dangerouslySetInnerHTML':
            case 'suppressContentEditableWarning':
            case 'suppressHydrationWarning':
            case 'style':
            case 'ref':
                return '';
            case 'className':
                $attributeName = 'class';
                $string = Js::str($value);
                break;
            case 'hidden':
                if ($value instanceof JsBoolean && !$value->value) {
                    return '';
                }
                $string = '';
                break;
            case 'src':
            case 'href':
                $string = Dom::sanitizeURL(Js::str($value));
                break;
            default:
                if (
                    (strlen($name) > 2 && ($name[0] === 'o' || $name[0] === 'O') && ($name[1] === 'n' || $name[1] === 'N'))
                    || !Dom::isAttributeNameSafe($name)
                ) {
                    return '';
                }
                $string = Js::str($value);
        }
        return ',' . self::escapeJSObject($attributeName) . ',' . self::escapeJSObject($string);
    }

    /** escapeJSObjectForInstructionScripts on a string. */
    public static function escapeJSObject(string $input): string
    {
        return strtr(
            Server::jsonString($input),
            ['&' => '\\u0026', '>' => '\\u003e', '<' => '\\u003c', "\u{2028}" => '\\u2028', "\u{2029}" => '\\u2029'],
        );
    }

    /** escapeJSStringsForInstructionScripts on a string. */
    public static function escapeJSString(string $input): string
    {
        return strtr(Server::jsonString($input), ['<' => '\\u003c', "\u{2028}" => '\\u2028', "\u{2029}" => '\\u2029']);
    }
}
