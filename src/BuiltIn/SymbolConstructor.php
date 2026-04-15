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
        $symbolFn->set('iterator', self::iterator());
        $symbolFn->set('hasInstance', self::hasInstance());
        $symbolFn->set('toPrimitive', self::toPrimitive());
        $symbolFn->set('toStringTag', self::toStringTag());

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
        // Set Symbol.toStringTag on the prototype
        $proto->setBySymbol(self::toStringTag(), new JsString('Symbol'));
        $symbolFn->set('prototype', $proto);

        $env->defineVar('Symbol', $symbolFn);
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
