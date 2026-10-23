<?php

declare(strict_types=1);

namespace Phasis\Node;

use Phasis\Object\PropertyDescriptor;
use Phasis\Value\JsObject;
use Phasis\Value\JsString;
use Phasis\Value\JsSymbol;
use Phasis\Value\JsValue;

/** Contextified global properties with private intrinsics and a live external sandbox. */
final class VmGlobalObject extends JsObject
{
    public function __construct(private readonly JsObject $sandbox, private readonly JsObject $intrinsics)
    {
        parent::__construct($intrinsics->getPrototype());
    }

    public function get(string $name): JsValue
    {
        return $this->getWithValueReceiver($name, $this);
    }

    public function getWithValueReceiver(string $name, JsValue $receiver): JsValue
    {
        if ($this->sandbox->has($name)) {
            return $this->sandbox->getWithValueReceiver($name, $receiver);
        }
        return $this->intrinsics->getWithValueReceiver($name, $receiver);
    }

    public function has(string $name): bool
    {
        return $this->sandbox->has($name) || $this->intrinsics->has($name);
    }

    public function hasOwnProperty(string $name): bool
    {
        return $this->sandbox->hasOwnProperty($name) || $this->intrinsics->hasOwnProperty($name);
    }

    public function internalSet(string $name, JsValue $value, JsObject $receiver): bool
    {
        if (!$this->sandbox->has($name)) {
            $descriptor = $this->intrinsics->getOwnPropertyDescriptor($name);
            if ($descriptor !== null && $descriptor->writable === false && !$descriptor->isAccessorDescriptor()) {
                return false;
            }
        }
        return $this->sandbox->internalSet($name, $value, $receiver === $this ? $this->sandbox : $receiver);
    }

    public function defineOwnProperty(string $name, PropertyDescriptor $descriptor): bool
    {
        return $this->sandbox->defineOwnProperty($name, $descriptor);
    }

    public function getOwnPropertyDescriptor(string $name): ?PropertyDescriptor
    {
        return $this->sandbox->getOwnPropertyDescriptor($name) ?? $this->intrinsics->getOwnPropertyDescriptor($name);
    }

    public function getOwnPropertyDescriptorRaw(string $name): ?PropertyDescriptor
    {
        return $this->getOwnPropertyDescriptor($name);
    }

    public function delete(string $name, bool $strict = false): bool
    {
        if ($this->sandbox->hasOwnProperty($name)) {
            return $this->sandbox->delete($name, $strict);
        }
        return $this->intrinsics->delete($name, $strict);
    }

    /** @return list<string> */
    public function getOwnPropertyNames(): array
    {
        return array_values(array_unique(array_merge($this->sandbox->getOwnPropertyNames(), $this->intrinsics->getOwnPropertyNames())));
    }

    /** @return list<string> */
    public function ownKeys(): array
    {
        return $this->getOwnPropertyNames();
    }

    /** @return list<string> */
    public function getOwnEnumerableKeys(): array
    {
        return array_values(array_filter(
            $this->getOwnPropertyNames(),
            fn (string $name): bool => $this->getOwnPropertyDescriptor($name)?->enumerable === true,
        ));
    }

    /** @return list<JsValue> */
    public function ordinaryOwnPropertyKeys(): array
    {
        $keys = array_map(static fn (string $name): JsValue => new JsString($name), $this->getOwnPropertyNames());
        foreach ($this->getOwnSymbolsWithDescriptors() as [$symbol]) {
            $keys[] = $symbol;
        }
        return $keys;
    }

    public function getBySymbol(JsSymbol $symbol): JsValue
    {
        return $this->getBySymbolWithReceiver($symbol, $this);
    }

    public function getBySymbolWithReceiver(JsSymbol $symbol, JsValue $receiver): JsValue
    {
        return $this->sandbox->hasBySymbol($symbol)
            ? $this->sandbox->getBySymbolWithReceiver($symbol, $receiver)
            : $this->intrinsics->getBySymbolWithReceiver($symbol, $receiver);
    }

    public function hasBySymbol(JsSymbol $symbol): bool
    {
        return $this->sandbox->hasBySymbol($symbol) || $this->intrinsics->hasBySymbol($symbol);
    }

    public function internalSetBySymbol(JsSymbol $symbol, JsValue $value, JsObject $receiver): bool
    {
        return $this->sandbox->internalSetBySymbol($symbol, $value, $receiver === $this ? $this->sandbox : $receiver);
    }

    public function definePropertyBySymbol(JsSymbol $symbol, PropertyDescriptor $descriptor): bool
    {
        return $this->sandbox->definePropertyBySymbol($symbol, $descriptor);
    }

    public function getPropertyDescriptorBySymbol(JsSymbol $symbol): ?PropertyDescriptor
    {
        return $this->sandbox->getPropertyDescriptorBySymbol($symbol) ?? $this->intrinsics->getPropertyDescriptorBySymbol($symbol);
    }

    public function deleteBySymbol(JsSymbol $symbol): bool
    {
        return $this->sandbox->hasBySymbol($symbol)
            ? $this->sandbox->deleteBySymbol($symbol) : $this->intrinsics->deleteBySymbol($symbol);
    }

    /** @return list<array{JsSymbol, PropertyDescriptor}> */
    public function getOwnSymbolsWithDescriptors(): array
    {
        $entries = [];
        $symbols = array_merge($this->intrinsics->getOwnSymbolsWithDescriptors(), $this->sandbox->getOwnSymbolsWithDescriptors());
        foreach ($symbols as [$symbol, $descriptor]) {
            $entries[$symbol->getId()] = [$symbol, $descriptor];
        }
        return array_values($entries);
    }
}
