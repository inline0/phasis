<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\String;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;
use Phasis\BuiltIn\SymbolConstructor;

/**
 * StringPrototype trait part: StringMisc. Composed into
 * StringPrototype via `use String\StringMisc;`. `self::`/`$this->`
 * resolve into the composing class.
 */
trait StringMisc
{
    private static function rawFn(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            // String.raw`template` receives a template object as first arg, then substitutions.
            $template = $args[0] ?? JsUndefined::instance();
            if (!$template instanceof JsObject) {
                throw new \Phasis\Exceptions\TypeError('String.raw: template argument must be an object');
            }
            $rawVal = $template->get('raw');
            if (!$rawVal instanceof JsObject) {
                throw new \Phasis\Exceptions\TypeError('String.raw: template.raw must be an object');
            }
            $rawLen = ($rawVal instanceof JsArray)
                ? $rawVal->getLength()
                : (int) TypeConversion::toNumber($rawVal->get('length'));
            if ($rawLen === 0) {
                return new JsString('');
            }
            $result = '';
            for ($i = 0; $i < $rawLen; $i++) {
                $result .= TypeConversion::toString($rawVal->get((string) $i));
                // Substitutions are appended only for indices 0..rawLen-2 (spec §21.1.2.4 step 12.f).
                // If fewer substitutions are provided than needed, use empty string (spec step 12.g).
                if ($i < $rawLen - 1) {
                    $sub = $args[$i + 1] ?? new JsString('');
                    $result .= TypeConversion::toString($sub);
                }
            }
            return new JsString($result);
        };
    }

    /**
     * RequireObjectCoercible(this) then coerce to string.
     *
     * Per spec, all String.prototype methods must reject null and undefined
     * with a TypeError before any coercion occurs.
     */
    private static function extractString(JsValue $this_): string
    {
        if ($this_ instanceof JsUndefined || $this_ instanceof JsNull) {
            throw new \Phasis\Exceptions\TypeError(
                'String.prototype method called on null or undefined',
            );
        }
        if ($this_ instanceof JsString) {
            return $this_->value;
        }
        if ($this_ instanceof JsObject) {
            $prim = $this_->get('[[PrimitiveValue]]');
            if ($prim instanceof JsString) {
                return $prim->value;
            }
            // Object wrapping a non-string primitive (e.g. new Object(42),
            // new Object(true)): convert the inner primitive to string so
            // that String.prototype methods called on the wrapper produce
            // the same result as in V8.
            if (
                !($prim instanceof JsUndefined)
                && !($prim instanceof JsObject)
            ) {
                return TypeConversion::toString($prim);
            }
        }
        return TypeConversion::toString($this_);
    }

    private static function localeCompare(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $str = self::extractString($this_);
            $that = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $localesArg = $args[1] ?? \Phasis\Value\JsUndefined::instance();
            $optionsArg = $args[2] ?? \Phasis\Value\JsUndefined::instance();
            // Delegate to Intl.Collator when available so locale /
            // options validation matches the spec exactly. localeCompare
            // is "Same as Intl.Collator(locales, options).compare(this, that)".
            if (class_exists(\Collator::class) && extension_loaded('intl')) {
                $env = \Phasis\Engine::getCurrentInterpreter()?->getGlobalEnv();
                $intlObj = $env?->get('Intl', false);
                if ($intlObj instanceof \Phasis\Value\JsObject) {
                    $colCtor = $intlObj->get('Collator');
                    if ($colCtor instanceof \Phasis\Value\JsFunction) {
                        $colProto = $colCtor->get('prototype');
                        $colObj = new \Phasis\Value\JsObject(
                            $colProto instanceof \Phasis\Value\JsObject ? $colProto : null,
                        );
                        $colObj->defineOwnProperty(
                            '[[NewTarget]]',
                            \Phasis\Object\PropertyDescriptor::data($colCtor, false, false, false),
                        );
                        ($colCtor->getNativeCallable())($colObj, [$localesArg, $optionsArg]);
                        $interp = \Phasis\Engine::getCurrentInterpreter();
                        $compareGetter = $colProto instanceof \Phasis\Value\JsObject
                            ? $colProto->getOwnPropertyDescriptor('compare')
                            : null;
                        if (
                            $interp !== null
                            && $compareGetter !== null
                            && $compareGetter->get instanceof \Phasis\Value\JsFunction
                        ) {
                            $bound = $interp->callFunction(
                                $compareGetter->get,
                                $colObj,
                                [],
                            );
                            if ($bound instanceof \Phasis\Value\JsFunction) {
                                $result = $interp->callFunction(
                                    $bound,
                                    \Phasis\Value\JsUndefined::instance(),
                                    [new JsString($str), new JsString($that)],
                                );
                                if ($result instanceof JsNumber) {
                                    return $result;
                                }
                            }
                        }
                    }
                }
            }
            // Fallback: PHP strcmp with no locale awareness.
            $cmp = strcmp($str, $that);
            if ($cmp < 0) {
                return JsNumber::of(-1.0);
            }
            if ($cmp > 0) {
                return JsNumber::of(1.0);
            }
            return JsNumber::of(0.0);
        };
    }
}
