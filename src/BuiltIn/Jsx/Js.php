<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Jsx;

use Phasis\BuiltIn\SymbolConstructor;
use Phasis\Engine;
use Phasis\Exceptions\InternalError;
use Phasis\Exceptions\JsThrowable;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Spec\AbstractOperations;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
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
 * JavaScript semantics used by the JSX runtimes, written as small static
 * helpers so the ported React code reads close to its JavaScript source:
 * property reads, calls, typeof, ToString, for-in keys, and JS errors.
 */
final class Js
{
    /** @var array<int, array{0: int, 1: bool}> Prototype id => [mutationVersion, has enumerable keys]. */
    private static array $protoEnumerable = [];

    public static function undef(): JsUndefined
    {
        return JsUndefined::instance();
    }

    public static function null(): JsNull
    {
        return JsNull::instance();
    }

    /** `value == null`: null or undefined. */
    public static function isNullish(JsValue $value): bool
    {
        return $value instanceof JsUndefined || $value instanceof JsNull;
    }

    public static function typeof(JsValue $value): string
    {
        return $value->typeof();
    }

    public static function isObject(JsValue $value): bool
    {
        return $value instanceof JsObject && !$value instanceof JsFunction;
    }

    public static function isFunction(JsValue $value): bool
    {
        return $value instanceof JsFunction;
    }

    /** `value[key]`, including reads on primitives. */
    public static function get(JsValue $value, string $key): JsValue
    {
        if ($value instanceof JsObject) {
            return $value->properties->dataSlots[$key] ?? $value->get($key);
        }
        if ($value instanceof JsUndefined || $value instanceof JsNull) {
            throw new TypeError("Cannot read properties of {$value->display()} (reading '{$key}')");
        }
        return TypeConversion::toObject($value)->getWithValueReceiver($key, $value);
    }

    /** `value[symbol]`. */
    public static function getSymbol(JsValue $value, JsSymbol $symbol): JsValue
    {
        if ($value instanceof JsObject) {
            return $value->getBySymbol($symbol);
        }
        if ($value instanceof JsUndefined || $value instanceof JsNull) {
            throw new TypeError("Cannot read properties of {$value->display()} (reading '{$symbol->display()}')");
        }
        return TypeConversion::toObject($value)->getBySymbolWithReceiver($symbol, $value);
    }

    public static function set(JsObject $object, string $key, JsValue $value): void
    {
        $object->set($key, $value, true);
    }

    /**
     * Call a JavaScript function. Throws TypeError, as JS does, when the
     * value is not callable.
     *
     * @param list<JsValue> $args
     */
    public static function call(JsValue $fn, JsValue $thisValue, array $args = []): JsValue
    {
        if (!$fn instanceof JsFunction) {
            throw new TypeError(self::describe($fn) . ' is not a function');
        }
        return $fn->call($thisValue, $args);
    }

    /**
     * `new Ctor(...args)`.
     *
     * @param list<JsValue> $args
     */
    public static function construct(JsValue $ctor, array $args = []): JsValue
    {
        if (!$ctor instanceof JsFunction || !$ctor->isConstructable()) {
            throw new TypeError(self::describe($ctor) . ' is not a constructor');
        }
        // Reflect.construct runs the realm's full [[Construct]] (classes,
        // derived constructors, field initializers), like `new` in JS.
        $interpreter = Engine::getCurrentInterpreter();
        $reflect = $interpreter?->getGlobalEnv()->get('Reflect');
        $construct = $reflect instanceof JsObject ? $reflect->get('construct') : null;
        if (!$construct instanceof JsFunction) {
            return $ctor->construct($args);
        }
        return $construct->call(JsUndefined::instance(), [$ctor, new JsArray($args)]);
    }

    /** JS ToString. */
    public static function str(JsValue $value): string
    {
        if ($value instanceof JsString) {
            return $value->value;
        }
        return TypeConversion::toString($value);
    }

