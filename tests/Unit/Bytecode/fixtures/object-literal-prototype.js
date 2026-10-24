function prototypeLiteral(value) {
  return { before: 1, __proto__: value, after: 2 };
}
function quotedPrototypeLiteral(value) {
  return { '__proto__': value };
}
function computedLiteral(key, value) {
  return { [key]: value };
}
function shorthandLiteral(__proto__) {
  return { __proto__ };
}
function mixedLiteral(value) {
  return { ['__proto__']: 3, __proto__: value, after: 4 };
}
function anonymousPrototypeLiteral() {
  return { __proto__: function () {} };
}
var literalTrace = [];
function markLiteral(label, value) {
  literalTrace.push(label);
  return value;
}
function orderedLiteral(key, value) {
  return {
    before: markLiteral('before', 1),
    [key]: markLiteral('value', 2),
    __proto__: markLiteral('prototype', value),
    after: markLiteral('after', 3)
  };
}
var literalPrototype = { inherited: 5 };
var literalSymbol = Symbol('key');
var setterCalls = 0;
var originalPrototypeDescriptor = Object.getOwnPropertyDescriptor(Object.prototype, '__proto__');
Object.defineProperty(Object.prototype, '__proto__', { configurable: true, set() { setterCalls++; } });
Object.defineProperty(Object.prototype, 'before', { configurable: true, set() { setterCalls++; } });
Object.defineProperty(Object.prototype, literalSymbol, { configurable: true, set() { setterCalls++; } });
var literalResults = [];
try {
  for (var round = 0; round < 2; round++) {
    for (var value of [literalPrototype, null, 0, 'text', true, undefined, literalSymbol, 1n]) {
      var object = prototypeLiteral(value);
      var expected = value === null || typeof value === 'object' ? value : Object.prototype;
      literalResults.push([
        Object.getPrototypeOf(object) === expected,
        Object.hasOwn(object, '__proto__'),
        Object.keys(object), object.before, object.after
      ]);
      literalResults.push(Object.getPrototypeOf(quotedPrototypeLiteral(value)) === expected);
    }
    var computed = computedLiteral('__proto__', literalPrototype);
    var shorthand = shorthandLiteral(literalPrototype);
    var mixed = mixedLiteral(null);
    var symbolic = computedLiteral(literalSymbol, 7);
    var anonymous = anonymousPrototypeLiteral();
    literalResults.push([
      Object.getPrototypeOf(computed) === Object.prototype, computed.__proto__ === literalPrototype,
      Object.getPrototypeOf(shorthand) === Object.prototype, shorthand.__proto__ === literalPrototype,
      Object.getPrototypeOf(mixed) === null, mixed.__proto__, mixed.after,
      Object.getOwnPropertyDescriptor(symbolic, literalSymbol),
      Object.getPrototypeOf(anonymous).name,
      Object.hasOwn(anonymous, '__proto__')
    ]);
    literalTrace = [];
    var key = { [Symbol.toPrimitive](hint) { literalTrace.push('key:' + hint); return '__proto__'; } };
    var ordered = orderedLiteral(key, literalPrototype);
    literalResults.push([literalTrace.slice(), Object.getPrototypeOf(ordered) === literalPrototype, ordered.__proto__, Object.keys(ordered)]);
    literalTrace = [];
    var throwingKey = { [Symbol.toPrimitive]() { literalTrace.push('throw'); throw new RangeError('key'); } };
    try { orderedLiteral(throwingKey, null); }
    catch (error) { literalResults.push([error.name, literalTrace.slice()]); }
  }
  literalResults.push(setterCalls);
} finally {
  Object.defineProperty(Object.prototype, '__proto__', originalPrototypeDescriptor);
  delete Object.prototype.before;
  delete Object.prototype[literalSymbol];
}
globalThis.objectLiteralPrototypeResult = literalResults;
