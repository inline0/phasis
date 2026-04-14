<?php

declare(strict_types=1);

namespace PhpJs\BuiltIn;

use PhpJs\Runtime\Environment;
use PhpJs\Runtime\Interpreter;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsNumber;
use PhpJs\Value\JsString;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class GlobalObject
{
    public static function install(Environment $env): void
    {
        $env->defineVar('undefined', JsUndefined::instance());
        $env->defineVar('NaN', new JsNumber(NAN));
        $env->defineVar('Infinity', new JsNumber(INF));

        $env->defineVar('parseInt', JsFunction::fromCallable('parseInt', self::parseInt()));
        $env->defineVar('parseFloat', JsFunction::fromCallable('parseFloat', self::parseFloat()));
        $env->defineVar('isNaN', JsFunction::fromCallable('isNaN', self::isNaN()));
        $env->defineVar('isFinite', JsFunction::fromCallable('isFinite', self::isFinite()));
        $env->defineVar('String', JsFunction::fromCallable('String', self::stringConstructor()));
        $env->defineVar('Number', JsFunction::fromCallable('Number', self::numberConstructor()));
        $env->defineVar('Boolean', JsFunction::fromCallable('Boolean', self::booleanConstructor()));

        // eval
        $env->defineVar('eval', JsFunction::fromCallable('eval', function (JsValue $this_, array $args) use ($env): JsValue {
            $code = $args[0] ?? JsUndefined::instance();
            if (!$code instanceof JsString) {
                return $code;
            }
            $parser = new \PhpJs\Parser\Parser($code->value);
            $program = $parser->parse();
            $interp = new Interpreter($env);
            return $interp->execute($program);
        }));

        // encodeURIComponent / decodeURIComponent
        $env->defineVar('encodeURIComponent', JsFunction::fromCallable('encodeURIComponent', function (JsValue $this_, array $args): JsValue {
            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            return new JsString(rawurlencode($str));
        }));
        $env->defineVar('decodeURIComponent', JsFunction::fromCallable('decodeURIComponent', function (JsValue $this_, array $args): JsValue {
            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            return new JsString(rawurldecode($str));
        }));
        $env->defineVar('encodeURI', JsFunction::fromCallable('encodeURI', function (JsValue $this_, array $args): JsValue {
            $str = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            return new JsString(str_replace(
                ['%3A', '%2F', '%3F', '%23', '%5B', '%5D', '%40', '%21', '%24', '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B', '%3D'],
                [':', '/', '?', '#', '[', ']', '@', '!', '$', '&', "'", '(', ')', '*', '+', ',', ';', '='],
                rawurlencode($str),
            ));
        }));

        // Function constructor
        $fnConstructor = JsFunction::fromCallable('Function', function (JsValue $this_, array $args): JsValue {
            $body = '';
            $params = '';
            if (count($args) > 0) {
                $body = TypeConversion::toString(array_pop($args));
                $params = implode(',', array_map(fn(JsValue $a) => TypeConversion::toString($a), $args));
            }
            $source = "function anonymous({$params}) { {$body} }";
            $parser = new \PhpJs\Parser\Parser($source);
            $program = $parser->parse();
            $interp = new Interpreter(new \PhpJs\Runtime\Environment());
            return $interp->execute($program);
        });

        // Function.prototype with call/apply/bind
        $fnProto = JsFunction::fromCallable('', fn() => JsUndefined::instance());
        $fnProto->set('call', JsFunction::fromCallable('call', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new \PhpJs\Exceptions\TypeError('call called on non-function');
            }
            $thisArg = $args[0] ?? JsUndefined::instance();
            return $this_->call($thisArg, array_slice($args, 1));
        }));
        $fnProto->set('apply', JsFunction::fromCallable('apply', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new \PhpJs\Exceptions\TypeError('apply called on non-function');
            }
            $thisArg = $args[0] ?? JsUndefined::instance();
            $argsArr = $args[1] ?? JsUndefined::instance();
            $callArgs = [];
            if ($argsArr instanceof \PhpJs\Value\JsArray) {
                for ($i = 0; $i < $argsArr->getLength(); $i++) {
                    $callArgs[] = $argsArr->get((string) $i);
                }
            }
            return $this_->call($thisArg, $callArgs);
        }));
        $fnProto->set('bind', JsFunction::fromCallable('bind', function (JsValue $this_, array $args): JsValue {
            if (!$this_ instanceof JsFunction) {
                throw new \PhpJs\Exceptions\TypeError('bind called on non-function');
            }
            $boundThis = $args[0] ?? JsUndefined::instance();
            $boundArgs = array_slice($args, 1);
            $target = $this_;
            return JsFunction::fromCallable(
                'bound ' . $target->getName(),
                function (JsValue $th, array $callArgs) use ($target, $boundThis, $boundArgs): JsValue {
                    return $target->call($boundThis, array_merge($boundArgs, $callArgs));
                },
            );
        }));

        $fnConstructor->set('prototype', $fnProto);
        $env->defineVar('Function', $fnConstructor);
    }

    private static function parseInt(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $string = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $radix = isset($args[1]) ? (int) TypeConversion::toNumber($args[1]) : 0;

            $string = trim($string);
            if ($string === '') {
                return new JsNumber(NAN);
            }

            $negative = false;
            if ($string[0] === '-') {
                $negative = true;
                $string = substr($string, 1);
            } elseif ($string[0] === '+') {
                $string = substr($string, 1);
            }

            if ($radix === 0) {
                if (str_starts_with($string, '0x') || str_starts_with($string, '0X')) {
                    $radix = 16;
                    $string = substr($string, 2);
                } else {
                    $radix = 10;
                }
            } elseif ($radix === 16) {
                if (str_starts_with($string, '0x') || str_starts_with($string, '0X')) {
                    $string = substr($string, 2);
                }
            }

            if ($radix < 2 || $radix > 36) {
                return new JsNumber(NAN);
            }

            $validChars = substr('0123456789abcdefghijklmnopqrstuvwxyz', 0, $radix);
            $result = '';
            for ($i = 0; $i < strlen($string); $i++) {
                $ch = strtolower($string[$i]);
                if (!str_contains($validChars, $ch)) {
                    break;
                }
                $result .= $ch;
            }

            if ($result === '') {
                return new JsNumber(NAN);
            }

            $value = (float) intval($result, $radix);
            return new JsNumber($negative ? -$value : $value);
        };
    }

    private static function parseFloat(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $string = isset($args[0]) ? TypeConversion::toString($args[0]) : 'undefined';
            $string = ltrim($string);

            if ($string === '' || $string === 'undefined' || $string === 'null') {
                return new JsNumber(NAN);
            }

            if ($string === 'Infinity' || $string === '+Infinity') {
                return new JsNumber(INF);
            }
            if ($string === '-Infinity') {
                return new JsNumber(-INF);
            }

            if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/', $string, $matches)) {
                return new JsNumber((float) $matches[0]);
            }

            return new JsNumber(NAN);
        };
    }

    private static function isNaN(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $value = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            return new JsBoolean(is_nan($value));
        };
    }

    private static function isFinite(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $value = isset($args[0]) ? TypeConversion::toNumber($args[0]) : NAN;
            return new JsBoolean(is_finite($value));
        };
    }

    private static function stringConstructor(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            if (empty($args)) {
                return new JsString('');
            }
            return new JsString(TypeConversion::toString($args[0]));
        };
    }

    private static function numberConstructor(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $num = empty($args) ? 0.0 : TypeConversion::toNumber($args[0]);
            // When called as constructor (new Number(x)), set up wrapper
            if ($this_ instanceof \PhpJs\Value\JsObject && !$this_ instanceof \PhpJs\Value\JsFunction) {
                $this_->set('[[PrimitiveValue]]', new JsNumber($num));
                $val = new JsNumber($num);
                $this_->set('valueOf', JsFunction::fromCallable('valueOf', fn() => $val));
                $this_->set('toString', JsFunction::fromCallable('toString', fn() => new JsString($val->toJsString())));
                return $this_;
            }
            return new JsNumber($num);
        };
    }

    private static function booleanConstructor(): \Closure
    {
        return function (JsValue $this_, array $args): JsValue {
            $bool = empty($args) ? false : TypeConversion::toBoolean($args[0]);
            if ($this_ instanceof \PhpJs\Value\JsObject && !$this_ instanceof \PhpJs\Value\JsFunction) {
                $this_->set('[[PrimitiveValue]]', new JsBoolean($bool));
                $val = new JsBoolean($bool);
                $this_->set('valueOf', JsFunction::fromCallable('valueOf', fn() => $val));
                $this_->set('toString', JsFunction::fromCallable('toString', fn() => new JsString($bool ? 'true' : 'false')));
                return $this_;
            }
            return new JsBoolean($bool);
        };
    }
}
