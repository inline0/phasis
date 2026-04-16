<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Object\PropertyDescriptor;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;

/**
 * Singleton iterator prototype objects per the ECMAScript specification.
 *
 * Prototype chain: iterator → %XxxIteratorPrototype% → %IteratorPrototype% → %ObjectPrototype%
 *
 * %IteratorPrototype% has Symbol.iterator returning `this` (making all iterators self-iterable).
 * Each sub-prototype adds Symbol.toStringTag with the correct string value.
 */
class IteratorPrototypes
{
    private static ?JsObject $iteratorPrototype = null;
    private static ?JsObject $arrayIteratorPrototype = null;
    private static ?JsObject $mapIteratorPrototype = null;
    private static ?JsObject $setIteratorPrototype = null;
    private static ?JsObject $stringIteratorPrototype = null;
    private static ?JsObject $regExpStringIteratorPrototype = null;

    /**
     * Reset all cached prototypes. Must be called at the start of each Engine
     * initialization so that test262 tests running in fresh realms cannot
     * corrupt shared state (e.g. via configurable-property deletion checks).
     */
    public static function reset(): void
    {
        self::$iteratorPrototype = null;
        self::$arrayIteratorPrototype = null;
        self::$mapIteratorPrototype = null;
        self::$setIteratorPrototype = null;
        self::$stringIteratorPrototype = null;
        self::$regExpStringIteratorPrototype = null;
    }

    /** %IteratorPrototype%: base iterator prototype with Symbol.iterator = return this. */
    public static function iteratorPrototype(): JsObject
    {
        if (self::$iteratorPrototype === null) {
            self::$iteratorPrototype = new JsObject();
            $iterSym = SymbolConstructor::iterator();
            $selfIterFn = JsFunction::fromCallable('[Symbol.iterator]', function (\PhpJs\Value\JsValue $this_): \PhpJs\Value\JsValue {
                return $this_;
            }, 0);
            self::$iteratorPrototype->definePropertyBySymbol(
                $iterSym,
                PropertyDescriptor::data($selfIterFn, true, false, true),
            );
        }
        return self::$iteratorPrototype;
    }

    /** %ArrayIteratorPrototype%: inherits from %IteratorPrototype%, tag = "Array Iterator". */
    public static function arrayIteratorPrototype(): JsObject
    {
        if (self::$arrayIteratorPrototype === null) {
            self::$arrayIteratorPrototype = self::createTaggedPrototype('Array Iterator');
        }
        return self::$arrayIteratorPrototype;
    }

    /** %MapIteratorPrototype%: inherits from %IteratorPrototype%, tag = "Map Iterator". */
    public static function mapIteratorPrototype(): JsObject
    {
        if (self::$mapIteratorPrototype === null) {
            self::$mapIteratorPrototype = self::createTaggedPrototype('Map Iterator');
        }
        return self::$mapIteratorPrototype;
    }

    /** %SetIteratorPrototype%: inherits from %IteratorPrototype%, tag = "Set Iterator". */
    public static function setIteratorPrototype(): JsObject
    {
        if (self::$setIteratorPrototype === null) {
            self::$setIteratorPrototype = self::createTaggedPrototype('Set Iterator');
        }
        return self::$setIteratorPrototype;
    }

    /** %StringIteratorPrototype%: inherits from %IteratorPrototype%, tag = "String Iterator". */
    public static function stringIteratorPrototype(): JsObject
    {
        if (self::$stringIteratorPrototype === null) {
            self::$stringIteratorPrototype = self::createTaggedPrototype('String Iterator');
        }
        return self::$stringIteratorPrototype;
    }

    /** %RegExpStringIteratorPrototype%: inherits from %IteratorPrototype%, tag = "RegExp String Iterator". */
    public static function regExpStringIteratorPrototype(): JsObject
    {
        if (self::$regExpStringIteratorPrototype === null) {
            self::$regExpStringIteratorPrototype = self::createTaggedPrototype('RegExp String Iterator');
        }
        return self::$regExpStringIteratorPrototype;
    }

    /** Create a sub-prototype that inherits from %IteratorPrototype% with a Symbol.toStringTag. */
    private static function createTaggedPrototype(string $tag): JsObject
    {
        $proto = new JsObject(self::iteratorPrototype());
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString($tag), false, false, true),
        );
        return $proto;
    }
}
