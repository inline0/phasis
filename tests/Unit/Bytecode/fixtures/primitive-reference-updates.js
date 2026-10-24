function primitiveMemberPost(base) { return base.value++; }
function primitiveMemberStrict(base) { 'use strict'; return base.value++; }
function primitiveComputedPost(base, key) { return base[key]++; }
function primitiveComputedStrict(base, key) { 'use strict'; return base[key]++; }
function encodePrimitiveUpdate(value) {
  if (typeof value === 'bigint' || typeof value === 'symbol') return String(value);
  if (typeof value === 'number' && !Number.isFinite(value)) return String(value);
  if (value === undefined) return 'undefined';
  return value;
}
function observePrimitiveUpdate(action) {
  try { return ['return', encodePrimitiveUpdate(action())]; }
  catch (error) { return ['throw', error.name]; }
}
const primitiveUpdateResults = [];
const primitiveUpdateTrace = [];
const primitiveUpdateSymbol = Symbol('value');
for (const sample of [1, true, 'xy', BigInt('2'), Symbol('primitive')]) {
  const prototype = Object.getPrototypeOf(Object(sample));
  Object.defineProperty(prototype, 'value', {
    configurable: true,
    get: function () {
      'use strict';
      primitiveUpdateTrace.push(['get', typeof this, this === sample]);
      return '4';
    },
    set: function (value) {
      'use strict';
      primitiveUpdateTrace.push(['set', typeof this, this === sample, value]);
    },
  });
  Object.defineProperty(prototype, primitiveUpdateSymbol, {
    configurable: true,
    get: function () {
      'use strict';
      primitiveUpdateTrace.push(['symbol get', typeof this, this === sample]);
      return BigInt('9');
    },
    set: function (value) {
      'use strict';
      primitiveUpdateTrace.push(['symbol set', typeof this, this === sample, String(value)]);
    },
  });
  primitiveUpdateResults.push(observePrimitiveUpdate(() => primitiveMemberPost(sample)));
  primitiveUpdateResults.push(observePrimitiveUpdate(() => primitiveMemberStrict(sample)));
  primitiveUpdateResults.push(observePrimitiveUpdate(() => primitiveComputedPost(sample, primitiveUpdateSymbol)));
  primitiveUpdateResults.push(observePrimitiveUpdate(() => primitiveComputedStrict(sample, Object(primitiveUpdateSymbol))));
  delete prototype.value;
  delete prototype[primitiveUpdateSymbol];
}

for (const key of ['0', 'length']) {
  primitiveUpdateResults.push(observePrimitiveUpdate(() => primitiveComputedPost('12', key)));
  primitiveUpdateResults.push(observePrimitiveUpdate(() => primitiveComputedStrict('12', key)));
}

const primitiveProxyTarget = {value: 3};
primitiveUpdateResults.push(Reflect.set(new Proxy(primitiveProxyTarget, {}), 'value', 4, 1), primitiveProxyTarget.value);
const originalNumberPrototype = Object.getPrototypeOf(Number.prototype);
const primitiveProxy = new Proxy({
  get proxied() {
    'use strict';
    primitiveUpdateTrace.push(['proxy getter', typeof this, this === 1]);
    return 2;
  },
  set proxied(value) {
    'use strict';
    primitiveUpdateTrace.push(['proxy setter', typeof this, this === 1, value]);
  },
  data: 3,
}, {});
try {
  Object.setPrototypeOf(Number.prototype, primitiveProxy);
  primitiveUpdateResults.push(observePrimitiveUpdate(() => primitiveComputedStrict(1, 'proxied')));
  primitiveUpdateResults.push(observePrimitiveUpdate(() => primitiveComputedPost(1, 'data')));
  primitiveUpdateResults.push(observePrimitiveUpdate(() => primitiveComputedStrict(1, 'data')));
} finally {
  Object.setPrototypeOf(Number.prototype, originalNumberPrototype);
}

const primitiveSetTrap = new Proxy({}, {
  get(target, key, receiver) { return key === 'proxied' ? 2 : Reflect.get(target, key, receiver); },
  set(target, key, value, receiver) {
    primitiveUpdateTrace.push(['set trap', typeof receiver, receiver === 1, value]);
    return true;
  },
});
try {
  Object.setPrototypeOf(Number.prototype, primitiveSetTrap);
  primitiveUpdateResults.push(observePrimitiveUpdate(() => primitiveComputedStrict(1, 'proxied')));
} finally {
  Object.setPrototypeOf(Number.prototype, originalNumberPrototype);
}
const primitiveReferenceUpdateResult = [primitiveUpdateResults, primitiveUpdateTrace];
