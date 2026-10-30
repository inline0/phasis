<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Jsx\Precompile;

use Phasis\BuiltIn\Jsx\Js;
use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Value\JsArray;
use Phasis\Value\JsElement;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Runtime side of precompiled JSX (the internal `phasis/jsx-precompile`
 * module): jsxTemplate() wraps a template and its holes in an element the
 * renderers expand, jsxAttr() marks a dynamic attribute hole.
 *
 * A template is `[part, ..., part, meta]`: the static strings and a trailing
 * `{p, f, s, a, h, v}` object with the profile it was compiled for, per-part text
 * flags, per-hole tree steps, per-hole attribute names, and optional HTML
 * option edits and their version (see
 * JsxPrecompiler). Templates compiled before `a` existed pass attribute
 * holes as jsxAttr() markers instead; parse caches can still hold them.
 */
final class Template
{
    public const STARTS_WITH_TEXT = 1;
    public const ENDS_WITH_TEXT = 2;

    private static ?JsSymbol $templateSymbol = null;
    private static ?JsSymbol $attributeSymbol = null;

    public static function templateSymbol(): JsSymbol
    {
        return self::$templateSymbol ??= SymbolConstructor::registered('phasis.template');
    }

    public static function attributeSymbol(): JsSymbol
    {
        return self::$attributeSymbol ??= SymbolConstructor::registered('phasis.attr');
    }

    /** @return array<string, JsValue> */
    public static function moduleExports(): array
    {
        return [
            'jsxTemplate' => Js::fn('jsxTemplate', static function (JsValue $this_, array $args): JsValue {
                $tpl = $args[0] ?? JsUndefined::instance();
                $props = new JsObject();
                $props->properties->setDataSlot('tpl', $tpl);
                $props->properties->setDataSlot('exprs', new JsArray(array_slice($args, 1)));
                return new JsElement(self::templateSymbol(), JsNull::instance(), JsNull::instance(), $props);
            }, 1),
            'jsxAttr' => Js::fn('jsxAttr', static function (JsValue $this_, array $args): JsValue {
                $marker = new JsObject();
                $marker->properties->setDataSlot('$$typeof', self::attributeSymbol());
                $marker->properties->setDataSlot('name', $args[0] ?? JsUndefined::instance());
                $marker->properties->setDataSlot('value', $args[1] ?? JsUndefined::instance());
                $marker->properties->setDataSlot('tag', $args[2] ?? JsNull::instance());
                return $marker;
            }, 2),
        ];
    }

    public static function isAttribute(JsValue $value): bool
    {
        return $value instanceof JsObject && ($value->properties->dataSlots['$$typeof'] ?? null) === self::attributeSymbol();
    }

    /**
     * @return array{
     *     profile: string,
     *     parts: list<string>,
     *     flags: list<int>,
     *     steps: list<?list<string|int>>,
     *     attributes: list<?array{0: string, 1: bool}>,
     *     htmlEdits: ?list<list<array{int, int, string, string, string, string, string}>>,
     *     attributeGreaterEdits: bool,
     * }
     */
    public static function parse(JsValue $tpl): array
    {
        if (!$tpl instanceof JsObject) {
            throw Js::error('jsxTemplate() expects a precompiled template');
        }
        $cached = $tpl->getInternalProperty('[[PhasisTemplate]]');
        if (is_array($cached)) {
            /** Parsed below and stored on the template object. */
            return $cached;
        }
        $entries = Js::elements($tpl);
        $meta = array_pop($entries);
        $parts = [];
        foreach ($entries as $entry) {
            $parts[] = $entry instanceof JsString ? $entry->value : Js::str($entry);
        }
        $flags = [];
        foreach (Js::elements(Js::get($meta ?? JsUndefined::instance(), 'f')) as $flag) {
            $flags[] = $flag instanceof JsNumber ? (int) $flag->value : 0;
        }
        $steps = [];
        foreach (Js::elements(Js::get($meta ?? JsUndefined::instance(), 's')) as $holeSteps) {
            if (Js::isNullish($holeSteps)) {
                $steps[] = null;
                continue;
            }
            $list = [];
            foreach (Js::elements($holeSteps) as $step) {
                $list[] = $step instanceof JsString ? $step->value : (int) ($step instanceof JsNumber ? $step->value : 0);
            }
            $steps[] = $list;
        }
        // Per attribute hole: its name, and whether it is an `a` element's href.
        $attributes = [];
        foreach (Js::elements(Js::get($meta ?? JsUndefined::instance(), 'a')) as $attribute) {
            $entry = Js::isNullish($attribute) ? [] : Js::elements($attribute);
            $attributes[] = $entry === [] ? null : [Js::str($entry[0]), isset($entry[1])];
        }
        $htmlEditsValue = Js::get($meta ?? JsUndefined::instance(), 'h');
        $htmlEdits = null;
        if (!Js::isNullish($htmlEditsValue)) {
            $htmlEdits = [];
            foreach (Js::elements($htmlEditsValue) as $partEdits) {
                $edits = [];
                foreach (Js::elements($partEdits) as $edit) {
                    $values = Js::elements($edit);
                    $edits[] = [
                        (int) Js::str($values[0]), (int) Js::str($values[1]), Js::str($values[2]), Js::str($values[3]),
                        Js::str($values[4]), Js::str($values[5]), Js::str($values[6]),
                    ];
                }
                $htmlEdits[] = $edits;
            }
        }
        $parsed = [
            'profile' => Js::str(Js::get($meta ?? JsUndefined::instance(), 'p')),
            'parts' => $parts,
            'flags' => $flags,
            'steps' => $steps,
            'attributes' => $attributes,
            'htmlEdits' => $htmlEdits,
            'attributeGreaterEdits' => Js::str(Js::get($meta ?? JsUndefined::instance(), 'v')) === '1',
        ];
        $tpl->setInternalProperty('[[PhasisTemplate]]', $parsed);
        return $parsed;
    }
}
