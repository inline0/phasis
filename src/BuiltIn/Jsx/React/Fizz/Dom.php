<?php

/**
 * Ported from React 19.3.0's Fizz DOM config (ReactFizzConfigDOM and
 * ReactFizzConfigDOMLegacy, as built into react-dom-server.node.production.js
 * and react-dom-server-legacy.node.production.js).
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
use Phasis\BuiltIn\Jsx\React\ReactModule;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsBigInt;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * HTML serialization for the React profile: attributes, styles, start tags
 * for every element type React special-cases, text, and format contexts.
 */
final class Dom
{
    public const ROOT_HTML_MODE = 0;
    public const HTML_HTML_MODE = 1;
    public const HTML_MODE = 2;
    public const HTML_HEAD_MODE = 3;
    public const SVG_MODE = 4;
    public const MATHML_MODE = 5;
    public const HTML_TABLE_MODE = 6;
    public const HTML_TABLE_BODY_MODE = 7;
    public const HTML_TABLE_ROW_MODE = 8;
    public const HTML_COLGROUP_MODE = 9;

    public const TEXT_SEPARATOR = '<!-- -->';
    private const ACTION_JAVASCRIPT_URL = 'javascript:throw new Error(&#x27;React form unexpectedly submitted.&#x27;)';
    private const BLOCKED_URL = "javascript:throw new Error('React has blocked a javascript: URL as a security precaution.')";

    private const UNITLESS_NUMBERS = [
        'animationIterationCount' => true, 'aspectRatio' => true, 'borderImageOutset' => true,
        'borderImageSlice' => true, 'borderImageWidth' => true, 'boxFlex' => true, 'boxFlexGroup' => true,
        'boxOrdinalGroup' => true, 'columnCount' => true, 'columns' => true, 'flex' => true, 'flexGrow' => true,
        'flexPositive' => true, 'flexShrink' => true, 'flexNegative' => true, 'flexOrder' => true,
        'gridArea' => true, 'gridRow' => true, 'gridRowEnd' => true, 'gridRowSpan' => true,
        'gridRowStart' => true, 'gridColumn' => true, 'gridColumnEnd' => true, 'gridColumnSpan' => true,
        'gridColumnStart' => true, 'fontWeight' => true, 'lineClamp' => true, 'lineHeight' => true,
        'opacity' => true, 'order' => true, 'orphans' => true, 'scale' => true, 'tabSize' => true,
        'widows' => true, 'zIndex' => true, 'zoom' => true, 'fillOpacity' => true, 'floodOpacity' => true,
        'stopOpacity' => true, 'strokeDasharray' => true, 'strokeDashoffset' => true,
        'strokeMiterlimit' => true, 'strokeOpacity' => true, 'strokeWidth' => true,
        'MozAnimationIterationCount' => true, 'MozBoxFlex' => true, 'MozBoxFlexGroup' => true,
        'MozLineClamp' => true, 'msAnimationIterationCount' => true, 'msFlex' => true, 'msZoom' => true,
        'msFlexGrow' => true, 'msFlexNegative' => true, 'msFlexOrder' => true, 'msFlexPositive' => true,
        'msFlexShrink' => true, 'msGridColumn' => true, 'msGridColumnSpan' => true, 'msGridRow' => true,
        'msGridRowSpan' => true, 'WebkitAnimationIterationCount' => true, 'WebkitBoxFlex' => true,
        'WebKitBoxFlexGroup' => true, 'WebkitBoxOrdinalGroup' => true, 'WebkitColumnCount' => true,
        'WebkitColumns' => true, 'WebkitFlex' => true, 'WebkitFlexGrow' => true,
        'WebkitFlexPositive' => true, 'WebkitFlexShrink' => true, 'WebkitLineClamp' => true,
    ];

    private const ALIASES = [
        'acceptCharset' => 'accept-charset', 'htmlFor' => 'for', 'httpEquiv' => 'http-equiv',
        'crossOrigin' => 'crossorigin', 'accentHeight' => 'accent-height',
        'alignmentBaseline' => 'alignment-baseline', 'arabicForm' => 'arabic-form',
        'baselineShift' => 'baseline-shift', 'capHeight' => 'cap-height', 'clipPath' => 'clip-path',
        'clipRule' => 'clip-rule', 'colorInterpolation' => 'color-interpolation',
        'colorInterpolationFilters' => 'color-interpolation-filters', 'colorProfile' => 'color-profile',
        'colorRendering' => 'color-rendering', 'dominantBaseline' => 'dominant-baseline',
        'enableBackground' => 'enable-background', 'fillOpacity' => 'fill-opacity', 'fillRule' => 'fill-rule',
        'floodColor' => 'flood-color', 'floodOpacity' => 'flood-opacity', 'fontFamily' => 'font-family',
        'fontSize' => 'font-size', 'fontSizeAdjust' => 'font-size-adjust', 'fontStretch' => 'font-stretch',
        'fontStyle' => 'font-style', 'fontVariant' => 'font-variant', 'fontWeight' => 'font-weight',
        'glyphName' => 'glyph-name', 'glyphOrientationHorizontal' => 'glyph-orientation-horizontal',
        'glyphOrientationVertical' => 'glyph-orientation-vertical', 'horizAdvX' => 'horiz-adv-x',
        'horizOriginX' => 'horiz-origin-x', 'imageRendering' => 'image-rendering',
        'letterSpacing' => 'letter-spacing', 'lightingColor' => 'lighting-color', 'markerEnd' => 'marker-end',
        'markerMid' => 'marker-mid', 'markerStart' => 'marker-start', 'maskType' => 'mask-type',
        'overlinePosition' => 'overline-position', 'overlineThickness' => 'overline-thickness',
        'paintOrder' => 'paint-order', 'panose-1' => 'panose-1', 'pointerEvents' => 'pointer-events',
        'renderingIntent' => 'rendering-intent', 'shapeRendering' => 'shape-rendering',
        'stopColor' => 'stop-color', 'stopOpacity' => 'stop-opacity',
        'strikethroughPosition' => 'strikethrough-position',
        'strikethroughThickness' => 'strikethrough-thickness', 'strokeDasharray' => 'stroke-dasharray',
        'strokeDashoffset' => 'stroke-dashoffset', 'strokeLinecap' => 'stroke-linecap',
        'strokeLinejoin' => 'stroke-linejoin', 'strokeMiterlimit' => 'stroke-miterlimit',
        'strokeOpacity' => 'stroke-opacity', 'strokeWidth' => 'stroke-width', 'textAnchor' => 'text-anchor',
        'textDecoration' => 'text-decoration', 'textRendering' => 'text-rendering',
        'transformOrigin' => 'transform-origin', 'underlinePosition' => 'underline-position',
        'underlineThickness' => 'underline-thickness', 'unicodeBidi' => 'unicode-bidi',
        'unicodeRange' => 'unicode-range', 'unitsPerEm' => 'units-per-em', 'vAlphabetic' => 'v-alphabetic',
        'vHanging' => 'v-hanging', 'vIdeographic' => 'v-ideographic', 'vMathematical' => 'v-mathematical',
        'vectorEffect' => 'vector-effect', 'vertAdvY' => 'vert-adv-y', 'vertOriginX' => 'vert-origin-x',
        'vertOriginY' => 'vert-origin-y', 'wordSpacing' => 'word-spacing', 'writingMode' => 'writing-mode',
        'xmlnsXlink' => 'xmlns:xlink', 'xHeight' => 'x-height',
    ];

    /** React's VALID_ATTRIBUTE_NAME_REGEX (XML Name production). */
    private const ATTRIBUTE_NAME_START = ':A-Z_a-z\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}\x{0370}-\x{037D}\x{037F}-\x{1FFF}'
        . '\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}';
    private const ATTRIBUTE_NAME_PATTERN = '/^[' . self::ATTRIBUTE_NAME_START . '][' . self::ATTRIBUTE_NAME_START
        . '\-.0-9\x{00B7}\x{0300}-\x{036F}\x{203F}-\x{2040}]*$/u';

