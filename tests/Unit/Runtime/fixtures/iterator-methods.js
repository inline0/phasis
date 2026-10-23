function methodLoop(input, mode, trace) {
  const values = [];
  for (const value of input) {
    trace.push('body:' + value);
    values.push(value);
    if (mode === 'break') break;
    if (mode === 'return') return value;
    if (mode === 'throw') throw new RangeError('body');
  }
  return values;
}
function methodObserve(action) {
  try { return ['return', action()]; }
  catch (error) { return ['throw', error.name]; }
}
const iteratorMethodsResult = [];
for (const mode of ['normal', 'break', 'return', 'throw']) {
  for (const revoked of ['none', 'next', 'return']) {
    const trace = [];
    let index = 0;
    const iterator = {};
    const next = Proxy.revocable(function () {
      return {done: index >= 2, value: ++index};
    }, {
      apply(target, receiver, args) {
        trace.push('next receiver:' + (receiver === iterator) + ':' + args.length);
        return Reflect.apply(target, receiver, args);
      },
    });
    const close = Proxy.revocable(function () { return {}; }, {
      apply(target, receiver, args) {
        trace.push('return receiver:' + (receiver === iterator) + ':' + args.length);
        return Reflect.apply(target, receiver, args);
      },
    });
    iterator.next = next.proxy;
    iterator.return = close.proxy;
    if (revoked === 'next') next.revoke();
    if (revoked === 'return') close.revoke();
    const input = {};
    input[Symbol.iterator] = new Proxy(function () { return iterator; }, {
      apply(target, receiver, args) {
        trace.push('iterator receiver:' + (receiver === input) + ':' + args.length);
        return Reflect.apply(target, receiver, args);
      },
    });
    iteratorMethodsResult.push([mode, revoked, methodObserve(function () { return methodLoop(input, mode, trace); }), trace]);
  }
}
for (const sample of ['abc', 7, true, BigInt('8'), Symbol('sample')]) {
  const prototype = Object.getPrototypeOf(Object(sample));
  const previous = Object.getOwnPropertyDescriptor(prototype, Symbol.iterator);
  const trace = [];
  try {
    Object.defineProperty(prototype, Symbol.iterator, {
      configurable: true,
      get: function () {
        'use strict';
        trace.push('getter receiver:' + typeof this + ':' + (this === sample));
        return new Proxy(function () { return [9].values(); }, {
          apply(target, receiver, args) {
            trace.push('method receiver:' + typeof receiver + ':' + (receiver === sample));
            return Reflect.apply(target, receiver, args);
          },
        });
      },
    });
    iteratorMethodsResult.push([typeof sample, methodObserve(function () { return methodLoop(sample, 'normal', trace); }), trace]);
    Object.defineProperty(prototype, Symbol.iterator, {value: undefined, configurable: true});
    iteratorMethodsResult.push([typeof sample, 'missing', methodObserve(function () { return methodLoop(sample, 'normal', []); })]);
  } finally {
    if (previous) Object.defineProperty(prototype, Symbol.iterator, previous);
    else delete prototype[Symbol.iterator];
  }
}
