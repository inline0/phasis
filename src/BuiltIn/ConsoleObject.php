<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Interpreter;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
use Phasis\Value\JsBoolean;
use Phasis\Value\JsFunction;
use Phasis\Value\JsNull;
use Phasis\Value\JsNumber;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

class ConsoleObject
{
    /** @var list<string> */
    private array $output = [];

    /** @var array<string,int> per-label counters for count() / countReset(). */
    private array $counts = [];

    /** @var array<string,float> per-label start-times in ms for time() / timeLog() / timeEnd(). */
    private array $timers = [];

    /**
     * Build the `console` namespace object per
     * https://console.spec.whatwg.org/#console-namespace and the
     * WebIDL namespace-object rules:
     *
     *  - Methods are own data properties on the namespace itself
     *    (writable, non-enumerable, configurable).
     *  - The [[Prototype]] is an empty object whose [[Prototype]]
     *    is %Object.prototype% (two-deep chain).
     *  - The namespace exposes [Symbol.toStringTag] = "console" so
     *    `Object.prototype.toString.call(console) === "[object console]"`.
     */
    public function create(): JsObject
    {
        // Two-deep prototype chain: console -> empty -> Object.prototype.
        // The empty interim slot matches WebIDL's namespace-object rule
        // and is what WPT's console-is-a-namespace fixture asserts.
        $intermediateProto = new JsObject();

        $console = new JsObject($intermediateProto);

        $methods = [
            'log' => fn(array $args) => $this->emitFormatted($args),
            'error' => fn(array $args) => $this->emitFormatted($args),
            'warn' => fn(array $args) => $this->emitFormatted($args),
            'info' => fn(array $args) => $this->emitFormatted($args),
            'debug' => fn(array $args) => $this->emitFormatted($args),
            'trace' => fn(array $args) => $this->emitFormatted($args),
            'dir' => fn(array $args) => $this->emitFormatted($args),
            'dirxml' => fn(array $args) => $this->emitFormatted($args),
            'table' => fn(array $args) => $this->emitFormatted($args),
            'group' => fn(array $args) => $this->emitFormatted($args),
            'groupCollapsed' => fn(array $args) => $this->emitFormatted($args),
            'groupEnd' => static fn(array $args) => null,
        ];
        foreach ($methods as $name => $fn) {
            $console->defineOwnProperty(
                $name,
                PropertyDescriptor::data(
                    JsFunction::fromCallable($name, static function (JsValue $this_, array $args) use ($fn): JsValue {
                        $fn($args);
                        return JsUndefined::instance();
                    }),
                    /* writable */ true,
                    /* enumerable */ false,
                    /* configurable */ true,
                )
            );
        }

        // Counting + timing — both spec-defined to coerce their label
        // argument to a string via the WebIDL DOMString conversion,
        // which calls toString() exactly once. Errors from toString()
        // propagate out of the console call.
        $console->defineOwnProperty(
            'count',
            PropertyDescriptor::data(JsFunction::fromCallable('count', function (JsValue $this_, array $args): JsValue {
                $label = $this->coerceLabel($args[0] ?? null, 'default');
                $this->counts[$label] = ($this->counts[$label] ?? 0) + 1;
                $this->output[] = $label . ': ' . $this->counts[$label];
                return JsUndefined::instance();
            }), true, false, true),
        );
        $console->defineOwnProperty(
            'countReset',
            PropertyDescriptor::data(JsFunction::fromCallable('countReset', function (JsValue $this_, array $args): JsValue {
                $label = $this->coerceLabel($args[0] ?? null, 'default');
                $this->counts[$label] = 0;
                return JsUndefined::instance();
            }), true, false, true),
        );
        $console->defineOwnProperty(
            'time',
            PropertyDescriptor::data(JsFunction::fromCallable('time', function (JsValue $this_, array $args): JsValue {
                $label = $this->coerceLabel($args[0] ?? null, 'default');
                $this->timers[$label] = microtime(true) * 1000.0;
                return JsUndefined::instance();
            }), true, false, true),
        );
        $console->defineOwnProperty(
            'timeLog',
            PropertyDescriptor::data(JsFunction::fromCallable('timeLog', function (JsValue $this_, array $args): JsValue {
                $label = $this->coerceLabel($args[0] ?? null, 'default');
                if (!isset($this->timers[$label])) {
                    $this->output[] = 'Timer "' . $label . '" doesn\'t exist';
                    return JsUndefined::instance();
                }
                $elapsed = microtime(true) * 1000.0 - $this->timers[$label];
                $this->output[] = $label . ': ' . $elapsed . 'ms';
                return JsUndefined::instance();
            }), true, false, true),
        );
        $console->defineOwnProperty(
            'timeEnd',
            PropertyDescriptor::data(JsFunction::fromCallable('timeEnd', function (JsValue $this_, array $args): JsValue {
                $label = $this->coerceLabel($args[0] ?? null, 'default');
                if (!isset($this->timers[$label])) {
                    $this->output[] = 'Timer "' . $label . '" doesn\'t exist';
                    return JsUndefined::instance();
                }
                $elapsed = microtime(true) * 1000.0 - $this->timers[$label];
                unset($this->timers[$label]);
                $this->output[] = $label . ': ' . $elapsed . 'ms';
                return JsUndefined::instance();
            }), true, false, true),
        );

        // console.assert(condition, ...) — silent when truthy, logs
        // "Assertion failed:" + args otherwise.
        $console->defineOwnProperty(
            'assert',
            PropertyDescriptor::data(JsFunction::fromCallable('assert', function (JsValue $this_, array $args): JsValue {
                $cond = $args[0] ?? JsUndefined::instance();
                if (TypeConversion::toBoolean($cond)) {
                    return JsUndefined::instance();
                }
                $rest = array_slice($args, 1);
                $parts = array_map(fn(JsValue $v) => $this->formatValue($v), $rest);
                $this->output[] = 'Assertion failed: ' . implode(' ', $parts);
                return JsUndefined::instance();
            }), true, false, true),
        );

        // console.clear() — clears the captured-output buffer. Matches
        // browser dev tools behavior where the user-visible log
        // resets.
        $console->defineOwnProperty(
            'clear',
            PropertyDescriptor::data(JsFunction::fromCallable('clear', function (JsValue $this_, array $args): JsValue {
                unset($args, $this_);
                $this->output = [];
                return JsUndefined::instance();
            }), true, false, true),
        );

        // Namespace-object @@toStringTag. Lower-case per the WebIDL
        // §3.10 namespace-object rules (which require the unmodified
        // namespace identifier).
        $console->definePropertyBySymbol(
            \Phasis\BuiltIn\SymbolConstructor::toStringTag(),
            PropertyDescriptor::data(new JsString('console'), false, false, true),
        );

        return $console;
    }

