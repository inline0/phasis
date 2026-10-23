function typeOfValue(value) { return typeof value; }
function unknownType() { return typeof __unaryMissing__; }
function lexicalType() { return typeof laterLexical; }
function shadowedUndefined(undefined) { return typeof undefined; }
function capturedUndefined(undefined) { return function captured() { return typeof undefined; }; }
function deleteMember(object) { return delete object.value; }
function deleteComputed(object, key) { return delete object[key]; }
function deleteStrict(object, key) { 'use strict'; return delete object[key]; }
function deleteGlobal() { return delete __unaryGlobal__; }
function deleteLocal(value) { return delete value; }
function deleteSideEffect(callback) { return delete callback(); }
function deleteOrdered(object, callback) { return delete object[callback()]; }
var unaryResults = [];
unaryResults.push(unknownType());
globalThis.__unaryMissing__ = 1;
unaryResults.push(unknownType());
delete globalThis.__unaryMissing__;
unaryResults.push(unknownType());
try { lexicalType(); } catch (error) { unaryResults.push(error.name); }
let laterLexical = null;
unaryResults.push(lexicalType(), shadowedUndefined(5), shadowedUndefined(false), capturedUndefined('x')());
var revoked = Proxy.revocable(function () {}, {});
revoked.revoke();
for (var value of [undefined, null, true, 1, 'x', Symbol(), 1n, {}, [], function () {}, revoked.proxy]) {
  unaryResults.push(typeOfValue(value));
}
var unarySymbol = Symbol('key');
for (var round = 0; round < 2; round++) {
  var reads = 0;
  var object = {};
  Object.defineProperty(object, 'value', { configurable: true, get() { reads++; return 1; } });
  unaryResults.push([deleteMember(object), reads, Object.hasOwn(object, 'value')]);
  var fixed = {};
  Object.defineProperty(fixed, 'value', { value: 1 });
  Object.defineProperty(fixed, unarySymbol, { value: 2 });
  unaryResults.push([deleteMember(fixed), deleteComputed(fixed, unarySymbol), deleteLocal(1)]);
  for (var key of ['value', unarySymbol]) {
    try { deleteStrict(fixed, key); } catch (error) { unaryResults.push(error.name); }
  }
  for (var key of ['0', '1', '2', '00', '1.0', '-0', 'length', unarySymbol]) {
    unaryResults.push(deleteComputed('😀', key));
  }
  var trace = [];
  var keyObject = { [Symbol.toPrimitive](hint) { trace.push('coerce:' + hint); return unarySymbol; } };
  var keyed = { [unarySymbol]: 3 };
  unaryResults.push([deleteComputed(keyed, keyObject), trace.slice(), Object.hasOwn(keyed, unarySymbol)]);
  for (var base of [null, undefined, 1, false, Symbol(), 1n]) {
    trace = [];
    try {
      unaryResults.push(deleteOrdered(base, function () { trace.push('expression'); return keyObject; }));
    } catch (error) { unaryResults.push(error.name); }
    unaryResults.push(trace.slice());
  }
  var effects = 0;
  unaryResults.push([deleteSideEffect(function () { effects++; }), effects]);
  globalThis.__unaryGlobal__ = 4;
  unaryResults.push([deleteGlobal(), typeof __unaryGlobal__, deleteGlobal()]);
  var target = { x: 1 };
  trace = [];
  var proxy = new Proxy(target, { deleteProperty(object, key) { trace.push(key); return false; } });
  unaryResults.push([deleteComputed(proxy, 'x'), trace.slice()]);
  try { deleteStrict(proxy, 'x'); } catch (error) { unaryResults.push(error.name); }
}
globalThis.unaryReferenceResult = unaryResults;
