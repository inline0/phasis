<?php

declare(strict_types=1);

namespace Phasis\Value;

use Phasis\BuiltIn\SymbolConstructor;

/**
 * A React 19 element created by the native JSX runtimes (`jsx`, `jsxs`,
 * `createElement`, `cloneElement`).
 *
 * To JavaScript it is an ordinary object with React's own properties, in
 * React's order: `$$typeof` (Symbol.for('react.transitional.element')),
 * `type`, `key`, `ref` and `props`. The class only marks the object as one
 * the runtime made, which gives the renderers a fast path; objects built
 * elsewhere that carry the element symbol render the same way.
 */
final class JsElement extends JsObject
{
    private static ?JsSymbol $elementSymbol = null;

    public function __construct(JsValue $type, JsValue $key, JsValue $ref, JsObject $props)
    {
        parent::__construct();
        $slots = $this->properties;
        $slots->dataSlots = [
            '$$typeof' => self::elementSymbol(),
            'type' => $type,
            'key' => $key,
            'ref' => $ref,
            'props' => $props,
        ];
        $slots->order = ['$$typeof' => true, 'type' => true, 'key' => true, 'ref' => true, 'props' => true];
    }

    public static function elementSymbol(): JsSymbol
    {
        return self::$elementSymbol ??= SymbolConstructor::registered('react.transitional.element');
    }
}
