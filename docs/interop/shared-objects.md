---
title: "Shared objects"
description: "PHP objects cannot be shared with JavaScript yet. What setGlobal() does with an object today, and how to expose PHP state and methods with arrays and closures instead."
path: "interop/shared-objects"
order: 50
section: "Interop"
meta_title: "Shared objects"
meta_description: "PHP objects cannot be shared with JavaScript yet. What setGlobal() does with an object today, and how to expose PHP state and methods with arrays and closures instead."
---

# Shared objects

Passing a PHP object into JavaScript by reference — reading its properties, calling its methods and seeing the changes in PHP — is **not supported yet**. It is tracked in [issue #18](https://github.com/inline0/phasis/issues/18). This page describes what happens today and the patterns that work instead.

## What happens today

`setGlobal()` converts its value with the rules in [Value conversion](/docs/interop/values). A PHP object that is not callable has no conversion, so the global is `undefined`:

```php
class Counter {
    public int $value = 0;
    public function increment(): void { $this->value++; }
}

$counter = new Counter();
$engine->setGlobal('counter', $counter);

$engine->eval('typeof counter');
// "undefined"

$engine->eval('counter.increment()');
// throws Phasis\Exceptions\TypeError:
// Cannot read properties of undefined (reading 'increment')
```

The same applies to `stdClass`, entities and models, service containers, generators and resources. Two exceptions are callable objects:

- An object with `__invoke()` becomes a JS function that calls it.
- A callable array such as `[$counter, 'increment']` becomes a JS function that calls that method.

There is no `PhpBridge` or other wrapper class for exposing objects; `src/Interop/` contains only the value converter.

## Expose methods as closures

A closure can capture the object, so JS calls a function and the method runs on the original PHP instance:

```php
$counter = new Counter();
$engine->setGlobal('increment', fn () => $counter->increment());
$engine->setGlobal('count', fn () => $counter->value);

$engine->eval('increment(); increment(); count()');
// 2

echo $counter->value;
// 2
```

A callable array does the same without a closure:

```php
$engine->setGlobal('increment', [$counter, 'increment']);
```

## Group them in an array

An associative array becomes a JS object, and closures inside it become functions. That gives JS an object-shaped API whose methods act on the PHP instance:

```php
class User {
    public function __construct(
        public string $name,
        public int $age,
        private string $secret,
    ) {}

    public function greet(): string {
        return "Hi, I'm $this->name";
    }
}

$user = new User('Ada', 36, 'topsecret');

$engine->setGlobal('user', [
    'name'   => $user->name,
    'greet'  => fn () => $user->greet(),
    'setAge' => function (int $age) use ($user): void { $user->age = $age; },
]);

$engine->eval('user.greet()');
// "Hi, I'm Ada"

$engine->eval('user.setAge(37)');
echo $user->age;
// 37
```

Only what you put in the array is visible to JS, so private state such as `$secret` stays in PHP.

The data fields are copies taken when `setGlobal()` runs. In the example, `user.name` is a string in JS; assigning to it changes the JS copy, not `$user->name`. Route every write through a closure.

## Pass data out, results back

For data-shaped objects, convert to an array on the way in and read the result on the way out:

```php
$post = ['title' => 'Draft', 'tags' => ['php']];

$engine->setGlobal('post', $post);
$engine->eval('function edit(p) { p.title = "Edited from JS"; return p; }');

$post = $engine->call('edit', $post);
echo $post['title'];
// Edited from JS
```

`get_object_vars($object)` or a `toArray()` method gives you the array for an object. Then apply the returned array to your object, entity or model in PHP.

## Caveats

- Exceptions thrown inside these closures currently cannot be caught by JS `try` / `catch`; they reach the caller of `eval()` ([issue #20](https://github.com/inline0/phasis/issues/20)). See [Host functions](/docs/interop/host-functions#throwing-back-into-js).
- A closure registered with `setGlobal()` keeps the objects it captures alive as long as the engine holds the global.
