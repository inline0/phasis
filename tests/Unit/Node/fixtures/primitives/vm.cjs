'use strict';
const vm = require('node:vm');
const { AsyncLocalStorage } = require('node:async_hooks');
require('./helpers.cjs').run(async () => {
  const result = [];
  const shared = { number: 5 };
  const sandbox = { value: 2, shared };
  result.push(['create', vm.isContext(sandbox), vm.createContext(sandbox) === sandbox, vm.createContext(sandbox) === sandbox, vm.isContext(sandbox)]);
  const global = vm.runInContext('this', sandbox);
  result.push(['global', global !== sandbox, vm.runInContext('this === globalThis', sandbox), sandbox.Array === undefined, Object.keys(sandbox).sort()]);
  result.push(['intrinsics', vm.runInContext('Array', sandbox) !== Array, vm.runInContext('Object', sandbox) !== Object, vm.runInContext('[]', sandbox) instanceof Array, vm.runInContext('typeof process', sandbox)]);
  vm.runInContext('value += 3; shared.number += 4; var exposed = 7; let hidden = 11;', sandbox);
  result.push(['bindings', sandbox.value, shared.number, sandbox.exposed, sandbox.hidden === undefined, vm.runInContext('hidden + exposed', sandbox)]);
  sandbox.value = 31;
  result.push(['live', vm.runInContext('value', sandbox)]);
  Object.defineProperty(sandbox, 'double', { get() { return this.value * 2; }, configurable: true, enumerable: true });
  result.push(['accessor', vm.runInContext('double', sandbox)]);
  const script = new vm.Script('var total = (typeof total === "undefined" ? 0 : total) + value; total', { filename: 'vm-count.js' });
  const other = vm.createContext({ value: 4 });
  result.push(['script', script.runInContext(sandbox), script.runInContext(other), script.runInContext(sandbox), sandbox.total, other.total]);
  const innerArray = vm.runInContext('Array', sandbox);
  const closure = vm.runInContext('(function () { return [value, hidden]; })', sandbox);
  const returned = closure();
  result.push(['closure', [...returned], returned instanceof innerArray, returned instanceof Array]);
  result.push(['symbols', vm.runInContext('Symbol.iterator', sandbox) === Symbol.iterator, vm.runInContext('Symbol.for("shared-vm-symbol")', sandbox) === Symbol.for('shared-vm-symbol')]);
  try { vm.runInContext('throw new Error("inside")', sandbox, { filename: 'vm-fixture.js' }); }
  catch (error) { result.push(['error', error.message, error instanceof Error, String(error.stack).includes('vm-fixture.js')]); }
  try { vm.runInContext('while (true) {}', sandbox, { timeout: 1 }); }
  catch (error) { result.push(['timeout', error.code]); }
  result.push(['after-timeout', vm.runInContext('value + 1', sandbox)]);
  result.push(['compile-function', vm.compileFunction('return prefix + value', ['value'], { parsingContext: vm.createContext({ prefix: '>' }) })('ok')]);
  result.push(['this-context', vm.runInThisContext('typeof shared'), vm.runInNewContext('value += 1; value', { value: 10 })]);
  const storage = new AsyncLocalStorage();
  const order = [];
  await storage.run('request', async () => {
    const nested = vm.createContext({ current: () => storage.getStore() });
    result.push(['context', vm.runInContext('current()', nested), storage.getStore()]);
    process.nextTick(() => order.push('tick:' + storage.getStore()));
    Promise.resolve().then(() => order.push('promise:' + storage.getStore()));
    await new Promise(resolve => setTimeout(() => { order.push('timer:' + storage.getStore()); resolve(); }, 1));
  });
  result.push(['outer-scheduling', order, storage.getStore() === undefined, [] instanceof Array, ({}).constructor === Object]);
  const originalThen = Promise.prototype.then;
  let resolveAdopted;
  const adopted = new Promise(resolve => { resolveAdopted = resolve; });
  Promise.prototype.then = function () { throw new Error('intercepted'); };
  vm.createContext({});
  resolveAdopted(Promise.resolve(1));
  Promise.prototype.then = originalThen;
  result.push(['patched-then', await adopted.then(() => 'unobserved', error => error.message)]);
  const foreignGenerator = vm.runInContext('(function* () { yield 1; return 2; })', sandbox);
  const foreignObject = vm.runInContext('Object', sandbox);
  const iterator = foreignGenerator();
  const localIterator = (function* () { yield 0; })();
  const first = iterator.next();
  const second = localIterator.next.call(iterator);
  result.push(['generator-realms', Object.getPrototypeOf(Object.prototype) === null, Object.getPrototypeOf(foreignObject.prototype) === null, Object.getPrototypeOf(first) === foreignObject.prototype, Object.getPrototypeOf(second) === Object.prototype, first.value, second.value]);
  return result;
});
