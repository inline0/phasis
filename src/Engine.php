<?php

declare(strict_types=1);

namespace PhpJs;

use PhpJs\BuiltIn\ConsoleObject;
use PhpJs\BuiltIn\GlobalObject;
use PhpJs\Interop\PhpToJs;
use PhpJs\Parser\Parser;
use PhpJs\Runtime\CallStack;
use PhpJs\Runtime\Environment;
use PhpJs\Runtime\Interpreter;
use PhpJs\Spec\TypeConversion;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsUndefined;
use PhpJs\Value\JsValue;

class Engine
{
    private Environment $globalEnv;
    private Interpreter $interpreter;
    private ConsoleObject $console;
    private CallStack $callStack;

    public function __construct()
    {
        $this->callStack = new CallStack();
        $this->globalEnv = new Environment();
        $this->console = new ConsoleObject();
        $this->interpreter = new Interpreter($this->globalEnv, $this->callStack);

        $this->installBuiltins();

        // Global 'this' should be the global object
        $objProto = $this->globalEnv->has('__ObjectPrototype__')
            ? $this->globalEnv->get('__ObjectPrototype__')
            : null;
        $globalObj = new \PhpJs\Value\JsObject(
            $objProto instanceof \PhpJs\Value\JsObject ? $objProto : null,
        );

        // Install non-writable, non-configurable global value properties on the global object
        // per ES spec 19.1 (Value Properties of the Global Object).
        $globalObj->defineOwnProperty('Infinity', \PhpJs\Object\PropertyDescriptor::data(
            new \PhpJs\Value\JsNumber(INF),
            false,
            false,
            false,
        ));
        $globalObj->defineOwnProperty('NaN', \PhpJs\Object\PropertyDescriptor::data(
            new \PhpJs\Value\JsNumber(NAN),
            false,
            false,
            false,
        ));
        $globalObj->defineOwnProperty('undefined', \PhpJs\Object\PropertyDescriptor::data(
            \PhpJs\Value\JsUndefined::instance(),
            false,
            false,
            false,
        ));

        // Sync all environment bindings onto the global object so that
        // Object.getOwnPropertyDescriptor(this, "parseInt") etc. work.
        // Per ES spec, built-in function properties are writable, non-enumerable, configurable.
        $skipKeys = ['this', 'globalThis', 'Infinity', 'NaN', 'undefined',
            '__ObjectPrototype__', '__FunctionPrototype__', '__ArrayPrototype__',
            '__StringPrototype__', '__NumberPrototype__', '__ErrorPrototype__',
            '__TypeErrorPrototype__', '__RangeErrorPrototype__',
            '__ReferenceErrorPrototype__', '__SyntaxErrorPrototype__',
            '__URIErrorPrototype__', '__EvalErrorPrototype__',
            '__RegExpPrototype__', '__DatePrototype__',
            '__SymbolPrototype__', '__MapPrototype__', '__SetPrototype__',
        ];
        foreach ($this->globalEnv->allBindings() as $name => $value) {
            if (in_array($name, $skipKeys, true)) {
                continue;
            }
            if (str_starts_with($name, '__') && str_ends_with($name, '__')) {
                continue;
            }
            if (!$globalObj->hasOwnProperty($name)) {
                $globalObj->defineOwnProperty(
                    $name,
                    \PhpJs\Object\PropertyDescriptor::data($value, true, false, true),
                );
            }
        }

        $this->globalEnv->defineVar('this', $globalObj);
        $this->globalEnv->defineVar('globalThis', $globalObj);

        // Link the global environment to the global object so that
        // top-level var declarations and assignments create properties
        // on globalThis (per ES spec 9.1.1.1 Global Environment Records).
        $this->globalEnv->linkGlobalObject($globalObj);
    }

