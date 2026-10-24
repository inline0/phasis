const hoistBindingResult = [];
function localOnly() {
  const token = 3;
  function helper() { return token; }
  return helper();
}
function recursive() {
  function helper(depth) { return depth ? helper(depth - 1) : 5; }
  return helper(3);
}
function siblings() {
  function first() { return second(); }
  function second() { return 7; }
  return first();
}
function replaceCaptured() {
  function read() { return helper; }
  function helper() { return 1; }
  helper = 'changed';
  return read();
}
function duplicateDeclarations() {
  function helper() { return 1; }
  function helper() { return 2; }
  return helper();
}
function varRedeclaration() {
  function helper() { return 4; }
  var helper;
  return helper();
}
function varReplacement() {
  function helper() { return 4; }
  var helper = 8;
  return helper;
}
function parameterCollision(helper) {
  function helper() { return 9; }
  const snapshot = parameterCollision['arguments'];
  return [helper(), snapshot[0] === helper];
}
function restCollision(...helper) {
  function helper() { return 11; }
  return helper();
}
function defaultCollision(helper = 1) {
  function helper() { return 13; }
  return helper();
}
function retainedRest(...values) {
  function read() { return values.slice(); }
  return read;
}
function directEval() {
  function helper() { return 15; }
  return eval('helper()');
}
function nestedEval() {
  function helper() { return 17; }
  function read() { return eval('helper()'); }
  return read();
}
function classEval() {
  function helper() { return 19; }
  return new class { read() { return eval('helper()'); } }().read();
}
function withBinding() {
  function helper() { return 21; }
  with ({}) { return helper(); }
}
function strictBindings() {
  'use strict';
  function helper() { return 23; }
  return helper();
}
for (let pass = 0; pass < 3; pass++) {
  hoistBindingResult.push(localOnly(), recursive(), siblings(), replaceCaptured(), duplicateDeclarations(),
    varRedeclaration(), varReplacement(), parameterCollision(1), restCollision(1), defaultCollision(),
    directEval(), nestedEval(), classEval(), withBinding(), strictBindings());
  const first = retainedRest(1, 2);
  const second = retainedRest(3);
  hoistBindingResult.push(first(), second(), first());
}
let escapedHelper;
function keepCaller() { escapedHelper = keepCaller.caller; }
function escapeThroughCaller(hook) {
  let value = 1;
  function helper() { hook(); value++; }
  helper();
  return () => value;
}
for (let pass = 0; pass < 3; pass++) {
  const read = escapeThroughCaller(keepCaller);
  hoistBindingResult.push(read(), escapedHelper.name);
  escapedHelper();
  hoistBindingResult.push(read());
  escapedHelper = null;
}
