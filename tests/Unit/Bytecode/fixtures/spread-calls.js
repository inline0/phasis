const callTrace = [];
function callMethod(object, key, value) { return object[key](value()); }
function spreadCall(fn, first, items, last) { return fn(first(), ...items, last()); }
function spreadMethod(object, key, items) { return object[key](...items); }
function spreadNew(ctor, items) { return new ctor(...items); }
function tailSpread(n, value) { 'use strict'; return n ? tailSpread(...[n - 1, value + 1]) : value; }
function tailMethod(object, key, items) { 'use strict'; return object[key](...items); }
function collect() { 'use strict'; return [this === undefined, Array.from(arguments)]; }
function makeIterable(kind) {
  return {
    [Symbol.iterator]() {
      callTrace.push('iterator');
      let index = 0;
      const iterator = {
        get next() {
          callTrace.push('get next');
          const next = function () {
            callTrace.push('next ' + index);
            if (kind === 'throw-next') throw new Error('next');
            if (kind === 'primitive') return 1;
            const current = index++;
            return {
              get done() {
                callTrace.push('done ' + current);
                if (kind === 'throw-done') throw new Error('done');
                return current === 2;
              },
              get value() {
                callTrace.push('value ' + current);
                if (kind === 'throw-value') throw new Error('value');
                return current + 4;
              },
            };
          };
          return kind === 'proxy' ? new Proxy(next, {}) : next;
        },
        return() { callTrace.push('close'); return {}; },
      };
      return iterator;
    },
  };
}
const callResults = [];
const methodKey = Symbol('method');
const receiver = {
  marker: 12,
  get [methodKey]() {
    callTrace.push('get method');
    return function () { return [this.marker, Array.from(arguments)]; };
  },
};
const key = { [Symbol.toPrimitive](hint) { callTrace.push('key ' + hint); return methodKey; } };
for (let pass = 0; pass < 2; pass++) {
  callResults.push(callMethod(receiver, key, () => { callTrace.push('arg'); return 3; }));
  callResults.push(spreadCall(collect,
    () => { callTrace.push('first'); return 1; }, makeIterable('proxy'),
    () => { callTrace.push('last'); return 9; }));
  callResults.push(spreadMethod(receiver, key, makeIterable('proxy')));
  callResults.push(tailMethod(receiver, methodKey, [8]));
  const primitiveKey = Symbol('primitive');
  Number.prototype[primitiveKey] = function () { 'use strict'; return [typeof this, this]; };
  callResults.push(spreadMethod(7, primitiveKey, []));
  delete Number.prototype[primitiveKey];
  callResults.push(spreadCall(new Proxy(collect, {
    apply(target, self, args) { callTrace.push('apply'); return Reflect.apply(target, self, args); },
  }), () => 1, [2], () => 3));
  callResults.push(spreadCall(collect, () => 1, [2, , 4], () => 5));
  callResults.push(spreadNew(function Record(a, b) { this.value = a + b; }, [2, 7]).value);
  callResults.push(spreadNew(new Proxy(function Record() {}, {
    construct(target, args) { callTrace.push('construct'); return { value: args[0] }; },
  }), [11]).value);
  for (const kind of ['throw-next', 'throw-done', 'throw-value', 'primitive']) {
    try { spreadMethod(receiver, methodKey, makeIterable(kind)); }
    catch (error) { callResults.push([kind, error.name, error instanceof TypeError ? 'invalid' : error.message]); }
  }
  try { spreadMethod({ x: 1 }, 'x', makeIterable('proxy')); }
  catch (error) { callResults.push(['not callable', error.name]); }
  const notIterable = [1, 2];
  notIterable[Symbol.iterator] = undefined;
  try { spreadMethod(receiver, methodKey, notIterable); }
  catch (error) { callResults.push(['not iterable', error.name]); }
  try { callMethod(null, key, () => { callTrace.push('unexpected arg'); }); }
  catch (error) { callResults.push(['null receiver', error.name]); }
}
callResults.push(tailSpread(5000, 0));
const spreadCallResult = [callResults, callTrace];
