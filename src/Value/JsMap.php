<?php

declare(strict_types=1);

namespace PhpJs\Value;

use PhpJs\Spec\AbstractOperations;

/**
 * JavaScript Map object.
 *
 * Stores key-value entries using SameValueZero for key comparison.
 * Internally uses a flat list of [key, value] pairs to support
 * object keys (reference equality) alongside primitive keys.
 */
class JsMap extends JsObject
{
    /** @var list<array{0: JsValue, 1: JsValue}> */
    private array $entries = [];

    public function __construct(?JsObject $prototype = null)
    {
        parent::__construct($prototype);
        $this->installSymbolIterator();
    }

    private function installSymbolIterator(): void
    {
        $map = $this;
        $iterSym = \PhpJs\BuiltIn\SymbolConstructor::iterator();
        $factory = function () use ($map, $iterSym): JsValue {
            $index = 0;
            $iterator = new JsObject();
            $nextFn = function () use ($map, &$index): JsValue {
                $result = new JsObject();
                $entries = $map->mapEntries();
                if ($index < count($entries)) {
                    $entry = $entries[$index];
                    $result->set('value', JsArray::fromArray([$entry[0], $entry[1]]));
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

    public function mapGet(JsValue $key): JsValue
    {
        $index = $this->findIndex($key);
        if ($index === -1) {
            return JsUndefined::instance();
        }
        return $this->entries[$index][1];
    }

    public function mapSet(JsValue $key, JsValue $value): void
    {
        // Normalize -0 to +0 for key storage per spec.
        if ($key instanceof JsNumber && $key->value === 0.0) {
            $key = new JsNumber(0.0);
        }

        $index = $this->findIndex($key);
        if ($index !== -1) {
            $this->entries[$index][1] = $value;
        } else {
            $this->entries[] = [$key, $value];
        }
    }

    public function mapHas(JsValue $key): bool
    {
        return $this->findIndex($key) !== -1;
    }

    public function mapDelete(JsValue $key): bool
    {
        $index = $this->findIndex($key);
        if ($index === -1) {
            return false;
        }
        array_splice($this->entries, $index, 1);
        return true;
    }

    public function mapClear(): void
    {
        $this->entries = [];
    }

    public function mapSize(): int
    {
        return count($this->entries);
    }

    public function mapForEach(JsFunction $callback, JsValue $thisArg): void
    {
        foreach ($this->entries as $entry) {
            $callback->call($thisArg, [$entry[1], $entry[0], $this]);
        }
    }

    /** @return list<array{0: JsValue, 1: JsValue}> */
    public function mapEntries(): array
    {
        return $this->entries;
    }

    /** @return list<JsValue> */
    public function mapKeys(): array
    {
        return array_map(fn(array $entry) => $entry[0], $this->entries);
    }

    /** @return list<JsValue> */
    public function mapValues(): array
    {
        return array_map(fn(array $entry) => $entry[1], $this->entries);
    }

    public function toJsString(): string
    {
        return '[object Map]';
    }

    public function display(): string
    {
        $parts = [];
        foreach ($this->entries as $entry) {
            $parts[] = $entry[0]->display() . ' => ' . $entry[1]->display();
        }
        return 'Map(' . $this->mapSize() . ') { ' . implode(', ', $parts) . ' }';
    }

    /**
     * Find the index of a key using SameValueZero comparison.
     */
    private function findIndex(JsValue $key): int
    {
        foreach ($this->entries as $i => $entry) {
            if (AbstractOperations::sameValueZero($entry[0], $key)) {
                return $i;
            }
        }
        return -1;
    }
}