    /** React's isJavaScriptProtocol. */
    private const JAVASCRIPT_URL_PATTERN = '/^[\x00-\x1F ]*j[\r\n\t]*a[\r\n\t]*v[\r\n\t]*a[\r\n\t]*s'
        . '[\r\n\t]*c[\r\n\t]*r[\r\n\t]*i[\r\n\t]*p[\r\n\t]*t[\r\n\t]*:/i';

    private const ESCAPES = ['"' => '&quot;', '&' => '&amp;', "'" => '&#x27;', '<' => '&lt;', '>' => '&gt;'];

    /** @var array<string, bool> */
    private static array $attributeNameCache = [];

    /** @var array<string, string> */
    private static array $styleNameCache = [];

    /** @var array<string, string> */
    private static array $tagStartCache = [];

    // Text and attribute escaping.

    public static function escapeText(string $text): string
    {
        if (strpbrk($text, '"&\'<>') === false) {
            return $text;
        }
        return strtr($text, self::ESCAPES);
    }

    /** escapeTextForBrowser: booleans, numbers and bigints are stringified as-is. */
    public static function esc(JsValue|string $value): string
    {
        if (is_string($value)) {
            return self::escapeText($value);
        }
        if ($value instanceof JsString) {
            return self::escapeText($value->value);
        }
        if ($value instanceof JsBoolean || $value instanceof JsNumber || $value instanceof JsBigInt) {
            return Js::str($value);
        }
        return self::escapeText(Js::str($value));
    }

    public static function isAttributeNameSafe(string $name): bool
    {
        if (isset(self::$attributeNameCache[$name])) {
            return self::$attributeNameCache[$name];
        }
        $ok = preg_match(
            self::ATTRIBUTE_NAME_PATTERN,
            $name,
        ) === 1;
        return self::$attributeNameCache[$name] = $ok;
    }

    public static function sanitizeURL(string $url): string
    {
        if (
            preg_match(self::JAVASCRIPT_URL_PATTERN, $url) === 1
        ) {
            return self::BLOCKED_URL;
        }
        return $url;
    }

    /**
     * Own enumerable props with non-nullish values, in for-in order: the
     * `for (propKey in props) if (hasOwnProperty.call(props, propKey))`
     * loops of React.
     *
     * @param JsObject|array<string, JsValue> $props
     * @return list<array{0: string, 1: JsValue}>
     */
    public static function propEntries(JsObject|array $props): array
    {
        $entries = [];
        if (is_array($props)) {
            foreach ($props as $key => $value) {
                if (!$value instanceof JsUndefined && !$value instanceof JsNull) {
                    $entries[] = [(string) $key, $value];
                }
            }
            return $entries;
        }
        $slots = $props->properties;
        if ($slots->descriptors === [] && get_class($props) === JsObject::class) {
            foreach ($slots->dataSlots as $key => $value) {
                if (is_int($key)) {
                    return self::propEntriesSlow($props);
                }
                if (!$value instanceof JsUndefined && !$value instanceof JsNull) {
                    $entries[] = [$key, $value];
                }
            }
            return $entries;
        }
        return self::propEntriesSlow($props);
    }

    /** @return list<array{0: string, 1: JsValue}> */
    private static function propEntriesSlow(JsObject $props): array
    {
        $entries = [];
        foreach (Js::ownEnumerableKeys($props) as $key) {
            $value = Js::get($props, $key);
            if (!$value instanceof JsUndefined && !$value instanceof JsNull) {
                $entries[] = [$key, $value];
            }
        }
        return $entries;
    }

    /**
     * `props[name]` for a JS props object or an internal props array.
     *
     * @param JsObject|array<string, JsValue> $props
     */
    public static function prop(JsObject|array $props, string $name): JsValue
    {
        if (is_array($props)) {
            return $props[$name] ?? JsUndefined::instance();
        }
        return $props->properties->dataSlots[$name] ?? $props->get($name);
    }

    private static function nullish(?JsValue $value): bool
    {
        return $value === null || $value instanceof JsUndefined || $value instanceof JsNull;
    }

    private static function fnOrSymbol(JsValue $value): bool
    {
        return $value instanceof JsFunction || $value instanceof JsSymbol;
    }

    // Styles and attributes.

    /** @param list<string> $target */
    public static function pushStyleAttribute(array &$target, JsValue $style): void
    {
        if (!$style instanceof JsObject || $style instanceof JsFunction) {
            throw Js::error(
                "The `style` prop expects a mapping from style properties to values, not a string. For example, "
                . "style={{marginRight: spacing + 'em'}} when using JSX.",
            );
        }
        $isFirst = true;
        foreach (self::propEntries($style) as [$styleName, $styleValue]) {
            if ($styleValue instanceof JsBoolean || ($styleValue instanceof JsString && $styleValue->value === '')) {
                continue;
            }
            if (str_starts_with($styleName, '--')) {
                $nameChunk = self::escapeText($styleName);
                $valueChunk = self::escapeText(TypeConversion::trimWhitespace(Js::str($styleValue)));
            } else {
                $nameChunk = self::$styleNameCache[$styleName] ??= self::escapeText(self::hyphenateStyleName($styleName));
                if ($styleValue instanceof JsNumber) {
                    $valueChunk = $styleValue->value === 0.0 || isset(self::UNITLESS_NUMBERS[$styleName])
                        ? Js::str($styleValue)
                        : Js::str($styleValue) . 'px';
                } else {
                    $valueChunk = self::escapeText(TypeConversion::trimWhitespace(Js::str($styleValue)));
                }
            }
            if ($isFirst) {
                $isFirst = false;
                $target[] = ' style="';
            } else {
                $target[] = ';';
            }
            $target[] = $nameChunk;
            $target[] = ':';
            $target[] = $valueChunk;
        }
        if (!$isFirst) {
            $target[] = '"';
        }
    }

    private static function hyphenateStyleName(string $name): string
    {
        $name = mb_strtolower((string) preg_replace('/([A-Z])/', '-$1', $name), 'UTF-8');
        return (string) preg_replace('/^ms-/', '-ms-', $name);
    }

    /** @param list<string> $target */
    public static function pushBooleanAttribute(array &$target, string $name, JsValue $value): void
    {
        if ($value->toBoolean() && !self::fnOrSymbol($value)) {
            $target[] = ' ' . $name . '=""';
        }
    }

    /** @param list<string> $target */
    public static function pushStringAttribute(array &$target, string $name, JsValue|string $value): void
    {
        if (is_string($value)) {
            $target[] = ' ' . $name . '="' . self::escapeText($value) . '"';
            return;
        }
        if (!self::fnOrSymbol($value) && !$value instanceof JsBoolean) {
            $target[] = ' ' . $name . '="' . self::esc($value) . '"';
        }
    }

