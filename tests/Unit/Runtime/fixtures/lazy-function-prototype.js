(() => {
  'use strict';
  const observations = [];
  const attempt = callback => {
    try { return callback(); } catch (error) { return error.name; }
  };
  const attributes = descriptor => [
    descriptor.writable, descriptor.enumerable, descriptor.configurable,
    'get' in descriptor, 'set' in descriptor
  ];

  const first = function First() {};
  first.extra = 1;
  const order = Reflect.ownKeys(first);
  const descriptor = Object.getOwnPropertyDescriptor(first, 'prototype');
  const prototype = descriptor.value;
  observations.push([
    order, Reflect.ownKeys(first), attributes(descriptor),
    Reflect.ownKeys(prototype), attributes(Object.getOwnPropertyDescriptor(prototype, 'constructor')),
    prototype.constructor === first, Object.getPrototypeOf(prototype) === Object.prototype,
    prototype === first.prototype, Object.getPrototypeOf(new first()) === prototype
  ]);

  const inherited = function Inherited() {};
  const child = Object.create(inherited);
  const inheritedPrototype = child.prototype;
  observations.push([
    inheritedPrototype === inherited.prototype,
    Reflect.get(inherited, 'prototype', 17) === inheritedPrototype,
    new Proxy(inherited, {}).prototype === inheritedPrototype,
    Object.getOwnPropertyDescriptors(inherited).prototype.value === inheritedPrototype
  ]);

  for (const value of [undefined, null, 23, 'replacement', { custom: true }]) {
    const changed = function Changed() {};
    changed.prototype = value;
    observations.push([
      changed.prototype === value,
      Object.getOwnPropertyDescriptor(changed, 'prototype').value === value,
      attributes(Object.getOwnPropertyDescriptor(changed, 'prototype')),
      Object.getPrototypeOf(new changed()) === (typeof value === 'object' && value !== null ? value : Object.prototype)
    ]);
  }

  const defined = function Defined() {};
  const replacement = {};
  Object.defineProperty(defined, 'prototype', { value: replacement, writable: false });
  observations.push([
    defined.prototype === replacement,
    Reflect.set(defined, 'prototype', {}),
    Reflect.defineProperty(defined, 'prototype', { value: replacement }),
    Reflect.defineProperty(defined, 'prototype', { value: undefined }),
    attempt(() => { defined.prototype = null; }),
    attributes(Object.getOwnPropertyDescriptor(defined, 'prototype'))
  ]);

  const locked = function Locked() {};
  Object.defineProperty(locked, 'prototype', { writable: false });
  observations.push([
    typeof locked.prototype, locked.prototype.constructor === locked,
    Object.getPrototypeOf(new locked()) === locked.prototype,
    Reflect.defineProperty(locked, 'prototype', { value: locked.prototype }),
    Reflect.defineProperty(locked, 'prototype', { value: {} }),
    Reflect.defineProperty(locked, 'prototype', { writable: true }),
    attributes(Object.getOwnPropertyDescriptor(locked, 'prototype'))
  ]);

  for (const operation of [Object.preventExtensions, Object.seal, Object.freeze]) {
    const restricted = function Restricted() {};
    // Node 26.12 reports a sealed strict function as frozen despite its writable
    // prototype. An ordinary own property avoids that V8 anomaly; the prototype
    // descriptor and replacement behavior are still checked directly below.
    restricted.extra = 1;
    operation(restricted);
    const initial = restricted.prototype;
    observations.push([
      initial.constructor === restricted,
      Object.isExtensible(restricted), Object.isSealed(restricted), Object.isFrozen(restricted),
      attributes(Object.getOwnPropertyDescriptor(restricted, 'prototype')),
      Reflect.set(restricted, 'prototype', null),
      Object.getPrototypeOf(new restricted()) === (restricted.prototype === null ? Object.prototype : initial),
      Reflect.deleteProperty(restricted, 'prototype')
    ]);
  }

  const removed = function Removed() {};
  observations.push([
    Reflect.deleteProperty(removed, 'prototype'),
    attempt(() => { delete removed.prototype; }),
    Reflect.defineProperty(removed, 'prototype', { get() { return 1; } }),
    Reflect.defineProperty(removed, 'prototype', { enumerable: true }),
    Reflect.defineProperty(removed, 'prototype', { configurable: true }),
    removed.prototype.constructor === removed
  ]);

  const frozen = function Frozen() {};
  Object.defineProperty(frozen, 'prototype', { writable: false });
  const dishonest = new Proxy(frozen, {
    get(target, name) { return name === 'prototype' ? {} : Reflect.get(target, name); },
    getOwnPropertyDescriptor(target, name) {
      return name === 'prototype'
        ? { value: {}, writable: false, enumerable: false, configurable: false }
        : Reflect.getOwnPropertyDescriptor(target, name);
    },
    defineProperty() { return true; },
    set() { return true; },
    deleteProperty() { return true; },
    ownKeys() { return ['length', 'name']; }
  });
  observations.push([
    attempt(() => dishonest.prototype),
    attempt(() => Object.getOwnPropertyDescriptor(dishonest, 'prototype')),
    attempt(() => Reflect.defineProperty(dishonest, 'prototype', { value: {} })),
    attempt(() => Reflect.set(dishonest, 'prototype', {})),
    attempt(() => Reflect.deleteProperty(dishonest, 'prototype')),
    attempt(() => Reflect.ownKeys(dishonest)),
    frozen.prototype.constructor === frozen
  ]);

  const mutable = function Mutable() {};
  const proxyValue = {};
  observations.push([
    new Proxy(mutable, { get() { return proxyValue; } }).prototype === proxyValue,
    mutable.prototype !== proxyValue,
    Reflect.getOwnPropertyDescriptor(new Proxy(mutable, {}), 'prototype').value === mutable.prototype
  ]);

  const dynamic = new Function('"use strict"; return 3;');
  const method = { method() {} }.method;
  const generator = function* () { yield 1; };
  observations.push([
    dynamic.prototype.constructor === dynamic,
    new dynamic() instanceof dynamic,
    Object.hasOwn(method, 'prototype'), Object.hasOwn(() => 1, 'prototype'),
    Object.hasOwn(dynamic.bind(null), 'prototype'), Object.hasOwn(async function () {}, 'prototype'),
    Object.hasOwn(generator, 'prototype'), Object.hasOwn(generator.prototype, 'constructor'),
    Object.getPrototypeOf(generator()) === generator.prototype
  ]);
  return observations;
})();