    public static function string(string $value): JsString
    {
        return new JsString($value);
    }

    public static function number(float|int $value): JsNumber
    {
        return JsNumber::of((float) $value);
    }

    public static function bool(bool $value): JsBoolean
    {
        return JsBoolean::of($value);
    }

    /** JS ToBoolean. */
    public static function truthy(JsValue $value): bool
    {
        return $value->toBoolean();
    }

    /** `x === y`. */
    public static function same(JsValue $x, JsValue $y): bool
    {
        if ($x === $y) {
            return !($x instanceof JsNumber && is_nan($x->value));
        }
        return AbstractOperations::strictEquals($x, $y);
    }

    /** `Object.is(x, y)`. */
    public static function is(JsValue $x, JsValue $y): bool
    {
        return AbstractOperations::sameValue($x, $y);
    }

    public static function isArray(JsValue $value): bool
    {
        if ($value instanceof JsArray) {
            return true;
        }
        if ($value instanceof \Phasis\Value\JsProxy) {
            return $value->isRevoked() || self::isArray($value->getTarget());
        }
        return false;
    }

    /**
     * Elements of a JS array, with holes as undefined.
     *
     * @return list<JsValue>
     */
    public static function elements(JsValue $array): array
    {
        if ($array instanceof JsArray && $array->isDenseMode()) {
            $out = [];
            $undefined = JsUndefined::instance();
            foreach ($array->getDenseElements() as $element) {
                $out[] = $element ?? $undefined;
            }
            $length = $array->getLength();
            for ($i = count($out); $i < $length; $i++) {
                $out[] = $undefined;
            }
            return $out;
        }
        if ($array instanceof JsObject) {
            $length = (int) TypeConversion::toLength($array->get('length'));
            $out = [];
            for ($i = 0; $i < $length; $i++) {
                $out[] = $array->get((string) $i);
            }
            return $out;
        }
        return [];
    }

    /** @param list<JsValue> $values */
    public static function array(array $values = []): JsArray
    {
        return new JsArray($values);
    }

    /** @param array<string, JsValue> $properties */
    public static function object(array $properties = []): JsObject
    {
        $object = new JsObject();
        foreach ($properties as $key => $value) {
            $object->properties->setDataSlot((string) $key, $value);
        }
        return $object;
    }

    /**
     * Keys a `for (key in object)` loop visits: own and inherited enumerable
     * string keys. Plain objects take a fast path.
     *
     * @return list<string>
     */
    public static function forInKeys(JsObject $object): array
    {
        $own = self::ownEnumerableKeys($object);
        $proto = $object->prototype;
        while ($proto !== null) {
            if ($proto instanceof \Phasis\Value\JsProxy || self::hasEnumerableKeys($proto)) {
                return $object->getEnumerableKeys();
            }
            $proto = $proto->prototype;
        }
        return $own;
    }

    /**
     * `Object.keys(object)`.
     *
     * @return list<string>
     */
    public static function ownEnumerableKeys(JsObject $object): array
    {
        $properties = $object->properties;
        if ($properties->descriptors === [] && get_class($object) === JsObject::class) {
            $keys = [];
            foreach ($properties->dataSlots as $key => $_) {
                if (is_int($key) || ($key[0] === '[' && str_starts_with($key, '[['))) {
                    return $object->getOwnEnumerableKeys();
                }
                $keys[] = $key;
            }
            return $keys;
        }
        return $object->getOwnEnumerableKeys();
    }

    private static function hasEnumerableKeys(JsObject $proto): bool
    {
        $id = spl_object_id($proto);
        $version = $proto->properties->mutationVersion;
        $cached = self::$protoEnumerable[$id] ?? null;
        if ($cached !== null && $cached[0] === $version) {
            return $cached[1];
        }
        $has = $proto->properties->dataSlots !== [];
        if (!$has) {
            foreach ($proto->properties->descriptors as $desc) {
                if ($desc->enumerable === true) {
                    $has = true;
                    break;
                }
            }
        }
        self::$protoEnumerable[$id] = [$version, $has];
        return $has;
    }