    /** @param list<string> $target */
    public static function pushAttribute(array &$target, string $name, JsValue $value): void
    {
        switch ($name) {
            case 'className':
                self::pushStringAttribute($target, 'class', $value);
                return;
            case 'tabIndex':
                self::pushStringAttribute($target, 'tabindex', $value);
                return;
            case 'dir':
            case 'role':
            case 'viewBox':
            case 'width':
            case 'height':
                self::pushStringAttribute($target, $name, $value);
                return;
            case 'style':
                self::pushStyleAttribute($target, $value);
                return;
            case 'src':
            case 'href':
                if ($value instanceof JsString && $value->value === '') {
                    return;
                }
                // fall through
            case 'action':
            case 'formAction':
                if (self::nullish($value) || self::fnOrSymbol($value) || $value instanceof JsBoolean) {
                    return;
                }
                $target[] = ' ' . $name . '="' . self::escapeText(self::sanitizeURL(Js::str($value))) . '"';
                return;
            case 'defaultValue':
            case 'defaultChecked':
            case 'innerHTML':
            case 'suppressContentEditableWarning':
            case 'suppressHydrationWarning':
            case 'ref':
                return;
            case 'autoFocus':
            case 'multiple':
            case 'muted':
                self::pushBooleanAttribute($target, strtolower($name), $value);
                return;
            case 'xlinkHref':
                if (self::fnOrSymbol($value) || $value instanceof JsBoolean) {
                    return;
                }
                $target[] = ' xlink:href="' . self::escapeText(self::sanitizeURL(Js::str($value))) . '"';
                return;
            case 'contentEditable':
            case 'spellCheck':
            case 'draggable':
            case 'value':
            case 'autoReverse':
            case 'externalResourcesRequired':
            case 'focusable':
            case 'preserveAlpha':
                if (!self::fnOrSymbol($value)) {
                    $target[] = ' ' . $name . '="' . self::esc($value) . '"';
                }
                return;
            case 'inert':
            case 'allowFullScreen':
            case 'async':
            case 'autoPlay':
            case 'controls':
            case 'credentialless':
            case 'default':
            case 'defer':
            case 'disabled':
            case 'disablePictureInPicture':
            case 'disableRemotePlayback':
            case 'formNoValidate':
            case 'hidden':
            case 'loop':
            case 'noModule':
            case 'noValidate':
            case 'open':
            case 'playsInline':
            case 'readOnly':
            case 'required':
            case 'reversed':
            case 'scoped':
            case 'seamless':
            case 'itemScope':
                if ($value->toBoolean() && !self::fnOrSymbol($value)) {
                    $target[] = ' ' . $name . '=""';
                }
                return;
            case 'capture':
            case 'download':
                if ($value instanceof JsBoolean && $value->value) {
                    $target[] = ' ' . $name . '=""';
                } elseif (!($value instanceof JsBoolean && !$value->value) && !self::fnOrSymbol($value)) {
                    $target[] = ' ' . $name . '="' . self::esc($value) . '"';
                }
                return;
            case 'cols':
            case 'rows':
            case 'size':
            case 'span':
                if (!self::fnOrSymbol($value)) {
                    $number = TypeConversion::toNumber($value);
                    if (!is_nan($number) && $number >= 1) {
                        $target[] = ' ' . $name . '="' . self::esc($value) . '"';
                    }
                }
                return;
            case 'rowSpan':
            case 'start':
                if (!self::fnOrSymbol($value) && !is_nan(TypeConversion::toNumber($value))) {
                    $target[] = ' ' . $name . '="' . self::esc($value) . '"';
                }
                return;
            case 'xlinkActuate':
                self::pushStringAttribute($target, 'xlink:actuate', $value);
                return;
            case 'xlinkArcrole':
                self::pushStringAttribute($target, 'xlink:arcrole', $value);
                return;
            case 'xlinkRole':
                self::pushStringAttribute($target, 'xlink:role', $value);
                return;
            case 'xlinkShow':
                self::pushStringAttribute($target, 'xlink:show', $value);
                return;
            case 'xlinkTitle':
                self::pushStringAttribute($target, 'xlink:title', $value);
                return;
            case 'xlinkType':
                self::pushStringAttribute($target, 'xlink:type', $value);
                return;
            case 'xmlBase':
                self::pushStringAttribute($target, 'xml:base', $value);
                return;
            case 'xmlLang':
                self::pushStringAttribute($target, 'xml:lang', $value);
                return;
            case 'xmlSpace':
                self::pushStringAttribute($target, 'xml:space', $value);
                return;
        }
        if (strlen($name) > 2 && ($name[0] === 'o' || $name[0] === 'O') && ($name[1] === 'n' || $name[1] === 'N')) {
            return;
        }
        $name = self::ALIASES[$name] ?? $name;
        if (!self::isAttributeNameSafe($name)) {
            return;
        }
        if (self::fnOrSymbol($value)) {
            return;
        }
        if ($value instanceof JsBoolean) {
            $prefix = substr(strtolower($name), 0, 5);
            if ($prefix !== 'data-' && $prefix !== 'aria-') {
                return;
            }
        }
        $target[] = ' ' . $name . '="' . self::esc($value) . '"';
    }

    /** @param list<string> $target */
    public static function pushInnerHTML(array &$target, ?JsValue $innerHTML, ?JsValue $children): void
    {
        if (self::nullish($innerHTML)) {
            return;
        }
        if (!self::nullish($children)) {
            throw Js::error('Can only set one of `children` or `props.dangerouslySetInnerHTML`.');
        }
        if (!$innerHTML instanceof JsObject || $innerHTML instanceof JsFunction || !Js::has($innerHTML, '__html')) {
            throw Js::error(
                '`props.dangerouslySetInnerHTML` must be in the form `{__html: ...}`. Please visit '
                . 'https://react.dev/link/dangerously-set-inner-html for more information.',
            );
        }
        $html = Js::get($innerHTML, '__html');
        if (!self::nullish($html)) {
            $target[] = Js::str($html);
        }
    }

    private static function flattenOptionChildren(JsValue $children): string
    {
        $content = new JsString('');
        $children = ReactModule::childrenToArray($children);
        foreach ($children as $child) {
            if (!self::nullish($child)) {
                $content = AbstractOperations::add($content, $child);
            }
        }
        return Js::str($content);
    }

    // Forms.

    private static function injectFormReplayingRuntime(ResumableState $resumableState, RenderState $renderState): void
    {
        if (($resumableState->instructions & 16) !== 0) {
            return;
        }
        $resumableState->instructions |= 16;
        $preamble = $renderState->preamble;
        if (($preamble->htmlChunks !== null || $preamble->headChunks !== null) && $renderState->bootstrapChunks === []) {
            $renderState->bootstrapChunks[] = $renderState->startInlineScript;
            self::pushCompletedShellIdAttribute($renderState->bootstrapChunks, $resumableState);
            $renderState->bootstrapChunks[] = '>';
            $renderState->bootstrapChunks[] = Scripts::FORM_REPLAYING_RUNTIME_SCRIPT;
            $renderState->bootstrapChunks[] = '</script>';
        } else {
            array_unshift($renderState->bootstrapChunks, $renderState->startInlineScript, '>', Scripts::FORM_REPLAYING_RUNTIME_SCRIPT, '</script>');
        }
    }

    /** @param list<string> $target */
    public static function pushCompletedShellIdAttribute(array &$target, ResumableState $resumableState): void
    {
        if (($resumableState->instructions & 32) === 0) {
            $resumableState->instructions |= 32;
            $target[] = ' id="';
            $target[] = self::escapeText('_' . $resumableState->idPrefix . 'R_');
            $target[] = '"';
        }
    }

    private static function getCustomFormFields(ResumableState $resumableState, JsValue $formAction): ?JsValue
    {
        $formActionFn = Js::get($formAction, '$$FORM_ACTION');
        if ($formActionFn instanceof JsFunction) {
            $id = $resumableState->nextFormID++;
            $prefix = $resumableState->idPrefix . $id;
            try {
                $customFields = Js::call($formActionFn, $formAction, [new JsString($prefix)]);
                if ($customFields->toBoolean()) {
                    $formData = Js::get($customFields, 'data');
                    if (!self::nullish($formData)) {
                        self::forEachFormField($formData, static function (JsValue $value): void {
                            self::validateAdditionalFormField($value);
                        });
                    }
                }
                return $customFields;
            } catch (\Throwable $e) {
                $x = Js::thrownValue($e);
                if ($x instanceof JsObject && Js::get($x, 'then') instanceof JsFunction) {
                    throw $e;
                }
            }
        }
        return null;
    }

    private static function validateAdditionalFormField(JsValue $value): void
    {
        if (!$value instanceof JsString) {
            throw Js::error('File/Blob fields are not yet supported in progressive forms. Will fallback to client hydration.');
        }
    }

    /** @param \Closure(JsValue, JsValue): void $callback */
    private static function forEachFormField(JsValue $formData, \Closure $callback): void
    {
        Js::call(Js::get($formData, 'forEach'), $formData, [
            Js::fn('', static function (JsValue $this_, array $args) use ($callback): JsValue {
                $callback($args[0] ?? JsUndefined::instance(), $args[1] ?? JsUndefined::instance());
                return JsUndefined::instance();
            }, 2),
        ]);
    }

    /** @param list<string> $target */
    private static function pushAdditionalFormFields(array &$target, JsValue $formData): void
    {
        self::forEachFormField($formData, static function (JsValue $value, JsValue $key) use (&$target): void {
            $target[] = '<input type="hidden"';
            self::validateAdditionalFormField($value);
            self::pushStringAttribute($target, 'name', $key);
            self::pushStringAttribute($target, 'value', $value);
            $target[] = '/>';
        });
    }