    /**
     * WebIDL DOMString coercion for the count / time family. The
     * spec lets the label argument be any value — including objects
     * — but coerces via ToString, which may throw. Browsers preserve
     * the throw and the console method propagates it.
     */
    private function coerceLabel(?JsValue $val, string $defaultLabel): string
    {
        if ($val === null || $val instanceof JsUndefined) {
            return $defaultLabel;
        }
        return TypeConversion::toString($val);
    }

    /**
     * Shared `log` / `info` / `warn` / etc. body — formats every arg
     * and pushes the resulting line onto the captured buffer.
     *
     * @param list<JsValue> $args
     */
    private function emitFormatted(array $args): void
    {
        $parts = array_map(fn(JsValue $v) => $this->formatValue($v), $args);
        $this->output[] = implode(' ', $parts);
    }

    public function formatValue(JsValue $value): string
    {
        if ($value instanceof JsString) {
            return $value->value;
        }
        if ($value instanceof JsNumber) {
            return $value->toJsString();
        }
        if ($value instanceof JsUndefined) {
            return 'undefined';
        }
        if ($value instanceof JsNull) {
            return 'null';
        }
        if ($value instanceof JsArray) {
            return $this->formatArray($value);
        }
        if ($value instanceof JsFunction) {
            $name = $value->getName();
            return "[Function: {$name}]";
        }
        if ($value instanceof JsObject && DateConstructor::isDateObject($value)) {
            $toString = $value->get('toString');
            if ($toString instanceof JsFunction) {
                $result = $toString->call($value, []);
                return TypeConversion::toString($result);
            }
        }
        if ($value instanceof JsObject) {
            return $this->formatObject($value);
        }
        return $value->display();
    }

    private function formatArray(JsArray $arr): string
    {
        $parts = [];
        $len = $arr->getLength();
        for ($i = 0; $i < $len; $i++) {
            $val = $arr->get((string) $i);
            $parts[] = $this->formatForNested($val);
        }
        return '[ ' . implode(', ', $parts) . ' ]';
    }

    private function formatObject(JsObject $obj): string
    {
        $keys = $obj->getOwnPropertyNames();
        if (empty($keys)) {
            return '{}';
        }

        $parts = [];
        foreach ($keys as $key) {
            $val = $obj->get($key);
            $parts[] = "{$key}: " . $this->formatForNested($val);
        }
        return '{ ' . implode(', ', $parts) . ' }';
    }

    private function formatForNested(JsValue $value): string
    {
        if ($value instanceof JsString) {
            return "'" . str_replace("'", "\\'", $value->value) . "'";
        }
        return $this->formatValue($value);
    }

    /** @return list<string> */
    public function getOutput(): array
    {
        return $this->output;
    }

    public function getOutputString(): string
    {
        return implode("\n", $this->output);
    }

    public function clear(): void
    {
        $this->output = [];
    }
}
