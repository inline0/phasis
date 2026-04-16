<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Object\PropertyDescriptor;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsGenerator;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;

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
    private static ?JsObject $generatorPrototype = null;
    private static ?JsObject $generatorFunctionPrototype = null;

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
        self::$generatorPrototype = null;
        self::$generatorFunctionPrototype = null;
    }

    /** %IteratorPrototype%: base iterator prototype with Symbol.iterator = return this. */
    public static function iteratorPrototype(): JsObject
    {
        if (self::$iteratorPrototype === null) {
            self::$iteratorPrototype = new JsObject();
            $iterSym = SymbolConstructor::iterator();
            $selfIterFn = JsFunction::fromCallable(
                '[Symbol.iterator]',
                function (\PhpJs\Value\JsValue $this_): \PhpJs\Value\JsValue {
                    return $this_;
                },
                0,
            );
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

    /**
     * %GeneratorPrototype%: prototype of all generator instances.
     * Inherits from %IteratorPrototype%. Has next/return/throw methods and Symbol.toStringTag.
     */
    public static function generatorPrototype(): JsObject
    {
        if (self::$generatorPrototype === null) {
            self::$generatorPrototype = self::createGeneratorPrototype();
        }
        return self::$generatorPrototype;
    }

    /**
     * %GeneratorFunction.prototype%: the [[Prototype]] shared by all generator functions.
     * Inherits from Function.prototype. Its .prototype is %GeneratorPrototype%.
     */
    public static function generatorFunctionPrototype(): JsObject
    {
        if (self::$generatorFunctionPrototype === null) {
            self::$generatorFunctionPrototype = self::createGeneratorFunctionPrototype();
        }
        return self::$generatorFunctionPrototype;
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

    private static function createGeneratorPrototype(): JsObject
    {
        // %GeneratorPrototype% inherits from %IteratorPrototype%.
        $proto = new JsObject(self::iteratorPrototype());

        // Symbol.toStringTag = "Generator" (non-writable, non-enumerable, configurable).
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('Generator'), false, false, true),
        );

        // next(): resume the generator.
        $nextFn = JsFunction::fromCallable(
            'next',
            function (\PhpJs\Value\JsValue $this_, array $args): \PhpJs\Value\JsValue {
                if (!$this_ instanceof JsGenerator) {
                    throw new TypeError('%GeneratorPrototype%.next called on incompatible receiver');
                }
                $value = $args[0] ?? JsUndefined::instance();
                return $this_->next($value);
            },
            1,
        );
        $proto->defineOwnProperty('next', PropertyDescriptor::data($nextFn, true, false, true));

        // return(): force the generator to return a value.
        $returnFn = JsFunction::fromCallable(
            'return',
            function (\PhpJs\Value\JsValue $this_, array $args): \PhpJs\Value\JsValue {
                if (!$this_ instanceof JsGenerator) {
                    throw new TypeError('%GeneratorPrototype%.return called on incompatible receiver');
                }
                $value = $args[0] ?? JsUndefined::instance();
                return $this_->returnValue($value);
            },
            1,
        );
        $proto->defineOwnProperty('return', PropertyDescriptor::data($returnFn, true, false, true));

        // throw(): throw a value into the generator.
        $throwFn = JsFunction::fromCallable(
            'throw',
            function (\PhpJs\Value\JsValue $this_, array $args): \PhpJs\Value\JsValue {
                if (!$this_ instanceof JsGenerator) {
                    throw new TypeError('%GeneratorPrototype%.throw called on incompatible receiver');
                }
                $value = $args[0] ?? JsUndefined::instance();
                return $this_->throwValue($value);
            },
            1,
        );
        $proto->defineOwnProperty('throw', PropertyDescriptor::data($throwFn, true, false, true));

        return $proto;
    }

    private static function createGeneratorFunctionPrototype(): JsObject
    {
        // %GeneratorFunction.prototype% inherits from Function.prototype.
        // Function.prototype may not be set yet; use a JsObject with lazy proto wiring.
        $fnProto = JsFunction::getFunctionPrototype();
        $proto = new JsObject($fnProto);

        // Symbol.toStringTag = "GeneratorFunction" (non-writable, non-enumerable, configurable).
        $toStringTagSym = SymbolConstructor::toStringTag();
        $proto->definePropertyBySymbol(
            $toStringTagSym,
            PropertyDescriptor::data(new JsString('GeneratorFunction'), false, false, true),
        );

        // .prototype points to %GeneratorPrototype%.
        $proto->defineOwnProperty(
            'prototype',
            PropertyDescriptor::data(self::generatorPrototype(), false, false, false),
        );

        return $proto;
    }
}
