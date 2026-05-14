<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

/**
 * Symbol constructor and well-known symbols.
 *
 * Symbol is callable but not a constructor: new Symbol() throws TypeError.
 * Symbol(description) creates a unique symbol with an optional description.
 */
class SymbolConstructor
{
    /** @var array<string, JsSymbol> Global symbol registry for Symbol.for / Symbol.keyFor. */
    private static array $registry = [];

    /** Symbol.prototype, stored for access by TypeConversion::toObject. */
    private static ?JsObject $proto = null;

    /** Return Symbol.prototype (null before install() runs). */
    public static function getProto(): ?JsObject
    {
        return self::$proto;
    }

    /** Well-known symbols, created once and reused. */
    private static ?JsSymbol $iterator = null;
    private static ?JsSymbol $hasInstance = null;
    private static ?JsSymbol $toPrimitive = null;
    private static ?JsSymbol $toStringTag = null;
    private static ?JsSymbol $split = null;
    private static ?JsSymbol $search = null;
    private static ?JsSymbol $match = null;
    private static ?JsSymbol $replace = null;
    private static ?JsSymbol $species = null;
    private static ?JsSymbol $isConcatSpreadable = null;
    private static ?JsSymbol $unscopables = null;
    private static ?JsSymbol $matchAll = null;
    private static ?JsSymbol $asyncIterator = null;
    private static ?JsSymbol $dispose = null;
    private static ?JsSymbol $asyncDispose = null;

    public static function iterator(): JsSymbol
    {
        return self::$iterator ??= new JsSymbol('Symbol.iterator');
    }

    public static function hasInstance(): JsSymbol
    {
        return self::$hasInstance ??= new JsSymbol('Symbol.hasInstance');
    }

    public static function toPrimitive(): JsSymbol
    {
        return self::$toPrimitive ??= new JsSymbol('Symbol.toPrimitive');
    }

    public static function toStringTag(): JsSymbol
    {
        return self::$toStringTag ??= new JsSymbol('Symbol.toStringTag');
    }

    public static function split(): JsSymbol
    {
        return self::$split ??= new JsSymbol('Symbol.split');
    }

    public static function search(): JsSymbol
    {
        return self::$search ??= new JsSymbol('Symbol.search');
    }

    public static function match(): JsSymbol
    {
        return self::$match ??= new JsSymbol('Symbol.match');
    }

    public static function replace(): JsSymbol
    {
        return self::$replace ??= new JsSymbol('Symbol.replace');
    }

    public static function species(): JsSymbol
    {
        return self::$species ??= new JsSymbol('Symbol.species');
    }

    public static function isConcatSpreadable(): JsSymbol
    {
        return self::$isConcatSpreadable ??= new JsSymbol('Symbol.isConcatSpreadable');
    }

    public static function unscopables(): JsSymbol
    {
        return self::$unscopables ??= new JsSymbol('Symbol.unscopables');
    }

    public static function matchAll(): JsSymbol
    {
        return self::$matchAll ??= new JsSymbol('Symbol.matchAll');
    }

    public static function asyncIterator(): JsSymbol
    {
        return self::$asyncIterator ??= new JsSymbol('Symbol.asyncIterator');
    }

    public static function dispose(): JsSymbol
    {
        return self::$dispose ??= new JsSymbol('Symbol.dispose');
    }

    public static function asyncDispose(): JsSymbol
    {
        return self::$asyncDispose ??= new JsSymbol('Symbol.asyncDispose');
    }

    /**
     * Extract the underlying JsSymbol from either a primitive or a Symbol wrapper object.
     * Throws TypeError if $this_ is neither.
     */
    public static function extractSymbol(JsValue $this_, string $methodName): JsSymbol
    {
        if ($this_ instanceof JsSymbol) {
            return $this_;
        }

        // Spec thisSymbolValue checks the [[SymbolData]] internal slot
        // directly on the value, not via property lookup. A Proxy that
        // *targets* a Symbol wrapper does not itself have [[SymbolData]],
        // so it must throw — its has/get traps cannot satisfy the slot
        // requirement.
        if ($this_ instanceof \Phasis\Value\JsProxy) {
            throw new TypeError("Symbol.prototype.{$methodName} called on non-symbol value");
        }

        // Handle Object(Symbol(...)) wrappers: [[PrimitiveValue]] holds the JsSymbol.
        if ($this_ instanceof JsObject && $this_->has('[[PrimitiveValue]]')) {
            $prim = $this_->get('[[PrimitiveValue]]');
            if ($prim instanceof JsSymbol) {
                return $prim;
            }
        }

        throw new TypeError("Symbol.prototype.{$methodName} called on non-symbol value");
    }

