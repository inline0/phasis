'use strict';

function contains(key, object) { return key in object; }
function instance(value, constructor) { return value instanceof constructor; }
function containsOrdered(left, right) { return left() in right(); }
function instanceOrdered(left, right) { return left() instanceof right(); }
var relationalObjectResult = [];
for (let repeat = 0; repeat < 2; repeat++) {
  const trace = [];
  const symbol = Symbol('key');
  const prototype = {[symbol]: 1, inherited: 2};
  const object = Object.create(prototype);
  relationalObjectResult.push(contains('inherited', object), contains(symbol, object),
    contains(Object(symbol), object), contains('missing', object));
  const key = {[Symbol.toPrimitive](hint) { trace.push('key:' + hint); return 'inherited'; }};
  const proxy = new Proxy(object, {has(target, name) { trace.push('has:' + String(name)); return Reflect.has(target, name); }});
  relationalObjectResult.push(containsOrdered(
    () => { trace.push('left'); return key; },
    () => { trace.push('right'); return proxy; }
  ));
  for (const invalid of [null, undefined, 3, true, 'x', Symbol('rhs')]) {
    try { contains(key, invalid); }
    catch (error) { trace.push('invalid:' + error.name); }
  }
  function Constructor() {}
  const instanceValue = new Constructor();
  relationalObjectResult.push(instance(instanceValue, Constructor), instance(instanceValue, Constructor.bind(null)),
    instance({}, Constructor), instance(3, Constructor));
  const custom = {
    get [Symbol.hasInstance]() {
      trace.push('get hasInstance');
      return new Proxy(function (value) {
        trace.push('hasInstance:' + (this === custom) + ':' + value);
        return 'truthy';
      }, {});
    }
  };
  relationalObjectResult.push(instanceOrdered(
    () => { trace.push('instance left'); return 7; },
    () => { trace.push('instance right'); return custom; }
  ));
  const valueProxy = new Proxy(instanceValue, {
    getPrototypeOf(target) { trace.push('get prototype'); return Reflect.getPrototypeOf(target); }
  });
  relationalObjectResult.push(instance(valueProxy, Constructor));
  for (const invalid of [null, undefined, 3, {}, {[Symbol.hasInstance]: 1}]) {
    try { instance(instanceValue, invalid); }
    catch (error) { trace.push('invalid instance:' + error.name); }
  }
  relationalObjectResult.push(trace);
}
