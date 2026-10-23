const restParameterResult = [];
function restOnly(...values) { return values; }
function restPrefix(first, second, ...values) { return [first, second, values]; }
function restCaptured(first, ...values) {
  let count = 0;
  return function read() {
    values.push(first + count++);
    return values.slice();
  };
}
function restVarRedeclared(...values) {
  var values;
  values.push('added');
  return () => values.slice();
}
function restHoisted(...values) {
  function values() { return 9; }
  return () => values();
}
function restDefaults(first = 3, ...values) {
  var first;
  first += 2;
  return () => [first, values.slice()];
}
function restArgumentSnapshot(first, ...values) {
  const original = arguments;
  first = 8;
  values[0] = 9;
  return [first, values, original[0], original[1]];
}
const strictRestArguments = (function () {
  'use strict';
  return function strictRestArguments(first, ...values) {
    const original = arguments;
    first = 8;
    values[0] = 9;
    return [first, values, original[0], original[1]];
  };
})();
const restArrow = (...values) => values.slice();
function restPattern(...[first, second]) { return [first, second]; }
function restMutation(first, ...values) {
  first += 1;
  values[0] = 'changed';
  return [first, values];
}
function restObjectIdentity(...values) {
  return [values.length, values[0] === restIdentityObject, values[1] === restIdentityArray,
    Object.getPrototypeOf(values) === Array.prototype];
}
function restLegacy(first, ...values) {
  const snapshot = restLegacy['arguments'];
  return [snapshot.length, snapshot[0], snapshot[1], first, values];
}
function restEval(first, ...values) { return eval('first + values.length'); }
function restWith(...values) { with ({size: values.length}) { return size; } }
const restIdentityObject = {valueOf() { throw new Error('rest must not coerce arguments'); }};
const restIdentityArray = [1, 2];

for (let pass = 0; pass < 3; pass++) {
  restParameterResult.push(restOnly(), restOnly(1, 'two', null), restOnly(undefined));
  restParameterResult.push(restPrefix(), restPrefix(1), restPrefix(1, 2), restPrefix(1, 2, 3, 4));
  const left = restCaptured(10, 'left');
  const right = restCaptured(20, 'right');
  restParameterResult.push(left(), right(), left());
  restParameterResult.push(restVarRedeclared(1)(), restVarRedeclared(2)(), restHoisted(1)());
  restParameterResult.push(restDefaults()(), restDefaults(5, 6, 7)());
  restParameterResult.push(restArgumentSnapshot(1, 2, 3), strictRestArguments(1, 2, 3));
  restParameterResult.push(restArrow(), restArrow(1, 2), restPattern(1, 2, 3));
  restParameterResult.push(restMutation(1, 2, 3), restObjectIdentity(restIdentityObject, restIdentityArray));
  restParameterResult.push(restLegacy(1, 2, 3), restEval(3, 4, 5), restWith(1, 2));
}

for (const parameters of [
  ['first', '...first', 'return first;'],
  ['first', 'first', '...tail', 'return tail;'],
  ['...values', 'last', 'return values;'],
  ['...values = []', 'return values;'],
  ['...values', 'let values = 1; return values;'],
]) {
  try { Function(...parameters); restParameterResult.push('accepted'); }
  catch (error) { restParameterResult.push(error.name); }
}
