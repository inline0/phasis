<?php

declare(strict_types=1);

namespace Phasis\BuiltIn\Global_;

use Phasis\Exceptions\RangeError;
use Phasis\Exceptions\TypeError;
use Phasis\Exceptions\SyntaxError;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Environment;
use Phasis\Runtime\Interpreter;
use Phasis\Spec\AbstractOperations;
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
 * GlobalObject trait part: GlobalWrappers. Composed into GlobalObject
 * via `use Global_\GlobalWrappers;`.
 */
trait GlobalWrappers
{
    private static function stringConstructor(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            // Per spec 22.1.1.1 step 2: when called as a function (no new.target),
            // a Symbol argument becomes its descriptive string. When called as a
            // constructor (new String(sym)), ToString(sym) still throws TypeError.
            $isConstruct = $this_ instanceof \Phasis\Value\JsObject && $this_->has('[[NewTarget]]');
            if (!empty($args) && $args[0] instanceof \Phasis\Value\JsSymbol && !$isConstruct) {
                $str = $args[0]->display();
            } else {
                $str = empty($args) ? '' : TypeConversion::toString($args[0]);
            }
            // When called as constructor (new String(x)), create wrapper object
            if ($this_ instanceof \Phasis\Value\JsObject && $this_->has('[[NewTarget]]')) {
                $val = new JsString($str);
                $this_->defineOwnProperty(
                    '[[PrimitiveValue]]',
                    \Phasis\Object\PropertyDescriptor::data($val, false, false, false),
                );
                // valueOf/toString come from String.prototype, not own properties.
                // Set indexed character properties and length per spec.
                // JS strings use UTF-16 code units, so use the UTF-16 length.
                $u16Len = $val->length();
                $u16 = JsString::utf8ToUtf16LE($str);
                for ($i = 0; $i < $u16Len; $i++) {
                    $codeUnit = ord($u16[$i * 2]) | (ord($u16[$i * 2 + 1]) << 8);
                    $ch = JsString::utf16CodeUnitToUtf8($codeUnit);
                    $this_->defineOwnProperty((string) $i, \Phasis\Object\PropertyDescriptor::data(
                        new JsString($ch),
                        false,
                        true,
                        false,
                    ));
                }
                $this_->defineOwnProperty('length', \Phasis\Object\PropertyDescriptor::data(
                    new \Phasis\Value\JsNumber((float) $u16Len),
                    false,
                    false,
                    false,
                ));
                return $this_;
            }
            return new JsString($str);
        };
    }

    private static function numberConstructor(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (empty($args)) {
                $num = 0.0;
            } else {
                // Per spec: use ToNumeric first, then convert BigInt to float.
                $prim = TypeConversion::toNumeric($args[0]);
                if ($prim instanceof \Phasis\Value\JsBigInt) {
                    // 𝔽(ℝ(bigint)) - BigInt → Number (may lose precision).
                    $num = (float) $prim->value;
                } else {
                    $num = TypeConversion::toNumber($prim);
                }
            }
            // When called as constructor (new Number(x)), set up wrapper.
            // valueOf/toString come from Number.prototype, not own properties.
            if ($this_ instanceof \Phasis\Value\JsObject && $this_->has('[[NewTarget]]')) {
                $val = JsNumber::of($num);
                $this_->defineOwnProperty(
                    '[[PrimitiveValue]]',
                    \Phasis\Object\PropertyDescriptor::data($val, false, false, false),
                );
                return $this_;
            }
            return JsNumber::of($num);
        };
    }

    private static function booleanConstructor(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $bool = empty($args) ? false : TypeConversion::toBoolean($args[0]);
            if ($this_ instanceof \Phasis\Value\JsObject && $this_->has('[[NewTarget]]')) {
                // Only set [[PrimitiveValue]], don't shadow prototype methods
                $this_->defineOwnProperty(
                    '[[PrimitiveValue]]',
                    \Phasis\Object\PropertyDescriptor::data(new JsBoolean($bool), false, false, false),
                );
                return $this_;
            }
            return new JsBoolean($bool);
        };
    }
}