    private function installBuiltins(): void
    {
        GlobalObject::install($this->globalEnv);
        \PhpJs\BuiltIn\ObjectConstructor::install($this->globalEnv);

        // Wire Function.prototype -> Object.prototype now that both exist.
        // This must happen after ObjectConstructor::install sets globalPrototype.
        if ($this->globalEnv->has('Function') && $this->globalEnv->has('__ObjectPrototype__')) {
            $fnCtor = $this->globalEnv->get('Function');
            $objProto = $this->globalEnv->get('__ObjectPrototype__');
            if ($fnCtor instanceof JsFunction && $objProto instanceof JsObject) {
                $fnProto = $fnCtor->get('prototype');
                if ($fnProto instanceof JsObject) {
                    $fnProto->setPrototype($objProto);
                }
            }
        }
        \PhpJs\BuiltIn\ErrorConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\NumberConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\ArrayConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\StringPrototype::install($this->globalEnv);
        \PhpJs\BuiltIn\MathObject::install($this->globalEnv);
        \PhpJs\BuiltIn\JsonObject::install($this->globalEnv);
        \PhpJs\BuiltIn\SymbolConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\MapConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\SetConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\TypedArrayConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\PromiseConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\ProxyConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\ReflectObject::install($this->globalEnv);
        $this->globalEnv->defineVar('console', $this->console->create());

        \PhpJs\BuiltIn\WeakMapConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\WeakSetConstructor::install($this->globalEnv);

        // BigInt constructor (not new-able, converts to BigInt)
        $bigIntFn = JsFunction::fromCallable('BigInt', function (\PhpJs\Value\JsValue $this_, array $args): \PhpJs\Value\JsValue {
            $val = $args[0] ?? \PhpJs\Value\JsUndefined::instance();
            if ($val instanceof \PhpJs\Value\JsBigInt) {
                return $val;
            }
            if ($val instanceof \PhpJs\Value\JsNumber) {
                $n = $val->value;
                if (!is_finite($n) || floor($n) !== $n) {
                    throw new \PhpJs\Exceptions\RangeError('The number ' . $n . ' cannot be converted to a BigInt');
                }
                return new \PhpJs\Value\JsBigInt((string) (int) $n);
            }
            if ($val instanceof \PhpJs\Value\JsString) {
                $str = trim($val->value);
                if (preg_match('/^-?\d+$/', $str)) {
                    return new \PhpJs\Value\JsBigInt($str);
                }
                if (preg_match('/^0[xX][0-9a-fA-F]+$/', $str)) {
                    return new \PhpJs\Value\JsBigInt((string) hexdec(substr($str, 2)));
                }
                throw new \PhpJs\Exceptions\SyntaxError('Cannot convert ' . $str . ' to a BigInt');
            }
            if ($val instanceof \PhpJs\Value\JsBoolean) {
                return new \PhpJs\Value\JsBigInt($val->value ? '1' : '0');
            }
            throw new \PhpJs\Exceptions\TypeError('Cannot convert ' . $val->typeof() . ' to a BigInt');
        });
        $this->globalEnv->defineVar('BigInt', $bigIntFn);

        \PhpJs\BuiltIn\DateConstructor::install($this->globalEnv);

        $interp = $this->interpreter;
        $globalEnv = $this->globalEnv;
        $this->installStubConstructor('RegExp', function (\PhpJs\Value\JsValue $this_, array $args) use ($interp, $globalEnv): \PhpJs\Value\JsValue {
            $arg0 = $args[0] ?? \PhpJs\Value\JsUndefined::instance();
            $arg1 = $args[1] ?? \PhpJs\Value\JsUndefined::instance();

            $calledAsNew = $this_ instanceof \PhpJs\Value\JsObject && $this_->has('[[NewTarget]]');

            // Per spec 22.2.3.1: IsRegExp check using Symbol.match.
            $patternIsRegExp = false;
            if ($arg0 instanceof \PhpJs\Value\JsObject) {
                $matchSymbol = \PhpJs\BuiltIn\SymbolConstructor::match();
                $matchProp = $arg0->getBySymbol($matchSymbol);
                if ($matchProp instanceof \PhpJs\Value\JsUndefined) {
                    $patternIsRegExp = $arg0->has('source') && $arg0->has('flags');
                } else {
                    $patternIsRegExp = \PhpJs\Spec\TypeConversion::toBoolean($matchProp);
                }
            }

            // Spec step 4: When called as function (not new), if pattern is regexp-like
            // with flags undefined and pattern.constructor === RegExp, return pattern as-is.
            if (!$calledAsNew && $patternIsRegExp && $arg1 instanceof \PhpJs\Value\JsUndefined) {
                if ($arg0 instanceof \PhpJs\Value\JsObject) {
                    $patternCtor = $arg0->get('constructor');
                    if ($globalEnv->has('RegExp') && $patternCtor === $globalEnv->get('RegExp')) {
                        return $arg0;
                    }
                }
            }

            // If the first argument is already a RegExp object and no flags argument given,
            // return a copy with the same pattern and flags (per spec 22.2.3.1).
            if ($arg0 instanceof \PhpJs\Value\JsObject && $arg0->has('source') && $arg0->has('flags')) {
                $pattern = \PhpJs\Spec\TypeConversion::toString($arg0->get('source'));
                // Empty source is stored as (?:) on the object, but we need the raw pattern for PCRE.
                if ($pattern === '(?:)') {
                    $pattern = '';
                }
                $flags = $arg1 instanceof \PhpJs\Value\JsUndefined
                    ? \PhpJs\Spec\TypeConversion::toString($arg0->get('flags'))
                    : \PhpJs\Spec\TypeConversion::toString($arg1);
                return $interp->createRegExpFromConstructor($pattern, $flags);
            }

            $pattern = $arg0 instanceof \PhpJs\Value\JsUndefined
                ? ''
                : \PhpJs\Spec\TypeConversion::toString($arg0);
            $flags = $arg1 instanceof \PhpJs\Value\JsUndefined
                ? ''
                : \PhpJs\Spec\TypeConversion::toString($arg1);
            return $interp->createRegExpFromConstructor($pattern, $flags);
        }, 2);
    }

