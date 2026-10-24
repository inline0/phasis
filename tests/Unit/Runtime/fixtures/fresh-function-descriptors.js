var freshFunctionDescriptors = (() => {
    const generatorPrototype = Object.getPrototypeOf(function* () {});
    const asyncPrototype = Object.getPrototypeOf(async function () {});
    const asyncGeneratorPrototype = Object.getPrototypeOf(async function* () {});
    function dataDescriptor(fn, name) {
        const descriptor = Object.getOwnPropertyDescriptor(fn, name);
        if (!descriptor) return null;
        return [descriptor.value, descriptor.writable, descriptor.enumerable, descriptor.configurable,
            'get' in descriptor, 'set' in descriptor];
    }
    function snapshot(fn) {
        const prototype = Object.getPrototypeOf(fn);
        const ownPrototype = Object.getOwnPropertyDescriptor(fn, 'prototype');
        return {
            name: dataDescriptor(fn, 'name'), length: dataDescriptor(fn, 'length'),
            keys: Reflect.ownKeys(fn).map(key => typeof key === 'symbol' ? String(key) : key),
            initialMetadataOrder: Object.getOwnPropertyNames(fn).filter(key => key === 'name' || key === 'length'),
            enumerable: Object.keys(fn),
            prototypeKind: prototype === Function.prototype ? 'function'
                : prototype === generatorPrototype ? 'generator'
                : prototype === asyncPrototype ? 'async'
                : prototype === asyncGeneratorPrototype ? 'asyncGenerator' : 'other',
            ownPrototype: ownPrototype ? [typeof ownPrototype.value,
                ownPrototype.writable, ownPrototype.enumerable, ownPrototype.configurable] : null,
            extensible: Object.isExtensible(fn)
        };
    }
    function ordinary(a, b) { return a + b; }
    function strict(a) { 'use strict'; return a; }
    function defaulted(a, b = 1, ...rest) { return [a, b, rest]; }
    function restOnly(...rest) { return rest; }
    function destructured({a}, [b]) { return a + b; }
    const arrow = (a, b) => a + b;
    const object = { method(a, b) {}, get value() { return 1; }, set value(value) {} };
    const getter = Object.getOwnPropertyDescriptor(object, 'value').get;
    const setter = Object.getOwnPropertyDescriptor(object, 'value').set;
    async function asyncFunction(a, b) { return a + b; }
    function* generator(a) { yield a; }
    async function* asyncGenerator(a) { yield a; }
    class Constructor { constructor(a, b = 0) {} }
    const anonymous = (0, function (a) {});
    const cases = { ordinary, strict, defaulted, restOnly, destructured, arrow,
        method: object.method, getter, setter, asyncFunction, generator, asyncGenerator,
        Constructor, anonymous, bound: ordinary.bind(null, 1), native: Math.max,
        dynamic: Function('a', 'b = 1', 'return a + b'), proxy: new Proxy(ordinary, {}) };
    const initial = {};
    for (const key of Object.keys(cases)) initial[key] = snapshot(cases[key]);
    const originalLength = ordinary.length;
    ordinary.length = 99;
    const sloppyWritePreserved = ordinary.length === originalLength;
    let strictWriteRejected = false;
    try { (function () { 'use strict'; ordinary.name = 'wrong'; })(); }
    catch (error) { strictWriteRejected = error instanceof TypeError; }
    Object.defineProperty(ordinary, 'length', { value: 8 });
    delete ordinary.name;
    Object.defineProperty(ordinary, 'name', { value: 'renamed', writable: true, enumerable: true, configurable: true });
    const changed = snapshot(ordinary);
    Object.freeze(ordinary);
    const frozen = snapshot(ordinary);
    function first() {} function second() {}
    const prototypeIdentity = [first.prototype !== second.prototype,
        first.prototype.constructor === first, second.prototype.constructor === second];
    return { initial, sloppyWritePreserved, strictWriteRejected, changed, frozen, prototypeIdentity };
})();
if (typeof process !== 'undefined') process.stdout.write(JSON.stringify(freshFunctionDescriptors));
