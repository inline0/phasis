<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Exceptions\TypeError;
use PhpJs\Runtime\Environment;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsString;
use PhpJs\Value\JsSymbol;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

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

    public static function install(Environment $env): void
    {
        // Symbol(description) is callable but NOT a constructor.
        $symbolFn = JsFunction::fromCallable('Symbol', function (JsValue $this_, array $args): JsValue {
            $description = null;
            if (!empty($args) && !$args[0] instanceof JsUndefined) {
                $description = TypeConversion::toString($args[0]);
            }
            return new JsSymbol($description);
        });

        // Symbol.for(key): returns shared symbol from global registry.
        $symbolFn->set('for', JsFunction::fromCallable('for', self::symbolFor()));

        // Symbol.keyFor(sym): returns registry key for a registered symbol.
        $symbolFn->set('keyFor', JsFunction::fromCallable('keyFor', self::symbolKeyFor()));

        // Well-known symbols as static properties.
        // Well-known symbols are non-writable, non-enumerable, non-configurable per spec
        $wks = static fn (string $name, JsSymbol $sym) => $symbolFn->defineOwnProperty(
            $name,
            \PhpJs\Object\PropertyDescriptor::data($sym, false, false, false),
        );
        $wks('iterator', self::iterator());
        $wks('hasInstance', self::hasInstance());
        $wks('toPrimitive', self::toPrimitive());
        $wks('toStringTag', self::toStringTag());
        $wks('split', self::split());
        $wks('search', self::search());
        $wks('match', self::match());
        $wks('replace', self::replace());
        $wks('species', self::species());
        $wks('isConcatSpreadable', self::isConcatSpreadable());

        // Symbol.prototype
        $proto = new \PhpJs\Value\JsObject();
        $proto->defineOwnProperty('constructor', \PhpJs\Object\PropertyDescriptor::data($symbolFn, true, false, true));
        $proto->defineOwnProperty('toString', \PhpJs\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('toString', function (JsValue $this_): JsValue {
                if ($this_ instanceof JsSymbol) {
                    return new JsString($this_->toString());
                }
                throw new TypeError('Symbol.prototype.toString requires a Symbol');
            }, 0),
            true,
            false,
            true,
        ));
        $proto->defineOwnProperty('valueOf', \PhpJs\Object\PropertyDescriptor::data(
            JsFunction::fromCallable('valueOf', function (JsValue $this_): JsValue {
                if ($this_ instanceof JsSymbol) {
                    return $this_;
                }
                throw new TypeError('Symbol.prototype.valueOf requires a Symbol');
            }, 0),
            true,
            false,
            true,
        ));
        $proto->defineOwnProperty('description', \PhpJs\Object\PropertyDescriptor::accessor(
            JsFunction::fromCallable('get description', function (JsValue $this_): JsValue {
                if ($this_ instanceof JsSymbol) {
                    $desc = $this_->getDescription();
                    return $desc !== null ? new JsString($desc) : JsUndefined::instance();
                }
                throw new TypeError('Symbol.prototype.description requires a Symbol');
            }, 0),
            null,
            false,
            true,
        ));
        // Set Symbol.toStringTag on the prototype (non-writable, non-enumerable, configurable)
        $proto->definePropertyBySymbol(
            self::toStringTag(),
            \PhpJs\Object\PropertyDescriptor::data(new JsString('Symbol'), false, false, true),
        );
        $symbolFn->set('prototype', $proto);

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