    /** @param list<string> $target */
    private static function pushFormActionAttribute(
        array &$target,
        ResumableState $resumableState,
        RenderState $renderState,
        ?JsValue $formAction,
        ?JsValue $formEncType,
        ?JsValue $formMethod,
        ?JsValue $formTarget,
        ?JsValue $name,
    ): ?JsValue {
        $formData = null;
        if ($formAction instanceof JsFunction) {
            $customFields = self::getCustomFormFields($resumableState, $formAction);
            if ($customFields !== null) {
                $name = Js::get($customFields, 'name');
                $action = Js::get($customFields, 'action');
                $formAction = $action->toBoolean() ? $action : new JsString('');
                $formEncType = Js::get($customFields, 'encType');
                $formMethod = Js::get($customFields, 'method');
                $formTarget = Js::get($customFields, 'target');
                $formData = Js::get($customFields, 'data');
            } else {
                $target[] = ' formAction="' . self::ACTION_JAVASCRIPT_URL . '"';
                $formTarget = $formMethod = $formEncType = $formAction = $name = null;
                self::injectFormReplayingRuntime($resumableState, $renderState);
            }
        }
        if (!self::nullish($name)) {
            self::pushAttribute($target, 'name', $name);
        }
        if (!self::nullish($formAction)) {
            self::pushAttribute($target, 'formAction', $formAction);
        }
        if (!self::nullish($formEncType)) {
            self::pushAttribute($target, 'formEncType', $formEncType);
        }
        if (!self::nullish($formMethod)) {
            self::pushAttribute($target, 'formMethod', $formMethod);
        }
        if (!self::nullish($formTarget)) {
            self::pushAttribute($target, 'formTarget', $formTarget);
        }
        return $formData;
    }

    // Element start tags.

    public static function startChunkForTag(string $tag): string
    {
        if (isset(self::$tagStartCache[$tag])) {
            return self::$tagStartCache[$tag];
        }
        if (preg_match('/^[a-zA-Z][a-zA-Z:_\.\-\d]*$/', $tag) !== 1) {
            throw Js::error('Invalid tag: ' . $tag);
        }
        return self::$tagStartCache[$tag] = '<' . $tag;
    }

    public static function endChunkForTag(string $tag): string
    {
        return '</' . $tag . '>';
    }

    /**
     * @param list<string> $target
     * @param JsObject|array<string, JsValue> $props
     */
    public static function pushLinkImpl(array &$target, JsObject|array $props): null
    {
        $target[] = self::startChunkForTag('link');
        foreach (self::propEntries($props) as [$key, $value]) {
            if ($key === 'children' || $key === 'dangerouslySetInnerHTML') {
                throw Js::error('link is a self-closing tag and must neither have `children` nor use `dangerouslySetInnerHTML`.');
            }
            self::pushAttribute($target, $key, $value);
        }
        $target[] = '/>';
        return null;
    }

    /** @param list<string> $target */
    private static function pushSelfClosing(array &$target, JsObject $props, string $tag, FormatContext $formatContext): null
    {
        $target[] = self::startChunkForTag($tag);
        foreach (self::propEntries($props) as [$key, $value]) {
            if ($key === 'children' || $key === 'dangerouslySetInnerHTML') {
                throw Js::error($tag . ' is a self-closing tag and must neither have `children` nor use `dangerouslySetInnerHTML`.');
            }
            self::pushAttribute($target, $key, $value);
        }
        self::pushViewTransitionAttributes($target, $formatContext);
        $target[] = '/>';
        return null;
    }

    /** @param list<string> $target */
    private static function pushTitleImpl(array &$target, JsObject $props): null
    {
        $target[] = self::startChunkForTag('title');
        $children = null;
        $innerHTML = null;
        foreach (self::propEntries($props) as [$key, $value]) {
            switch ($key) {
                case 'children':
                    $children = $value;
                    break;
                case 'dangerouslySetInnerHTML':
                    $innerHTML = $value;
                    break;
                default:
                    self::pushAttribute($target, $key, $value);
            }
        }
        $target[] = '>';
        $child = $children;
        if ($children !== null && Js::isArray($children)) {
            $elements = Js::elements($children);
            $child = count($elements) < 2 ? ($elements[0] ?? JsUndefined::instance()) : null;
        }
        if ($child !== null && !self::fnOrSymbol($child) && !self::nullish($child)) {
            $target[] = self::escapeText(Js::str($child));
        }
        self::pushInnerHTML($target, $innerHTML, $children);
        $target[] = self::endChunkForTag('title');
        return null;
    }

    /**
     * @param list<string> $target
     * @param JsObject|array<string, JsValue> $props
     */
    public static function pushScriptImpl(array &$target, JsObject|array $props): null
    {
        $target[] = self::startChunkForTag('script');
        $children = null;
        $innerHTML = null;
        foreach (self::propEntries($props) as [$key, $value]) {
            switch ($key) {
                case 'children':
                    $children = $value;
                    break;
                case 'dangerouslySetInnerHTML':
                    $innerHTML = $value;
                    break;
                default:
                    self::pushAttribute($target, $key, $value);
            }
        }
        $target[] = '>';
        self::pushInnerHTML($target, $innerHTML, $children);
        if ($children instanceof JsString) {
            $target[] = self::escapeScript($children->value);
        }
        $target[] = self::endChunkForTag('script');
        return null;
    }

    public static function escapeScript(string $text): string
    {
        return (string) preg_replace_callback(
            '/(<\/|<)(s)(cript)/i',
            static fn (array $m): string => $m[1] . ($m[2] === 's' ? '\\u0073' : '\\u0053') . $m[3],
            $text,
        );
    }

    public static function escapeStyle(string $text): string
    {
        return (string) preg_replace_callback(
            '/(<\/|<)(s)(tyle)/i',
            static fn (array $m): string => $m[1] . ($m[2] === 's' ? '\\73 ' : '\\53 ') . $m[3],
            $text,
        );
    }

    /** @param list<string> $target */
    private static function pushStartSingletonElement(array &$target, JsObject $props, string $tag, FormatContext $formatContext): ?JsValue
    {
        $target[] = self::startChunkForTag($tag);
        $children = null;
        $innerHTML = null;
        foreach (self::propEntries($props) as [$key, $value]) {
            switch ($key) {
                case 'children':
                    $children = $value;
                    break;
                case 'dangerouslySetInnerHTML':
                    $innerHTML = $value;
                    break;
                default:
                    self::pushAttribute($target, $key, $value);
            }
        }
        self::pushViewTransitionAttributes($target, $formatContext);
        $target[] = '>';
        self::pushInnerHTML($target, $innerHTML, $children);
        return $children;
    }

    /** @param list<string> $target */
    private static function pushStartGenericElement(array &$target, JsObject $props, string $tag, FormatContext $formatContext): ?JsValue
    {
        $target[] = self::$tagStartCache[$tag] ?? self::startChunkForTag($tag);
        $children = null;
        $innerHTML = null;
        foreach (self::propEntries($props) as [$key, $value]) {
            switch ($key) {
                case 'children':
                    $children = $value;
                    break;
                case 'dangerouslySetInnerHTML':
                    $innerHTML = $value;
                    break;
                default:
                    self::pushAttribute($target, $key, $value);
            }
        }
        if ($formatContext->viewTransition !== null) {
            self::pushViewTransitionAttributes($target, $formatContext);
        }
        $target[] = '>';
        if ($innerHTML !== null) {
            self::pushInnerHTML($target, $innerHTML, $children);
        }
        if ($children instanceof JsString) {
            $target[] = self::escapeText($children->value);
            return null;
        }
        return $children;
    }