    public static function install(Environment $env): void
    {
        // Symbol is callable and has [[Construct]] (isConstructor returns true),
        // but invoking it via new throws TypeError per spec 20.4.1.1.
        $symbolFn = JsFunction::fromCallable('Symbol', function (JsValue $this_, array $args): JsValue {
            // When called via new, $this_ is the newly created object with [[NewTarget]].
            if ($this_ instanceof JsObject && $this_->has('[[NewTarget]]')) {
                throw new TypeError('Symbol is not a constructor');
            }
            $description = null;
            if (!empty($args) && !$args[0] instanceof JsUndefined) {
                $description = TypeConversion::toString($args[0]);
            }
            return new JsSymbol($description);
        });
        $symbolFn->setConstructable();

        // Symbol.length = 0 (per spec)
        $symbolFn->defineOwnProperty('length', PropertyDescriptor::data(
            new \Phasis\Value\JsNumber(0.0),
            false,
            false,
            true,
        ));

        // Symbol.name = "Symbol"
        $symbolFn->defineOwnProperty('name', PropertyDescriptor::data(
            new JsString('Symbol'),
            false,
            false,
            true,
        ));

        // Symbol.for(key): returns shared symbol from global registry.
        // Per spec: writable, non-enumerable, configurable (standard built-in property)
        $symbolFn->defineOwnProperty('for', PropertyDescriptor::data(
            JsFunction::fromCallable('for', self::symbolFor(), 1),
            true,
            false,
            true,
        ));

        // Symbol.keyFor(sym): returns registry key for a registered symbol.
        $symbolFn->defineOwnProperty('keyFor', PropertyDescriptor::data(
            JsFunction::fromCallable('keyFor', self::symbolKeyFor(), 1),
            true,
            false,
            true,
        ));

        // Well-known symbols as static properties.
        // Per spec: { [[Writable]]: false, [[Enumerable]]: false, [[Configurable]]: false }
        $wks = static function (string $name, JsSymbol $sym) use ($symbolFn): void {
            $symbolFn->defineOwnProperty(
                $name,
                PropertyDescriptor::data($sym, false, false, false),
            );
        };

        $wks('asyncIterator', self::asyncIterator());
        $wks('hasInstance', self::hasInstance());
        $wks('isConcatSpreadable', self::isConcatSpreadable());
        $wks('iterator', self::iterator());
        $wks('match', self::match());
        $wks('matchAll', self::matchAll());
        $wks('replace', self::replace());
        $wks('search', self::search());
        $wks('species', self::species());
        $wks('split', self::split());
        $wks('toPrimitive', self::toPrimitive());
        $wks('toStringTag', self::toStringTag());
        $wks('unscopables', self::unscopables());
        $wks('dispose', self::dispose());
        $wks('asyncDispose', self::asyncDispose());

        // Symbol.prototype
        $proto = new JsObject();

        // Per spec Symbol.prototype.[[Prototype]] is Object.prototype.
        // The interpreter's evalMemberExpression falls back to the prototype chain,
        // so we don't need to link it here; prototype methods are looked up directly.

        $proto->defineOwnProperty('constructor', PropertyDescriptor::data($symbolFn, true, false, true));

        $proto->defineOwnProperty('toString', PropertyDescriptor::data(
            JsFunction::fromCallable('toString', function (JsValue $this_): JsValue {
                $sym = self::extractSymbol($this_, 'toString');
                return new JsString($sym->toString());
            }, 0),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('valueOf', PropertyDescriptor::data(
            JsFunction::fromCallable('valueOf', function (JsValue $this_): JsValue {
                // Return the JsSymbol primitive regardless of whether $this_ is boxed or not.
                return self::extractSymbol($this_, 'valueOf');
            }, 0),
            true,
            false,
            true,
        ));

        $proto->defineOwnProperty('description', PropertyDescriptor::accessor(
            JsFunction::fromCallable('get description', function (JsValue $this_): JsValue {
                $sym = self::extractSymbol($this_, 'description');
                $desc = $sym->getDescription();
                return $desc !== null ? new JsString($desc) : JsUndefined::instance();
            }, 0),
            null,
            false,
            true,
        ));

        // Symbol.prototype[Symbol.toPrimitive]: per spec non-enumerable, non-writable, configurable
        $proto->definePropertyBySymbol(
            self::toPrimitive(),
            PropertyDescriptor::data(
                JsFunction::fromCallable('[Symbol.toPrimitive]', function (JsValue $this_): JsValue {
                    return self::extractSymbol($this_, '[Symbol.toPrimitive]');
                }, 1),
                false,
                false,
                true,
            ),
        );

        // Symbol.prototype[Symbol.toStringTag]: "Symbol" — non-writable, non-enumerable, configurable
        $proto->definePropertyBySymbol(
            self::toStringTag(),
            PropertyDescriptor::data(new JsString('Symbol'), false, false, true),
        );

        // Symbol.prototype is non-writable, non-enumerable, non-configurable on Symbol
        $symbolFn->defineOwnProperty('prototype', PropertyDescriptor::data($proto, false, false, false));

        // Store prototype for TypeConversion::toObject and JsSymbol property lookup.
        self::$proto = $proto;
        JsSymbol::setSymbolPrototype($proto);

        $env->defineVar('Symbol', $symbolFn);
        // Store prototype for auto-boxing symbol property access
        $env->defineVar('__SymbolPrototype__', $proto);
    }

    private static function symbolFor(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $key = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';

            if (isset(self::$registry[$key])) {
                return self::$registry[$key];
            }

            $symbol = new JsSymbol($key);
            self::$registry[$key] = $symbol;
            return $symbol;
        };
    }

    /**
     * Check whether a symbol was created via Symbol.for() (i.e. is in the global registry).
     * Per spec, registered symbols cannot be held weakly.
     */
    public static function isRegisteredSymbol(JsSymbol $sym): bool
    {
        foreach (self::$registry as $registered) {
            if ($registered === $sym) {
                return true;
            }
        }
        return false;
    }

    private static function symbolKeyFor(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $sym = $args[0] ?? JsUndefined::instance();

            if (!$sym instanceof JsSymbol) {
                throw new TypeError('Symbol.keyFor requires a symbol argument');
            }

            foreach (self::$registry as $key => $registered) {
                if ($registered === $sym) {
                    return new JsString($key);
                }
            }

            return JsUndefined::instance();
        };
    }
}
