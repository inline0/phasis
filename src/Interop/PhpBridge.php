<?php

declare(strict_types=1);

namespace Phasis\Interop;

use Phasis\Engine;
use Phasis\Object\PropertyDescriptor;
use Phasis\Runtime\Interpreter;
use Phasis\Value\JsFunction;
use Phasis\Value\JsObject;
use Phasis\Value\JsUndefined;
use Phasis\Value\JsValue;

final class PhpBridge extends JsObject
{
    /** @var ?\WeakMap<object, list<\WeakReference<self>>> */
    private static ?\WeakMap $instances = null;

    /** @var array<string, \ReflectionProperty> */
    private array $publicProperties = [];

    /** @var array<string, true> */
    private array $installedProperties = [];

    private function __construct(
        private readonly object $host,
        private readonly ?Interpreter $interpreter,
    ) {
        $prototype = $interpreter?->getRealm()?->getGlobalEnv()->get('__ObjectPrototype__');
        parent::__construct($prototype instanceof JsObject ? $prototype : null);
        $reflection = new \ReflectionObject($host);
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic() && $property->isDefault()) {
                $this->publicProperties[$property->getName()] = $property;
            }
        }
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (!str_starts_with($name, '__') && !isset($this->publicProperties[$name])) {
                $this->defineOwnProperty($name, PropertyDescriptor::data(
                    PhpToJs::convert([$host, $name]),
                    false,
                    false,
                    false,
                ));
            }
        }
        $this->syncProperties();
    }

    public static function wrap(object $host): self
    {
        self::$instances ??= new \WeakMap();
        $interpreter = Engine::getCurrentInterpreter();
        $references = [];
        foreach (self::$instances[$host] ?? [] as $reference) {
            $bridge = $reference->get();
            if ($bridge !== null) {
                if ($bridge->interpreter === $interpreter) {
                    return $bridge;
                }
                $references[] = $reference;
            }
        }
        $bridge = new self($host, $interpreter);
        $references[] = \WeakReference::create($bridge);
        self::$instances[$host] = $references;
        return $bridge;
    }

    public function getHostObject(): object
    {
        return $this->host;
    }

    private function syncProperties(): void
    {
        $names = array_fill_keys(array_keys(get_object_vars($this->host)), true);
        foreach ($this->publicProperties as $name => $_) {
            $names[$name] = true;
        }
        foreach ($this->installedProperties as $name => $_) {
            if (!isset($names[$name])) {
                $this->forceDelete($name);
                unset($this->installedProperties[$name]);
            }
        }
        foreach ($names as $name => $_) {
            $name = (string) $name;
            if (isset($this->installedProperties[$name])) {
                continue;
            }
            $getter = JsFunction::fromCallable('get ' . $name, function () use ($name): JsValue {
                try {
                    return PhpToJs::convert($this->host->{$name});
                } catch (\Throwable $e) {
                    PhpToJs::throwHostException($e, $this->interpreter);
                }
            });
            $setter = null;
            if (!($this->publicProperties[$name] ?? null)?->isReadOnly()) {
                $setter = JsFunction::fromCallable('set ' . $name, function (JsValue $receiver, array $args) use ($name): JsValue {
                    try {
                        $this->host->{$name} = PhpToJs::toPhp($args[0] ?? JsUndefined::instance());
                        return JsUndefined::instance();
                    } catch (\Throwable $e) {
                        PhpToJs::throwHostException($e, $this->interpreter);
                    }
                });
            }
            $this->defineOwnProperty($name, PropertyDescriptor::accessor($getter, $setter, true, false));
            $this->installedProperties[$name] = true;
        }
    }

    public function getWithValueReceiver(string $name, JsValue $receiver): JsValue
    {
        $this->syncProperties();
        return parent::getWithValueReceiver($name, $receiver);
    }

    public function internalSet(string $name, JsValue $value, JsObject $receiver): bool
    {
        $this->syncProperties();
        if ($receiver === $this && $this->host instanceof \stdClass && !$this->hasOwnProperty($name)) {
            $this->host->{$name} = PhpToJs::toPhp($value);
            $this->syncProperties();
            return true;
        }
        return parent::internalSet($name, $value, $receiver);
    }

    public function getOwnPropertyDescriptor(string $name): ?PropertyDescriptor
    {
        $this->syncProperties();
        return parent::getOwnPropertyDescriptor($name);
    }

    public function has(string $name): bool
    {
        $this->syncProperties();
        return parent::has($name);
    }

    public function hasOwnProperty(string $name): bool
    {
        $this->syncProperties();
        return parent::hasOwnProperty($name);
    }

    /** @return list<string> */
    public function ownKeys(): array
    {
        $this->syncProperties();
        return parent::ownKeys();
    }

    /** @return list<string> */
    public function getOwnPropertyNames(): array
    {
        $this->syncProperties();
        return parent::getOwnPropertyNames();
    }
}
