<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Spec\AbstractOperations;

/**
 * JavaScript Set object.
 *
 * Stores unique values using SameValueZero for comparison.
 */
class JsSet extends JsObject
{
    /** @var list<JsValue> */
    private array $values = [];

    public function setAdd(JsValue $value): void
    {
        // Normalize -0 to +0 per spec.
        if ($value instanceof JsNumber && $value->value === 0.0) {
            $value = new JsNumber(0.0);
        }

        if (!$this->setHas($value)) {
            $this->values[] = $value;
        }
    }

    public function setHas(JsValue $value): bool
    {
        return $this->findIndex($value) !== -1;
    }

    public function setDelete(JsValue $value): bool
    {
        $index = $this->findIndex($value);
        if ($index === -1) {
            return false;
        }
        array_splice($this->values, $index, 1);
        return true;
    }

    public function setClear(): void
    {
        $this->values = [];
    }

    public function setSize(): int
    {
        return count($this->values);
    }

    public function setForEach(JsFunction $callback, JsValue $thisArg): void
    {
        foreach ($this->values as $value) {
            $callback->call($thisArg, [$value, $value, $this]);
        }
    }

    /** @return list<JsValue> */
    public function setValues(): array
    {
        return $this->values;
    }

    public function get(string $name): JsValue
    {
        return match ($name) {
            'size' => new JsNumber((float) $this->setSize()),
            'add' => JsFunction::fromCallable('add', function (JsValue $this_, array $args): JsValue {
                $value = $args[0] ?? JsUndefined::instance();
                $this->setAdd($value);
                return $this;
            }),
            'has' => JsFunction::fromCallable('has', function (JsValue $this_, array $args): JsValue {
                $value = $args[0] ?? JsUndefined::instance();
                return new JsBoolean($this->setHas($value));
            }),
            'delete' => JsFunction::fromCallable('delete', function (JsValue $this_, array $args): JsValue {
                $value = $args[0] ?? JsUndefined::instance();
                return new JsBoolean($this->setDelete($value));
            }),
            'clear' => JsFunction::fromCallable('clear', function (JsValue $this_, array $args): JsValue {
                $this->setClear();
                return JsUndefined::instance();
            }),
            'forEach' => JsFunction::fromCallable('forEach', function (JsValue $this_, array $args): JsValue {
                $callback = $args[0] ?? null;
                if (!$callback instanceof JsFunction) {
                    throw new \PhpJs\Exceptions\TypeError('forEach callback is not a function');
                }
                $thisArg = $args[1] ?? JsUndefined::instance();
                $this->setForEach($callback, $thisArg);
                return JsUndefined::instance();
            }),
            'keys' => JsFunction::fromCallable('keys', function (JsValue $this_, array $args): JsValue {
                return JsArray::fromArray($this->values);
            }),
            'values' => JsFunction::fromCallable('values', function (JsValue $this_, array $args): JsValue {
                return JsArray::fromArray($this->values);
            }),
            'entries' => JsFunction::fromCallable('entries', function (JsValue $this_, array $args): JsValue {
                $result = [];
                foreach ($this->values as $value) {
                    $result[] = JsArray::fromArray([$value, $value]);
                }
                return JsArray::fromArray($result);
            }),
            default => parent::get($name),
        };
    }

    public function toJsString(): string
    {
        return '[object Set]';
    }

    public function display(): string
    {
        $parts = [];
        foreach ($this->values as $value) {
            $parts[] = $value->display();
        }
        return 'Set(' . $this->setSize() . ') { ' . implode(', ', $parts) . ' }';
    }

    /**
     * Find the index of a value using SameValueZero comparison.
     */
    private function findIndex(JsValue $value): int
    {
        foreach ($this->values as $i => $stored) {
            if (AbstractOperations::sameValueZero($stored, $value)) {
                return $i;
            }
        }
        return -1;
    }
}
