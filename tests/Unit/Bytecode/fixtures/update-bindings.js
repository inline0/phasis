globalThis.updateValue = undefined;
function postIncrement() { return updateValue++; }
function preIncrement() { return ++updateValue; }
function postDecrement() { return updateValue--; }
function preDecrement() { return --updateValue; }
function strictIncrement() { 'use strict'; return updateValue++; }
function unknownIncrement() { return missingUpdateBinding++; }
function updateCaptured(value) {
  function read() { return value; }
  var before = value++;
  return [before, read(), --value, read()];
}
function updateConstant() { return constantUpdateBinding++; }
function updateDeadZone() { return deadUpdateBinding++; }
function encodeUpdate(value) {
  if (typeof value === 'bigint') return String(value) + 'n';
  if (typeof value === 'number') {
    if (Object.is(value, -0)) return '-0';
    if (!Number.isFinite(value)) return String(value);
  }
  if (value === undefined) return 'undefined';
  return value;
}
function observeUpdate(action) {
  try { return ['return', encodeUpdate(action())]; }
  catch (error) { return ['throw', error.name]; }
}
const updateResults = [];
const updateTrace = [];
const updateOperations = [postIncrement, preIncrement, postDecrement, preDecrement];
const updateInputs = [0, -0, 2.5, NaN, Infinity, -Infinity, true, false, null, undefined,
  '12', '-0', '', 'nonnumeric', BigInt('9007199254740993'), Symbol('update')];
for (let pass = 0; pass < 2; pass++) {
  for (const operation of updateOperations) {
    for (const input of updateInputs) {
      updateValue = input;
      updateResults.push([operation.name, observeUpdate(operation), encodeUpdate(updateValue)]);
    }
    updateValue = { [Symbol.toPrimitive](hint) { updateTrace.push(hint); return BigInt('7'); } };
    updateResults.push([observeUpdate(operation), encodeUpdate(updateValue)]);
    updateValue = { valueOf() { updateTrace.push('valueOf'); return '9'; } };
    updateResults.push([observeUpdate(operation), encodeUpdate(updateValue)]);
  }
  updateResults.push(updateCaptured('6').map(encodeUpdate), updateCaptured(BigInt('9')).map(encodeUpdate));
  updateResults.push(observeUpdate(unknownIncrement));
  updateResults.push(observeUpdate(updateDeadZone));
}
let deadUpdateBinding = 4;
updateResults.push(observeUpdate(updateDeadZone), deadUpdateBinding);
const constantUpdateBinding = { valueOf() { updateTrace.push('constant conversion'); return 3; } };
updateResults.push(observeUpdate(updateConstant));

// Accessor reads occur once, and numeric conversion precedes PutValue.
delete globalThis.updateValue;
for (const operation of [postIncrement, preDecrement, strictIncrement]) {
  let stored;
  Object.defineProperty(globalThis, 'updateValue', {
    configurable: true,
    get() {
      updateTrace.push('get');
      return { [Symbol.toPrimitive](hint) { updateTrace.push('convert ' + hint); return '11'; } };
    },
    set(value) { updateTrace.push('set ' + value); stored = value; },
  });
  updateResults.push([observeUpdate(operation), stored]);
}
Object.defineProperty(globalThis, 'updateValue', { value: 8, writable: false, configurable: true });
updateResults.push(observeUpdate(postIncrement), updateValue, observeUpdate(strictIncrement), updateValue);
Object.defineProperty(globalThis, 'updateValue', {
  configurable: true,
  get() { updateTrace.push('throwing getter'); throw new RangeError('get'); },
});
updateResults.push(observeUpdate(postIncrement));
delete globalThis.updateValue;

for (const operation of [postIncrement, strictIncrement]) {
  Object.defineProperty(globalThis, 'updateValue', {
    configurable: true,
    get() {
      updateTrace.push('deleting getter');
      delete globalThis.updateValue;
      return { valueOf() { updateTrace.push('conversion after delete'); return 2; } };
    },
  });
  updateResults.push(observeUpdate(operation), Object.hasOwn(globalThis, 'updateValue'));
  delete globalThis.updateValue;
}
Object.defineProperty(globalThis, 'updateValue', {
  configurable: true,
  get() { updateTrace.push('conversion throws'); return { valueOf() { throw new SyntaxError('numeric'); } }; },
  set() { updateTrace.push('unexpected setter'); },
});
updateResults.push(observeUpdate(postIncrement));
Object.defineProperty(globalThis, 'updateValue', {
  configurable: true,
  get() { updateTrace.push('setter will throw'); return '4'; },
  set(value) { updateTrace.push('throwing set ' + value); throw new RangeError('set'); },
});
updateResults.push(observeUpdate(preIncrement));
delete globalThis.updateValue;

const updateBindingResult = [updateResults, updateTrace];
