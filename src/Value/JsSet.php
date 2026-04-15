<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Spec\AbstractOperations;
use PhpJs\Value\JsBoolean;
use PhpJs\Value\JsFunction;
use PhpJs\Value\JsObject;
use PhpJs\Value\JsUndefined;

/**
 * JavaScript Set object.
 *
 * Stores unique values using SameValueZero for comparison.
 */
class JsSet extends JsObject
{
    /** @var list<JsValue> */
    private array $values = [];

    public function __construct(?JsObject $prototype = null)
    {
        parent::__construct($prototype);
        $this->installSymbolIterator();
    }

    private function installSymbolIterator(): void
    {
        $set = $this;
        $iterSym = \PhpJs\BuiltIn\SymbolConstructor::iterator();
        $factory = function () use ($set, $iterSym): JsValue {
            $index = 0;
            $iterator = new JsObject();
            $nextFn = function () use ($set, &$index): JsValue {
                $result = new JsObject();
                $values = $set->setValues();
                if ($index < count($values)) {
                    $result->set('value', $values[$index]);
                    $result->set('done', new JsBoolean(false));
                    $index++;
                } else {
                    $result->set('value', JsUndefined::instance());
                    $result->set('done', new JsBoolean(true));
                }
                return $result;
            };
            $iterator->set('next', JsFunction::fromCallable('next', $nextFn));
            $iterator->setBySymbol($iterSym, JsFunction::fromCallable('[Symbol.iterator]', function (JsValue $self_): JsValue {
                return $self_;
            }));
            return $iterator;
        };
        $this->setBySymbol($iterSym, JsFunction::fromCallable('[Symbol.iterator]', $factory));
    }

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