    private function installStubConstructor(string $name, callable $fn, int $length = 0): void
    {
        $constructor = \PhpJs\Value\JsFunction::fromCallable($name, $fn, $length);
        $constructor->setConstructable();
        $proto = new \PhpJs\Value\JsObject();
        // Per spec, constructor is writable, non-enumerable, configurable.
        $proto->defineOwnProperty(
            'constructor',
            \PhpJs\Object\PropertyDescriptor::data($constructor, true, false, true),
        );
        // Per spec, built-in constructor .prototype is non-writable, non-enumerable, non-configurable.
        $constructor->defineOwnProperty('prototype', \PhpJs\Object\PropertyDescriptor::data(
            $proto,
            false,
            false,
            false,
        ));
        $this->globalEnv->defineVar($name, $constructor);
        // Store prototype for internal use (e.g. Interpreter::createRegExpObject).
        $this->globalEnv->defineVar("__{$name}Prototype__", $proto);
    }

    public function eval(string $source): mixed
    {
        $parser = new Parser($source);
        $program = $parser->parse();
        $result = $this->interpreter->execute($program);
        return $this->toPhp($result);
    }

    public function execFile(string $path): mixed
    {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new \RuntimeException("Cannot read file: {$path}");
        }
        return $this->eval($source);
    }

    public function setGlobal(string $name, mixed $value): void
    {
        $jsValue = PhpToJs::convert($value);
        $this->globalEnv->defineVar($name, $jsValue);
    }

    public function call(string $name, mixed ...$args): mixed
    {
        $fn = $this->globalEnv->get($name);
        if (!$fn instanceof JsFunction) {
            throw new Exceptions\TypeError("{$name} is not a function");
        }

        $jsArgs = array_map(fn($a) => PhpToJs::convert($a), $args);
        $result = $this->interpreter->callFunction($fn, JsUndefined::instance(), $jsArgs);
        return $this->toPhp($result);
    }

    public function getConsoleOutput(): string
    {
        return $this->console->getOutputString();
    }

    /** @return list<string> */
    public function getConsoleLines(): array
    {
        return $this->console->getOutput();
    }

    public function clearConsole(): void
    {
        $this->console->clear();
    }

    public function reset(): void
    {
        $this->globalEnv = new Environment();
        $this->console = new ConsoleObject();
        $this->callStack = new CallStack();
        $this->interpreter = new Interpreter($this->globalEnv, $this->callStack);

        $this->installBuiltins();
    }

    public function setLimit(string $name, int $value): void
    {
        if ($name === 'maxLoopIterations') {
            $this->interpreter->setMaxLoopIterations($value);
        }
    }

    private function toPhp(JsValue $value): mixed
    {
        if ($value instanceof JsUndefined || $value instanceof \PhpJs\Value\JsNull) {
            return null;
        }
        if ($value instanceof \PhpJs\Value\JsBoolean) {
            return $value->toBoolean();
        }
        if ($value instanceof \PhpJs\Value\JsNumber) {
            $num = $value->value;
            if (is_nan($num)) {
                return NAN;
            }
            if ($num === INF) {
                return INF;
            }
            if ($num === -INF) {
                return -INF;
            }
            if ($num == (int) $num && abs($num) < PHP_INT_MAX) {
                return (int) $num;
            }
            return $num;
        }
        if ($value instanceof \PhpJs\Value\JsString) {
            return $value->value;
        }
        if ($value instanceof \PhpJs\Value\JsFunction) {
            return null; // Functions don't convert to PHP values
        }
        if ($value instanceof \PhpJs\Value\JsArray) {
            $result = [];
            $len = $value->getLength();
            for ($i = 0; $i < $len; $i++) {
                $result[] = $this->toPhp($value->get((string) $i));
            }
            return $result;
        }
        if ($value instanceof JsObject) {
            $result = [];
            foreach ($value->getOwnPropertyNames() as $key) {
                $val = $value->get($key);
                if ($val instanceof \PhpJs\Value\JsFunction) {
                    continue; // Skip function properties
                }
                $result[$key] = $this->toPhp($val);
            }
            return $result;
        }
        return null;
    }
}
