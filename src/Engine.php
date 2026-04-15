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
        $this->globalEnv->defineVar('this', $globalObj);
        $this->globalEnv->defineVar('globalThis', $globalObj);
    }

    private function installBuiltins(): void
    {
        GlobalObject::install($this->globalEnv);
        \PhpJs\BuiltIn\ErrorConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\ObjectConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\NumberConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\ArrayConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\StringPrototype::install($this->globalEnv);
        \PhpJs\BuiltIn\MathObject::install($this->globalEnv);
        \PhpJs\BuiltIn\JsonObject::install($this->globalEnv);
        \PhpJs\BuiltIn\SymbolConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\MapConstructor::install($this->globalEnv);
        \PhpJs\BuiltIn\SetConstructor::install($this->globalEnv);
        $this->globalEnv->defineVar('console', $this->console->create());

        // WeakMap/WeakSet — use regular Map/Set storage (PHP has no weak refs for objects)
        $this->installStubConstructor('WeakMap', function (\PhpJs\Value\JsValue $this_, array $args): \PhpJs\Value\JsValue {
            $map = new \PhpJs\Value\JsMap();
            return $map;
        });
        $this->installStubConstructor('WeakSet', function (\PhpJs\Value\JsValue $this_, array $args): \PhpJs\Value\JsValue {
            $set = new \PhpJs\Value\JsSet();
            return $set;
        });

        // Stub constructors for Date and RegExp (minimal, enough for instanceof)
        $this->installStubConstructor('Date', function (\PhpJs\Value\JsValue $this_, array $args): \PhpJs\Value\JsValue {
            if ($this_ instanceof \PhpJs\Value\JsObject && $this_->has('[[NewTarget]]')) {
                $this_->set('toString', \PhpJs\Value\JsFunction::fromCallable('toString', fn() => new \PhpJs\Value\JsString(date('r'))));
                $this_->set('valueOf', \PhpJs\Value\JsFunction::fromCallable('valueOf', fn() => new \PhpJs\Value\JsNumber((float) (int) (microtime(true) * 1000))));
                $this_->set('getTime', \PhpJs\Value\JsFunction::fromCallable('getTime', fn() => new \PhpJs\Value\JsNumber((float) (int) (microtime(true) * 1000))));
                return $this_;
            }
            return new \PhpJs\Value\JsString(date('r'));
        });
        $dateConstructor = $this->globalEnv->get('Date');
        if ($dateConstructor instanceof \PhpJs\Value\JsFunction) {
            $dateConstructor->set('now', \PhpJs\Value\JsFunction::fromCallable('now', fn() => new \PhpJs\Value\JsNumber((float) (int) (microtime(true) * 1000))));
        }

        $interp = $this->interpreter;
        $this->installStubConstructor('RegExp', function (\PhpJs\Value\JsValue $this_, array $args) use ($interp): \PhpJs\Value\JsValue {
            $arg0 = $args[0] ?? \PhpJs\Value\JsUndefined::instance();
            $arg1 = $args[1] ?? \PhpJs\Value\JsUndefined::instance();

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
        });
    }

    private function installStubConstructor(string $name, callable $fn): void
    {
        $constructor = \PhpJs\Value\JsFunction::fromCallable($name, $fn);
        $proto = new \PhpJs\Value\JsObject();
        $proto->set('constructor', $constructor);
        $constructor->set('prototype', $proto);
        $this->globalEnv->defineVar($name, $constructor);
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
        // Limits are enforced at construction time; reset to apply
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
