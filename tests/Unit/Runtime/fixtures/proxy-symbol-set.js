'use strict';

function writeSymbol(target, key, value) { target[key] = value; return value; }
function readSymbol(target, key) { return target[key]; }
function outcome(action) {
  try { return action(); }
  catch (error) { return error.name; }
}

var proxySymbolSetResult = [];
for (let repeat = 0; repeat < 3; repeat++) {
  const key = Symbol('cache');
  const trace = [];
  const target = {};
  const wrapper = new Proxy(target, {
    set(object, property, value, receiver) {
      trace.push(['set', property === key, receiver === wrapper]);
      return Reflect.set(object, property, value, receiver);
    },
    getOwnPropertyDescriptor(object, property) {
      trace.push(['own', property === key]);
      return Reflect.getOwnPropertyDescriptor(object, property);
    },
    defineProperty(object, property, descriptor) {
      trace.push(['define', property === key, Object.keys(descriptor).sort()]);
      return Reflect.defineProperty(object, property, descriptor);
    }
  });
  writeSymbol(wrapper, key, 1);
  writeSymbol(wrapper, key, 2);
  proxySymbolSetResult.push(['normal', readSymbol(wrapper, key), target[key], trace]);

  const receiverTarget = {};
  const receiverTrace = [];
  const receiver = new Proxy(receiverTarget, {
    isExtensible() { throw new Error('Unexpected extensibility query'); },
    getOwnPropertyDescriptor(object, property) {
      receiverTrace.push('own');
      return Reflect.getOwnPropertyDescriptor(object, property);
    },
    defineProperty(object, property, descriptor) {
      receiverTrace.push(['define', Object.keys(descriptor).sort()]);
      return Reflect.defineProperty(object, property, descriptor);
    }
  });
  proxySymbolSetResult.push(['receiver', Reflect.set(target, key, 3, receiver),
    Reflect.set(new Proxy(target, {}), key, 4, receiver), receiverTarget[key], target[key], receiverTrace]);

  const nestedTarget = {};
  const nested = new Proxy(new Proxy(nestedTarget, {}), {});
  writeSymbol(nested, key, 5);
  proxySymbolSetResult.push(['nested fallback', nested[key], nestedTarget[key]]);

  const denied = new Proxy({}, { set() { return false; } });
  proxySymbolSetResult.push(['false set', Reflect.set(denied, key, 1),
    outcome(() => writeSymbol(denied, key, 2)),
    Function('target', 'key', 'return target[key] = 3')(denied, key),
    outcome(() => Object.assign(denied, { [key]: 4 }))]);
  const defineDenied = new Proxy({}, { defineProperty() { return false; } });
  proxySymbolSetResult.push(['false define', Reflect.set(defineDenied, key, 1),
    outcome(() => writeSymbol(defineDenied, key, 2))]);

  const primitives = [undefined, null, 3, false, 'receiver', key];
  const primitiveResults = [];
  for (const primitive of primitives) {
    const ordinary = {};
    const proxy = new Proxy(ordinary, {});
    const setterTrace = [];
    const accessor = Object.create(null, {
      [key]: { set(value) { setterTrace.push([this === primitive, value]); } }
    });
    primitiveResults.push([Reflect.set(proxy, key, 1, primitive), ordinary[key],
      Reflect.set(new Proxy(accessor, {}), key, 2, primitive),
      Reflect.set(accessor, key, 3, primitive), setterTrace]);
  }
  proxySymbolSetResult.push(['primitive receivers', primitiveResults]);

  const inheritedTrace = [];
  const inherited = new Proxy({}, {
    set(object, property, value, receiver) {
      inheritedTrace.push([property === key, receiver === child]);
      return Reflect.set(object, property, value, receiver);
    }
  });
  const child = Object.create(inherited);
  writeSymbol(child, key, 8);
  proxySymbolSetResult.push(['prototype proxy', child[key], Reflect.ownKeys(child).length,
    Reflect.ownKeys(inherited).length, inheritedTrace]);

  const receiverReadOnly = Object.defineProperty({}, key, { value: 1 });
  const receiverAccessor = Object.defineProperty({}, key, { set() { throw 'unexpected setter'; } });
  proxySymbolSetResult.push(['receiver restrictions',
    Reflect.set({}, key, 2, receiverReadOnly),
    Reflect.set({}, key, 2, receiverAccessor),
    Reflect.set({}, key, 2, Object.preventExtensions({})),
    receiverReadOnly[key]]);

  const explicitReceiver = {};
  const receiverChecks = [];
  const checkReceiver = new Proxy({}, { set(object, property, value, passed) {
    receiverChecks.push(passed === explicitReceiver);
    return true;
  } });
  Reflect.set(checkReceiver, key, 1, explicitReceiver);
  proxySymbolSetResult.push(['trap receiver', receiverChecks]);

  const frozen = Object.defineProperty({}, key, { value: NaN });
  const frozenProxy = new Proxy(frozen, { set() { return true; } });
  const getter = Object.defineProperty({}, key, { get() { return 1; } });
  proxySymbolSetResult.push(['invariants', Reflect.set(frozenProxy, key, NaN),
    outcome(() => Reflect.set(frozenProxy, key, 1)),
    outcome(() => Reflect.set(new Proxy(getter, { set() { return true; } }), key, 1))]);

  const revoked = Proxy.revocable({}, {});
  revoked.revoke();
  proxySymbolSetResult.push(['revoked', outcome(() => writeSymbol(revoked.proxy, key, 1)),
    outcome(() => Reflect.set(revoked.proxy, key, 2))]);
  const selfRevoked = Proxy.revocable(frozen, { set() { selfRevoked.revoke(); return true; } });
  proxySymbolSetResult.push(['revoked by trap', outcome(() => Reflect.set(selfRevoked.proxy, key, 1))]);

  const thrown = [];
  for (const handler of [
    { getOwnPropertyDescriptor() { throw new RangeError('own'); } },
    { defineProperty() { throw new SyntaxError('define'); } },
    { set: 1 }
  ]) {
    thrown.push(outcome(() => Reflect.set(new Proxy({}, handler), key, 1)));
  }
  proxySymbolSetResult.push(['abrupt traps', thrown]);
}