    /**
     * Elements whose children React renders itself (as text) or not at all
     * return null; the rest return their children for the renderer to walk.
     * `undefined` marks a hoisted title, style or meta, as in React.
     *
     * @param list<string> $target
     */
    public static function pushStartInstance(
        array &$target,
        string $type,
        JsObject $props,
        ResumableState $resumableState,
        RenderState $renderState,
        ?PreambleState $preambleState,
        ?HoistableState $hoistableState,
        FormatContext $formatContext,
        bool $textEmbedded,
    ): ?JsValue {
        switch ($type) {
            case 'div':
            case 'span':
            case 'svg':
            case 'path':
            case 'g':
            case 'p':
            case 'li':
            case 'annotation-xml':
            case 'color-profile':
            case 'font-face':
            case 'font-face-src':
            case 'font-face-uri':
            case 'font-face-format':
            case 'font-face-name':
            case 'missing-glyph':
                return self::pushStartGenericElement($target, $props, $type, $formatContext);
            case 'a':
                $target[] = self::startChunkForTag('a');
                $children = null;
                $innerHTML = null;
                foreach (self::propEntries($props) as [$key, $value]) {
                    switch ($key) {
                        case 'children':
                            $children = $value;
                            break;
                        case 'dangerouslySetInnerHTML':
                            $innerHTML = $value;
                            break;
                        case 'href':
                            if ($value instanceof JsString && $value->value === '') {
                                self::pushStringAttribute($target, 'href', '');
                            } else {
                                self::pushAttribute($target, $key, $value);
                            }
                            break;
                        default:
                            self::pushAttribute($target, $key, $value);
                    }
                }
                self::pushViewTransitionAttributes($target, $formatContext);
                $target[] = '>';
                self::pushInnerHTML($target, $innerHTML, $children);
                if ($children instanceof JsString) {
                    $target[] = self::escapeText($children->value);
                    return null;
                }
                return $children;
            case 'select':
                $target[] = self::startChunkForTag('select');
                $children = null;
                $innerHTML = null;
                foreach (self::propEntries($props) as [$key, $value]) {
                    switch ($key) {
                        case 'children':
                            $children = $value;
                            break;
                        case 'dangerouslySetInnerHTML':
                            $innerHTML = $value;
                            break;
                        case 'defaultValue':
                        case 'value':
                            break;
                        default:
                            self::pushAttribute($target, $key, $value);
                    }
                }
                self::pushViewTransitionAttributes($target, $formatContext);
                $target[] = '>';
                self::pushInnerHTML($target, $innerHTML, $children);
                return $children;
            case 'option':
                $selectedValue = $formatContext->selectedValue;
                $target[] = self::startChunkForTag('option');
                $children = null;
                $value = null;
                $selected = null;
                $innerHTML = null;
                foreach (self::propEntries($props) as [$key, $propValue]) {
                    switch ($key) {
                        case 'children':
                            $children = $propValue;
                            break;
                        case 'selected':
                            $selected = $propValue;
                            break;
                        case 'dangerouslySetInnerHTML':
                            $innerHTML = $propValue;
                            break;
                        case 'value':
                            $value = $propValue;
                            self::pushAttribute($target, $key, $propValue);
                            break;
                        default:
                            self::pushAttribute($target, $key, $propValue);
                    }
                }
                if (!self::nullish($selectedValue)) {
                    $stringValue = $value !== null ? Js::str($value) : self::flattenOptionChildren($children ?? JsNull::instance());
                    if (Js::isArray($selectedValue)) {
                        foreach (Js::elements($selectedValue) as $candidate) {
                            if (Js::str($candidate) === $stringValue) {
                                $target[] = ' selected=""';
                                break;
                            }
                        }
                    } elseif (Js::str($selectedValue) === $stringValue) {
                        $target[] = ' selected=""';
                    }
                } elseif ($selected !== null && $selected->toBoolean()) {
                    $target[] = ' selected=""';
                }
                $target[] = '>';
                self::pushInnerHTML($target, $innerHTML, $children);
                return $children;
            case 'textarea':
                $target[] = self::startChunkForTag('textarea');
                $value = null;
                $defaultValue = null;
                $children = null;
                foreach (self::propEntries($props) as [$key, $propValue]) {
                    switch ($key) {
                        case 'children':
                            $children = $propValue;
                            break;
                        case 'value':
                            $value = $propValue;
                            break;
                        case 'defaultValue':
                            $defaultValue = $propValue;
                            break;
                        case 'dangerouslySetInnerHTML':
                            throw Js::error('`dangerouslySetInnerHTML` does not make sense on <textarea>.');
                        default:
                            self::pushAttribute($target, $key, $propValue);
                    }
                }
                if ($value === null && $defaultValue !== null) {
                    $value = $defaultValue;
                }
                self::pushViewTransitionAttributes($target, $formatContext);
                $target[] = '>';
                if ($children !== null) {
                    if ($value !== null) {
                        throw Js::error('If you supply `defaultValue` on a <textarea>, do not pass children.');
                    }
                    if (Js::isArray($children)) {
                        $elements = Js::elements($children);
                        if (count($elements) > 1) {
                            throw Js::error('<textarea> can only have at most one child.');
                        }
                        Js::str($elements[0] ?? JsUndefined::instance());
                    }
                    $value = new JsString(Js::str($children));
                }
                if ($value instanceof JsString && ($value->value[0] ?? '') === "\n") {
                    $target[] = "\n";
                }
                if ($value !== null) {
                    $target[] = self::escapeText(Js::str($value));
                }
                return null;
            case 'input':
                $target[] = self::startChunkForTag('input');
                $name = $formAction = $formEncType = $formMethod = $formTarget = null;
                $value = $defaultValue = $checked = $defaultChecked = null;
                foreach (self::propEntries($props) as [$key, $propValue]) {
                    switch ($key) {
                        case 'children':
                        case 'dangerouslySetInnerHTML':
                            throw Js::error('input is a self-closing tag and must neither have `children` nor use `dangerouslySetInnerHTML`.');
                        case 'name':
                            $name = $propValue;
                            break;
                        case 'formAction':
                            $formAction = $propValue;
                            break;
                        case 'formEncType':
                            $formEncType = $propValue;
                            break;
                        case 'formMethod':
                            $formMethod = $propValue;
                            break;
                        case 'formTarget':
                            $formTarget = $propValue;
                            break;
                        case 'defaultChecked':
                            $defaultChecked = $propValue;
                            break;
                        case 'defaultValue':
                            $defaultValue = $propValue;
                            break;
                        case 'checked':
                            $checked = $propValue;
                            break;
                        case 'value':
                            $value = $propValue;
                            break;
                        default:
                            self::pushAttribute($target, $key, $propValue);
                    }
                }
                $formData = self::pushFormActionAttribute(
                    $target,
                    $resumableState,
                    $renderState,
                    $formAction,
                    $formEncType,
                    $formMethod,
                    $formTarget,
                    $name,
                );
                if ($checked !== null) {
                    self::pushBooleanAttribute($target, 'checked', $checked);
                } elseif ($defaultChecked !== null) {
                    self::pushBooleanAttribute($target, 'checked', $defaultChecked);
                }
                if ($value !== null) {
                    self::pushAttribute($target, 'value', $value);
                } elseif ($defaultValue !== null) {
                    self::pushAttribute($target, 'value', $defaultValue);
                }
                self::pushViewTransitionAttributes($target, $formatContext);
                $target[] = '/>';
                if (!self::nullish($formData)) {
                    self::pushAdditionalFormFields($target, $formData);
                }
                return null;
            case 'button':
                $target[] = self::startChunkForTag('button');
                $children = $innerHTML = $name = $formAction = $formEncType = $formMethod = $formTarget = null;
                foreach (self::propEntries($props) as [$key, $propValue]) {
                    switch ($key) {
                        case 'children':
                            $children = $propValue;
                            break;
                        case 'dangerouslySetInnerHTML':
                            $innerHTML = $propValue;
                            break;
                        case 'name':
                            $name = $propValue;
                            break;
                        case 'formAction':
                            $formAction = $propValue;
                            break;
                        case 'formEncType':
                            $formEncType = $propValue;
                            break;
                        case 'formMethod':
                            $formMethod = $propValue;
                            break;
                        case 'formTarget':
                            $formTarget = $propValue;
                            break;
                        default:
                            self::pushAttribute($target, $key, $propValue);
                    }
                }
                $formData = self::pushFormActionAttribute(
                    $target,
                    $resumableState,
                    $renderState,
                    $formAction,
                    $formEncType,
                    $formMethod,
                    $formTarget,
                    $name,
                );
                self::pushViewTransitionAttributes($target, $formatContext);
                $target[] = '>';
                if (!self::nullish($formData)) {
                    self::pushAdditionalFormFields($target, $formData);
                }
                self::pushInnerHTML($target, $innerHTML, $children);
                if ($children instanceof JsString) {
                    $target[] = self::escapeText($children->value);
                    return null;
                }
                return $children;
            case 'form':
                $target[] = self::startChunkForTag('form');
                $children = $innerHTML = $formAction = $formEncType = $formMethod = $formTarget = null;
                foreach (self::propEntries($props) as [$key, $propValue]) {
                    switch ($key) {
                        case 'children':
                            $children = $propValue;
                            break;
                        case 'dangerouslySetInnerHTML':
                            $innerHTML = $propValue;
                            break;
                        case 'action':
                            $formAction = $propValue;
                            break;
                        case 'encType':
                            $formEncType = $propValue;
                            break;
                        case 'method':
                            $formMethod = $propValue;
                            break;
                        case 'target':
                            $formTarget = $propValue;
                            break;
                        default:
                            self::pushAttribute($target, $key, $propValue);
                    }
                }
                $formData = null;
                $formActionName = null;
                if ($formAction instanceof JsFunction) {
                    $customFields = self::getCustomFormFields($resumableState, $formAction);
                    if ($customFields !== null) {
                        $action = Js::get($customFields, 'action');
                        $formAction = $action->toBoolean() ? $action : new JsString('');
                        $formEncType = Js::get($customFields, 'encType');
                        $formMethod = Js::get($customFields, 'method');
                        $formTarget = Js::get($customFields, 'target');
                        $formData = Js::get($customFields, 'data');
                        $formActionName = Js::get($customFields, 'name');
                    } else {
                        $target[] = ' action="' . self::ACTION_JAVASCRIPT_URL . '"';
                        $formTarget = $formMethod = $formEncType = $formAction = null;
                        self::injectFormReplayingRuntime($resumableState, $renderState);
                    }
                }
                if (!self::nullish($formAction)) {
                    self::pushAttribute($target, 'action', $formAction);
                }
                if (!self::nullish($formEncType)) {
                    self::pushAttribute($target, 'encType', $formEncType);
                }
                if (!self::nullish($formMethod)) {
                    self::pushAttribute($target, 'method', $formMethod);
                }
                if (!self::nullish($formTarget)) {
                    self::pushAttribute($target, 'target', $formTarget);
                }
                self::pushViewTransitionAttributes($target, $formatContext);
                $target[] = '>';
                if ($formActionName !== null && !$formActionName instanceof JsNull) {
                    $target[] = '<input type="hidden"';
                    self::pushStringAttribute($target, 'name', $formActionName);
                    $target[] = '/>';
                    if (!self::nullish($formData)) {
                        self::pushAdditionalFormFields($target, $formData);
                    }
                }
                self::pushInnerHTML($target, $innerHTML, $children);
                if ($children instanceof JsString) {
                    $target[] = self::escapeText($children->value);
                    return null;
                }
                return $children;
            case 'menuitem':
                $target[] = self::startChunkForTag('menuitem');
                foreach (self::propEntries($props) as [$key, $propValue]) {
                    if ($key === 'children' || $key === 'dangerouslySetInnerHTML') {
                        throw Js::error('menuitems cannot have `children` nor `dangerouslySetInnerHTML`.');
                    }
                    self::pushAttribute($target, $key, $propValue);
                }
                self::pushViewTransitionAttributes($target, $formatContext);
                $target[] = '>';
                return null;
            case 'object':
                $target[] = self::startChunkForTag('object');
                $children = null;
                $innerHTML = null;
                foreach (self::propEntries($props) as [$key, $propValue]) {
                    switch ($key) {
                        case 'children':
                            $children = $propValue;
                            break;
                        case 'dangerouslySetInnerHTML':
                            $innerHTML = $propValue;
                            break;
                        case 'data':
                            $sanitized = self::sanitizeURL(Js::str($propValue));
                            if ($sanitized === '') {
                                break;
                            }
                            $target[] = ' data="' . self::escapeText($sanitized) . '"';
                            break;
                        default:
                            self::pushAttribute($target, $key, $propValue);
                    }
                }
                self::pushViewTransitionAttributes($target, $formatContext);
                $target[] = '>';
                self::pushInnerHTML($target, $innerHTML, $children);
                if ($children instanceof JsString) {
                    $target[] = self::escapeText($children->value);
                    return null;
                }
                return $children;
            case 'title':
                if (
                    $formatContext->insertionMode === self::SVG_MODE
                    || ($formatContext->tagScope & 1) !== 0
                    || !self::nullish(self::prop($props, 'itemProp'))
                ) {
                    return self::pushTitleImpl($target, $props);
                }
                if (($formatContext->tagScope & 4) !== 0) {
                    return null;
                }
                self::pushTitleImpl($renderState->hoistableChunks, $props);
                return JsUndefined::instance();
            case 'link':
                return Resources::pushLink($target, $props, $resumableState, $renderState, $hoistableState, $formatContext, $textEmbedded);
            case 'script':
                return Resources::pushScript($target, $props, $resumableState, $renderState, $formatContext, $textEmbedded);
            case 'style':
                return Resources::pushStyle($target, $props, $resumableState, $renderState, $hoistableState, $formatContext, $textEmbedded);
            case 'meta':
                if (
                    $formatContext->insertionMode === self::SVG_MODE
                    || ($formatContext->tagScope & 1) !== 0
                    || !self::nullish(self::prop($props, 'itemProp'))
                ) {
                    return self::pushSelfClosing($target, $props, 'meta', $formatContext);
                }
                if ($textEmbedded) {
                    $target[] = self::TEXT_SEPARATOR;
                }
                if (($formatContext->tagScope & 4) !== 0) {
                    return null;
                }
                if (self::prop($props, 'charSet') instanceof JsString) {
                    return self::pushSelfClosing($renderState->charsetChunks, $props, 'meta', $formatContext);
                }
                $metaName = self::prop($props, 'name');
                if ($metaName instanceof JsString && $metaName->value === 'viewport') {
                    return self::pushSelfClosing($renderState->viewportChunks, $props, 'meta', $formatContext);
                }
                return self::pushSelfClosing($renderState->hoistableChunks, $props, 'meta', $formatContext);
            case 'listing':
            case 'pre':
                $target[] = self::startChunkForTag($type);
                $children = null;
                $innerHTML = null;
                foreach (self::propEntries($props) as [$key, $propValue]) {
                    switch ($key) {
                        case 'children':
                            $children = $propValue;
                            break;
                        case 'dangerouslySetInnerHTML':
                            $innerHTML = $propValue;
                            break;
                        default:
                            self::pushAttribute($target, $key, $propValue);
                    }
                }
                self::pushViewTransitionAttributes($target, $formatContext);
                $target[] = '>';
                if ($innerHTML !== null) {
                    if ($children !== null) {
                        throw Js::error('Can only set one of `children` or `props.dangerouslySetInnerHTML`.');
                    }
                    if (!$innerHTML instanceof JsObject || $innerHTML instanceof JsFunction || !Js::has($innerHTML, '__html')) {
                        throw Js::error(
                            '`props.dangerouslySetInnerHTML` must be in the form `{__html: ...}`. Please visit '
                            . 'https://react.dev/link/dangerously-set-inner-html for more information.',
                        );
                    }
                    $html = Js::get($innerHTML, '__html');
                    if (!self::nullish($html)) {
                        if ($html instanceof JsString && ($html->value[0] ?? '') === "\n") {
                            $target[] = "\n";
                            $target[] = $html->value;
                        } else {
                            $target[] = Js::str($html);
                        }
                    }
                }
                if ($children instanceof JsString && ($children->value[0] ?? '') === "\n") {
                    $target[] = "\n";
                }
                return $children;
            case 'img':
                Resources::preloadImage($props, $resumableState, $renderState, $hoistableState, $formatContext);
                return self::pushSelfClosing($target, $props, 'img', $formatContext);
            case 'base':
            case 'area':
            case 'br':
            case 'col':
            case 'embed':
            case 'hr':
            case 'keygen':
            case 'param':
            case 'source':
            case 'track':
            case 'wbr':
                return self::pushSelfClosing($target, $props, $type, $formatContext);
            case 'head':
                if ($formatContext->insertionMode < self::HTML_MODE) {
                    $preamble = $preambleState ?? $renderState->preamble;
                    if ($preamble->headChunks !== null) {
                        throw Js::error('The `<head>` tag may only be rendered once.');
                    }
                    if ($preambleState !== null) {
                        $target[] = '<!--head-->';
                    }
                    $preamble->headChunks = [];
                    return self::pushStartSingletonElement($preamble->headChunks, $props, 'head', $formatContext);
                }
                return self::pushStartGenericElement($target, $props, 'head', $formatContext);
            case 'body':
                if ($formatContext->insertionMode < self::HTML_MODE) {
                    $preamble = $preambleState ?? $renderState->preamble;
                    if ($preamble->bodyChunks !== null) {
                        throw Js::error('The `<body>` tag may only be rendered once.');
                    }
                    if ($preambleState !== null) {
                        $target[] = '<!--body-->';
                    }
                    $preamble->bodyChunks = [];
                    return self::pushStartSingletonElement($preamble->bodyChunks, $props, 'body', $formatContext);
                }
                return self::pushStartGenericElement($target, $props, 'body', $formatContext);
            case 'html':
                if ($formatContext->insertionMode === self::ROOT_HTML_MODE) {
                    $preamble = $preambleState ?? $renderState->preamble;
                    if ($preamble->htmlChunks !== null) {
                        throw Js::error('The `<html>` tag may only be rendered once.');
                    }
                    if ($preambleState !== null) {
                        $target[] = '<!--html-->';
                    }
                    // The legacy renderer (renderToString) writes no doctype.
                    $preamble->htmlChunks = [$renderState->legacy ? '' : '<!DOCTYPE html>'];
                    return self::pushStartSingletonElement($preamble->htmlChunks, $props, 'html', $formatContext);
                }
                return self::pushStartGenericElement($target, $props, 'html', $formatContext);
        }
        if (str_contains($type, '-')) {
            return self::pushStartCustomElement($target, $props, $type, $formatContext);
        }
        return self::pushStartGenericElement($target, $props, $type, $formatContext);
    }