    public static function hasOwn(JsObject $object, string $key): bool
    {
        return isset($object->properties->dataSlots[$key]) || $object->hasOwnProperty($key);
    }

    /** The `in` operator for string keys. */
    public static function has(JsObject $object, string $key): bool
    {
        return isset($object->properties->dataSlots[$key]) || $object->has($key);
    }

    public static function symbolFor(string $key): JsSymbol
    {
        return SymbolConstructor::registered($key);
    }

    /** A JS `Error` (or subclass) object, created through the realm's constructor. */
    public static function makeError(string $message, string $type = 'Error', ?JsValue $cause = null): JsObject
    {
        $interpreter = Engine::getCurrentInterpreter();
        $ctor = null;
        if ($interpreter !== null) {
            try {
                $ctor = $interpreter->getGlobalEnv()->get($type);
            } catch (\Throwable) {
                $ctor = null;
            }
        }
        if ($ctor instanceof JsFunction) {
            $args = [new JsString($message)];
            if ($cause !== null) {
                $args[] = self::object(['cause' => $cause]);
            }
            $error = self::construct($ctor, $args);
            if ($error instanceof JsObject) {
                return $error;
            }
        }
        $error = new JsObject();
        $error->set('message', new JsString($message));
        $error->set('name', new JsString($type));
        return $error;
    }

    /** `throw Error(message)`. */
    public static function error(string $message, string $type = 'Error', ?JsValue $cause = null): JsThrowable
    {
        return new JsThrowable(self::makeError($message, $type, $cause));
    }

    /**
     * The JS value a PHP exception stands for, as a JS catch block would
     * see it. Resource-limit errors are not catchable and are rethrown.
     */
    public static function thrownValue(\Throwable $e): JsValue
    {
        if ($e instanceof JsThrowable) {
            return $e->jsValue;
        }
        if ($e instanceof InternalError || !$e instanceof \Phasis\Exceptions\RuntimeError && !$e instanceof \Phasis\Exceptions\SyntaxError) {
            throw $e;
        }
        $interpreter = Engine::getCurrentInterpreter();
        if ($interpreter === null) {
            throw $e;
        }
        return $interpreter->phpExceptionToJsValue($e);
    }

    /** Rethrow a JS value as a PHP exception. */
    public static function throw(JsValue $value): never
    {
        throw new JsThrowable($value);
    }

    /** A native JS function. */
    public static function fn(string $name, \Closure $fn, int $length = 0): JsFunction
    {
        return JsFunction::fromCallable($name, $fn, $length);
    }

    public static function defineHidden(JsObject $object, string $key, JsValue $value): void
    {
        $object->defineOwnProperty($key, PropertyDescriptor::data($value, true, false, true));
    }

    /** `Object.prototype.toString.call(value)` for error messages. */
    public static function objectTag(JsValue $value): string
    {
        $tagSymbol = SymbolConstructor::toStringTag();
        if ($value instanceof JsObject) {
            $tag = $value->getBySymbol($tagSymbol);
            if ($tag instanceof JsString) {
                return '[object ' . $tag->value . ']';
            }
            return match (true) {
                self::isArray($value) => '[object Array]',
                $value instanceof JsFunction => '[object Function]',
                default => '[object Object]',
            };
        }
        return '[object ' . ucfirst($value->typeof()) . ']';
    }

    private static function describe(JsValue $value): string
    {
        return match (true) {
            $value instanceof JsString => '"' . $value->value . '"',
            $value instanceof JsObject => self::objectTag($value) === '[object Array]' ? 'object' : 'object',
            $value instanceof JsBigInt, $value instanceof JsNumber, $value instanceof JsBoolean => $value->display(),
            default => $value->display(),
        };
    }
}
