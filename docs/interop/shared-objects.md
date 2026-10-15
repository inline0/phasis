---
title: "Shared objects"
description: "Share PHP objects with JavaScript by reference through public properties and methods."
path: "interop/shared-objects"
order: 50
section: "Interop"
meta_title: "Shared objects"
meta_description: "Share PHP objects with JavaScript by reference through public properties and methods."
---

# Shared objects

PHP objects passed to `setGlobal()`, returned by a host function, or supplied to `call()` are exposed by reference through `Phasis\Interop\PhpBridge`. Public properties stay live in both directions, and public methods run on the original PHP instance.

## Properties and methods

```php
class Counter {
    public int $value = 0;
    public function increment(): void { $this->value++; }
}

$counter = new Counter();
$engine->setGlobal('counter', $counter);

$engine->eval('counter.increment(); counter.value'); // 1
$counter->value;                                    // 1
$engine->eval('counter.value = 5');
$counter->value;                                    // 5

$counter->value = 8;
$engine->eval('counter.value');                     // 8
```

PHP property types and `readonly` restrictions still apply. Invalid typed writes raise a catchable JS error; readonly properties expose a getter without a setter. Private and protected members and magic methods are not exposed. Public methods are non-enumerable; public properties appear in `Object.keys()`.

Properties added to a `stdClass` in PHP become visible on the next JS access. Adding a property to its JS bridge also adds it to the original `stdClass`. Arrays held in PHP properties follow the normal copy conversion: assign an edited array back to the property to update PHP.

## Identity and round trips

The same PHP object has the same bridge within an engine:

```php
$engine->setGlobal('alias', $counter);
$engine->eval('alias === counter');                 // true

$engine->setGlobal('same', fn (Counter $value) => $value === $counter);
$engine->eval('same(counter)');                    // true
$engine->eval('counter') === $counter;             // true
```

Distinct PHP objects get distinct bridges. Self-referencing properties retain object identity without copying the graph. The bridge keeps its PHP object alive while JavaScript retains it; the identity cache uses weak references.

## Callables and resources

Invokable objects continue to become JS functions. Callable arrays such as `[$counter, 'increment']` also become functions. Resources have no JS representation and convert to `undefined`.

A PHP `Generator` is exposed as an object with public methods, rather than automatically becoming a JavaScript iterable. PHP promises are also host objects; see [Host functions](/docs/interop/host-functions#async-host-functions).

For an API exposing selected members, pass an associative array of data and closures. Those data fields are copies, while the closures can act on the original object.
