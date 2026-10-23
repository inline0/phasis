'use strict';

const observations = [];
function identity(value) { return value; }
function fail(value) { throw new RangeError('range:' + value); }
function mixed(value) {
  if (value === 0) return 40;
  return value > 0 ? Math.max(value, 3) : fail(value);
}
function invoke(fn, value) {
  const result = fn(value);
  return result;
}
function invokeMethod(receiver, value) {
  const result = receiver.invoke(value);
  return result;
}
function protectedCall(fn, value) {
  try { return fn(value); }
  catch (error) { observations.push('catch'); return error.message; }
  finally { observations.push('finally'); }
}
function cleanupCall(fn, value) {
  try { return fn(value); }
  finally { observations.push('cleanup'); }
}
function recoveredCleanup(fn, value) {
  try { return cleanupCall(fn, value); }
  catch (error) { return error.message; }
}

mixed(0);
invoke(mixed, 0);
invokeMethod({ invoke: mixed }, 0);
observations.push(invoke(mixed, 0), invoke(mixed, 8), invokeMethod({ invoke: mixed }, 9));
observations.push(protectedCall(mixed, -1), recoveredCleanup(mixed, -2));
observations.push(protectedCall(mixed, 0));

function nativeFailure() { return JSON.parse('!'); }
function errorIdentity(fn) {
  try { fn(); }
  catch (error) { return error.constructor === SyntaxError; }
}
errorIdentity(nativeFailure);
observations.push(errorIdentity(nativeFailure), errorIdentity(nativeFailure));

function tailThis() { return identity(this); }
const receiver = { invoke: tailThis };
receiver.invoke();
observations.push(invokeMethod(receiver) === receiver, invoke(tailThis) === undefined);
function argumentsTail() { return Math.max(...arguments); }
argumentsTail(1, 2);
observations.push(invoke(argumentsTail, 13));
function arrowFactory(argument) {
  return () => identity([this.tag, arguments[0], argument]);
}
const arrow = arrowFactory.call({ tag: 'lexical' }, 17);
arrow();
observations.push(invoke(arrow));

function TailPrimitive(value) { this.value = value; return Math.max(100, value); }
function TailObject(value) { return identity({ value }); }
function construct(Constructor, value) { return new Constructor(value); }
construct(TailPrimitive, 1);
construct(TailObject, 1);
observations.push(construct(TailPrimitive, 19).value, construct(TailObject, 23).value);

const vm = require('node:vm');
const sandbox = vm.createContext({});
const foreign = vm.runInContext(`({
  Number,
  SyntaxError,
  target: function target(value) {
    'use strict';
    return value ? Object(value) : JSON.parse('!');
  }
})`, sandbox);
foreign.target(1);
invoke(foreign.target, 1);
observations.push(Object.getPrototypeOf(invoke(foreign.target, 7)) === foreign.Number.prototype);
function foreignError(fn) {
  try { fn(0); }
  catch (error) { return error.constructor === foreign.SyntaxError; }
}
observations.push(foreignError(foreign.target), foreignError(foreign.target));
observations.push(Object.getPrototypeOf({}) === Object.prototype);

async function asyncTarget(value) { return value + 3; }
function asyncTail(value) { return asyncTarget(value); }
asyncTail(0);
const asyncReceiver = { invoke: asyncTail };
globalThis.__inlineTailDone = Promise.all([
  invoke(asyncTail, 27),
  invokeMethod(asyncReceiver, 28),
]).then(values => {
  observations.push(values);
  globalThis.__inlineTailObservations = JSON.stringify(observations);
  return observations;
});

globalThis.__inlineTailFunctions = [mixed, tailThis, asyncTail];