    /** @param list<string> $target */
    private static function pushStartCustomElement(array &$target, JsObject $props, string $type, FormatContext $formatContext): ?JsValue
    {
        $target[] = self::startChunkForTag($type);
        $children = null;
        $innerHTML = null;
        foreach (self::propEntries($props) as [$key, $propValue]) {
            $attributeName = $key;
            switch ($key) {
                case 'children':
                    $children = $propValue;
                    continue 2;
                case 'dangerouslySetInnerHTML':
                    $innerHTML = $propValue;
                    continue 2;
                case 'style':
                    self::pushStyleAttribute($target, $propValue);
                    continue 2;
                case 'suppressContentEditableWarning':
                case 'suppressHydrationWarning':
                case 'ref':
                    continue 2;
                case 'className':
                    $attributeName = 'class';
            }
            if (self::isAttributeNameSafe($key) && !self::fnOrSymbol($propValue) && !($propValue instanceof JsBoolean && !$propValue->value)) {
                if ($propValue instanceof JsBoolean) {
                    $propValue = new JsString('');
                } elseif ($propValue instanceof JsObject) {
                    continue;
                }
                $target[] = ' ' . $attributeName . '="' . self::esc($propValue) . '"';
            }
        }
        self::pushViewTransitionAttributes($target, $formatContext);
        $target[] = '>';
        self::pushInnerHTML($target, $innerHTML, $children);
        return $children;
    }

