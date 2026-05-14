<?php

declare(strict_types=1);

namespace Phasis\BuiltIn;

use Phasis\Runtime\Interpreter;
use Phasis\Spec\TypeConversion;
use Phasis\Value\JsArray;
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

    public function create(): JsObject
    {
        $console = new JsObject();

        $console->set('log', JsFunction::fromCallable('log', function (JsValue $this_, array $args): JsValue {
            $parts = array_map(fn(JsValue $v) => $this->formatValue($v), $args);
            $line = implode(' ', $parts);
            $this->output[] = $line;
            return JsUndefined::instance();
        }));

        $console->set('error', JsFunction::fromCallable('error', function (JsValue $this_, array $args): JsValue {
            $parts = array_map(fn(JsValue $v) => $this->formatValue($v), $args);
            $this->output[] = implode(' ', $parts);
            return JsUndefined::instance();
        }));

        $console->set('warn', JsFunction::fromCallable('warn', function (JsValue $this_, array $args): JsValue {
            $parts = array_map(fn(JsValue $v) => $this->formatValue($v), $args);
            $this->output[] = implode(' ', $parts);
            return JsUndefined::instance();
        }));

        $console->set('info', JsFunction::fromCallable('info', function (JsValue $this_, array $args): JsValue {
            $parts = array_map(fn(JsValue $v) => $this->formatValue($v), $args);
            $this->output[] = implode(' ', $parts);
            return JsUndefined::instance();
        }));

        return $console;
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