    // Text.

    /** @param list<string> $target */
    public static function pushTextInstance(array &$target, string $text, RenderState $renderState, bool $textEmbedded): bool
    {
        if ($renderState->generateStaticMarkup) {
            $target[] = self::escapeText($text);
            return false;
        }
        if ($text === '') {
            return $textEmbedded;
        }
        if ($textEmbedded) {
            $target[] = self::TEXT_SEPARATOR;
        }
        $target[] = self::escapeText($text);
        return true;
    }

    /** The text separator React pushes when a segment ends in text that is embedded in other text. */
    public static function pushSegmentFinale(Segment $segment, RenderState $renderState): void
    {
        if (!$renderState->generateStaticMarkup && $segment->lastPushedText && $segment->textEmbedded) {
            $segment->chunks[] = self::TEXT_SEPARATOR;
        }
    }

    // View transitions.

    /** @param list<string> $target */
    public static function pushViewTransitionAttributes(array &$target, FormatContext $formatContext): void
    {
        $vt = $formatContext->viewTransition;
        if ($vt === null) {
            return;
        }
        if ($vt->name !== 'auto') {
            self::pushStringAttribute($target, 'vt-name', $vt->nameIdx === 0 ? (string) $vt->name : $vt->name . '_' . $vt->nameIdx);
            $vt->nameIdx++;
        }
        self::pushStringAttribute($target, 'vt-update', (string) $vt->update);
        if ($vt->enter !== 'none') {
            self::pushStringAttribute($target, 'vt-enter', (string) $vt->enter);
        }
        if ($vt->exit !== 'none') {
            self::pushStringAttribute($target, 'vt-exit', (string) $vt->exit);
        }
        if ($vt->share !== 'none') {
            self::pushStringAttribute($target, 'vt-share', (string) $vt->share);
        }
    }

    // Format contexts.

    public static function createRootFormatContext(?string $namespaceURI): FormatContext
    {
        $mode = match ($namespaceURI) {
            'http://www.w3.org/2000/svg' => self::SVG_MODE,
            'http://www.w3.org/1998/Math/MathML' => self::MATHML_MODE,
            default => self::ROOT_HTML_MODE,
        };
        return new FormatContext($mode, null, 0, null);
    }

    public static function getChildFormatContext(FormatContext $parent, string $type, JsObject $props): FormatContext
    {
        $subtreeScope = $parent->tagScope & -25;
        switch ($type) {
            case 'noscript':
                return new FormatContext(self::HTML_MODE, null, $subtreeScope | 1, null);
            case 'select':
                $value = self::prop($props, 'value');
                return new FormatContext(self::HTML_MODE, self::nullish($value) ? self::prop($props, 'defaultValue') : $value, $subtreeScope, null);
            case 'svg':
                return new FormatContext(self::SVG_MODE, null, $subtreeScope, null);
            case 'picture':
                return new FormatContext(self::HTML_MODE, null, $subtreeScope | 2, null);
            case 'math':
                return new FormatContext(self::MATHML_MODE, null, $subtreeScope, null);
            case 'foreignObject':
                return new FormatContext(self::HTML_MODE, null, $subtreeScope, null);
            case 'table':
                return new FormatContext(self::HTML_TABLE_MODE, null, $subtreeScope, null);
            case 'thead':
            case 'tbody':
            case 'tfoot':
                return new FormatContext(self::HTML_TABLE_BODY_MODE, null, $subtreeScope, null);
            case 'colgroup':
                return new FormatContext(self::HTML_COLGROUP_MODE, null, $subtreeScope, null);
            case 'tr':
                return new FormatContext(self::HTML_TABLE_ROW_MODE, null, $subtreeScope, null);
            case 'head':
                if ($parent->insertionMode < self::HTML_MODE) {
                    return new FormatContext(self::HTML_HEAD_MODE, null, $subtreeScope, null);
                }
                break;
            case 'html':
                if ($parent->insertionMode === self::ROOT_HTML_MODE) {
                    return new FormatContext(self::HTML_HTML_MODE, null, $subtreeScope, null);
                }
                break;
        }
        if ($parent->insertionMode >= self::HTML_TABLE_MODE || $parent->insertionMode < self::HTML_MODE) {
            return new FormatContext(self::HTML_MODE, null, $subtreeScope, null);
        }
        if ($parent->viewTransition !== null || $parent->tagScope !== $subtreeScope) {
            return new FormatContext($parent->insertionMode, $parent->selectedValue, $subtreeScope, null);
        }
        return $parent;
    }

    private static function getSuspenseViewTransition(?ViewTransitionState $parent): ?ViewTransitionState
    {
        if ($parent === null) {
            return null;
        }
        return new ViewTransitionState($parent->update, 'none', 'none', $parent->update, 'none', 'none', $parent->autoName, $parent->autoName, 0);
    }

    public static function getSuspenseFallbackFormatContext(ResumableState $resumableState, FormatContext $parent): FormatContext
    {
        if (($parent->tagScope & 32) !== 0) {
            $resumableState->instructions |= 128;
        }
        return new FormatContext(
            $parent->insertionMode,
            $parent->selectedValue,
            $parent->tagScope | 12,
            self::getSuspenseViewTransition($parent->viewTransition),
        );
    }

    public static function getSuspenseContentFormatContext(ResumableState $resumableState, FormatContext $parent): FormatContext
    {
        $vt = self::getSuspenseViewTransition($parent->viewTransition);
        $subtreeScope = $parent->tagScope | 16;
        if ($vt !== null && $vt->share !== 'none') {
            $subtreeScope |= 64;
        }
        return new FormatContext($parent->insertionMode, $parent->selectedValue, $subtreeScope, $vt);
    }

    public static function makeId(ResumableState $resumableState, string $treeId, int $localId): string
    {
        $id = '_' . $resumableState->idPrefix . 'R_' . $treeId;
        if ($localId > 0) {
            $id .= 'H' . base_convert((string) $localId, 10, 32);
        }
        return $id . '_';
    }

    public static function getViewTransitionClassName(JsValue $defaultClass, JsValue $eventClass): ?string
    {
        $resolve = static function (JsValue $class): ?JsValue {
            if (self::nullish($class)) {
                return null;
            }
            return $class instanceof JsString ? $class : Js::get($class, 'default');
        };
        $default = $resolve($defaultClass);
        $event = $resolve($eventClass);
        if (self::nullish($event)) {
            if ($default instanceof JsString && $default->value === 'auto') {
                return null;
            }
            return self::nullish($default) ? null : Js::str($default);
        }
        if ($event instanceof JsString && $event->value === 'auto') {
            return null;
        }
        return Js::str($event);
    }

    // State creation.

    public static function createResumableState(JsValue $options): ResumableState
    {
        $prefix = $options instanceof JsObject ? Js::get($options, 'identifierPrefix') : JsUndefined::instance();
        $bootstrap = static fn (string $name): ?JsValue => $options instanceof JsObject && !Js::get($options, $name) instanceof JsUndefined
            ? Js::get($options, $name)
            : null;
        return new ResumableState(
            $prefix instanceof JsUndefined ? '' : Js::str($prefix),
            $bootstrap('bootstrapScriptContent'),
            $bootstrap('bootstrapScripts'),
            $bootstrap('bootstrapModules'),
        );
    }

    public static function createRenderState(
        ResumableState $resumableState,
        ?JsValue $nonce,
        ?JsValue $importMap,
        bool $generateStaticMarkup = false,
    ): RenderState {
        $renderState = new RenderState($resumableState->idPrefix);
        $renderState->generateStaticMarkup = $generateStaticMarkup;
        $nonceScript = null;
        $nonceStyle = null;
        if ($nonce instanceof JsString) {
            $nonceScript = $nonce->value;
        } elseif ($nonce instanceof JsObject && $nonce->toBoolean()) {
            $script = Js::get($nonce, 'script');
            $style = Js::get($nonce, 'style');
            $nonceScript = $script instanceof JsUndefined ? null : Js::str($script);
            $nonceStyle = $style instanceof JsUndefined ? null : Js::str($style);
        }
        $renderState->nonceScript = $nonceScript;
        $renderState->nonceStyle = $nonceStyle;
        if ($nonceScript !== null) {
            $renderState->startInlineScript = '<script nonce="' . self::escapeText($nonceScript) . '"';
        }
        if ($nonceStyle !== null) {
            $renderState->startInlineStyle = '<style nonce="' . self::escapeText($nonceStyle) . '"';
        }
        $bootstrapChunks = [];
        if ($resumableState->bootstrapScriptContent !== null) {
            $bootstrapChunks[] = $renderState->startInlineScript;
            self::pushCompletedShellIdAttribute($bootstrapChunks, $resumableState);
            $bootstrapChunks[] = '>';
            $bootstrapChunks[] = self::escapeScript(Js::str($resumableState->bootstrapScriptContent));
            $bootstrapChunks[] = '</script>';
        }
        if ($importMap !== null && !$importMap instanceof JsUndefined) {
            $renderState->importMapChunks[] = $nonceScript === null
                ? '<script type="importmap">'
                : '<script type="importmap" nonce="' . self::escapeText($nonceScript) . '">';
            $renderState->importMapChunks[] = self::escapeScript(Js::str(Server::jsonStringify($importMap)));
            $renderState->importMapChunks[] = '</script>';
        }
        foreach ([[$resumableState->bootstrapScripts, false], [$resumableState->bootstrapModules, true]] as [$list, $isModule]) {
            if ($list === null || $list instanceof JsUndefined) {
                continue;
            }
            foreach (Js::elements($list) as $config) {
                $integrity = null;
                $crossOrigin = null;
                $props = $isModule
                    ? [
                        'rel' => new JsString('modulepreload'),
                        'fetchPriority' => new JsString('low'),
                        'nonce' => $nonceScript === null ? JsUndefined::instance() : new JsString($nonceScript),
                    ]
                    : [
                        'rel' => new JsString('preload'),
                        'as' => new JsString('script'),
                        'fetchPriority' => new JsString('low'),
                        'nonce' => $nonce ?? JsUndefined::instance(),
                    ];
                if ($config instanceof JsString) {
                    $src = $config->value;
                    $props['href'] = $config;
                } else {
                    $srcValue = Js::get($config, 'src');
                    $props['href'] = $srcValue;
                    $src = Js::str($srcValue);
                    $integrityValue = Js::get($config, 'integrity');
                    $integrity = $integrityValue instanceof JsString ? $integrityValue->value : null;
                    $props['integrity'] = $integrity === null ? JsUndefined::instance() : new JsString($integrity);
                    $crossOriginValue = Js::get($config, 'crossOrigin');
                    $credentials = $crossOriginValue instanceof JsString && $crossOriginValue->value === 'use-credentials';
                    $crossOrigin = Js::isNullish($crossOriginValue) ? null : ($credentials ? 'use-credentials' : '');
                    $props['crossOrigin'] = $crossOrigin === null ? JsUndefined::instance() : new JsString($crossOrigin);
                }
                $resumableState->scriptResources[$src] = null;
                $resumableState->moduleScriptResources[$src] = null;
                $chunks = new Chunks();
                self::pushLinkImpl($chunks->c, $props);
                $renderState->bootstrapScripts[spl_object_id($chunks)] = $chunks;
                $bootstrapChunks[] = ($isModule ? '<script type="module" src="' : '<script src="') . self::escapeText($src) . '"';
                if ($nonceScript !== null) {
                    $bootstrapChunks[] = ' nonce="' . self::escapeText($nonceScript) . '"';
                }
                if ($integrity !== null) {
                    $bootstrapChunks[] = ' integrity="' . self::escapeText($integrity) . '"';
                }
                if ($crossOrigin !== null) {
                    $bootstrapChunks[] = ' crossorigin="' . self::escapeText($crossOrigin) . '"';
                }
                self::pushCompletedShellIdAttribute($bootstrapChunks, $resumableState);
                $bootstrapChunks[] = ' async=""></script>';
            }
        }
        $renderState->bootstrapChunks = $bootstrapChunks;
        return $renderState;
    }

    public static function hoistPreambleState(RenderState $renderState, PreambleState $preambleState): void
    {
        $preamble = $renderState->preamble;
        if ($preamble->htmlChunks === null && $preambleState->htmlChunks !== null) {
            $preamble->htmlChunks = $preambleState->htmlChunks;
        }
        if ($preamble->headChunks === null && $preambleState->headChunks !== null) {
            $preamble->headChunks = $preambleState->headChunks;
        }
        if ($preamble->bodyChunks === null && $preambleState->bodyChunks !== null) {
            $preamble->bodyChunks = $preambleState->bodyChunks;
        }
    }
}
