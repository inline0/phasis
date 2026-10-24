function memberPostIncrement(base) { return base.value++; }
function memberPreIncrement(base) { return ++base.value; }
function memberPostDecrement(base) { return base.value--; }
function memberPreDecrement(base) { return --base.value; }
function computedPostIncrement(base, key) { return base[key]++; }
function computedPreIncrement(base, key) { return ++base[key]; }
function computedPostDecrement(base, key) { return base[key]--; }
function computedPreDecrement(base, key) { return --base[key]; }
function strictMemberIncrement(base) { 'use strict'; return base.value++; }
function strictComputedIncrement(base, key) { 'use strict'; return base[key]++; }
function computedExpressionIncrement(base, key) { return base[key()]++; }
function updateGlobalMember() { return memberUpdateTarget.value++; }
function updateGlobalComputed() { return memberUpdateTarget[memberUpdateKey]++; }
function encodeMemberUpdate(value) {
  if (typeof value === 'bigint') return String(value) + 'n';
  if (typeof value === 'symbol') return String(value);
  if (typeof value === 'number') {
    if (Object.is(value, -0)) return '-0';
    if (!Number.isFinite(value)) return String(value);
  }
  if (value === undefined) return 'undefined';
  return value;
}
function observeMemberUpdate(action) {
  try { return ['return', encodeMemberUpdate(action())]; }
  catch (error) { return ['throw', error.name]; }
}
const memberUpdateResults = [];
const memberUpdateTrace = [];
const memberUpdateOperations = [memberPostIncrement, memberPreIncrement, memberPostDecrement, memberPreDecrement,
  computedPostIncrement, computedPreIncrement, computedPostDecrement, computedPreDecrement];
const memberUpdateInputs = [0, -0, 2.5, NaN, Infinity, -Infinity, true, false, null, undefined,
  '12', '-0', '', 'nonnumeric', BigInt('9007199254740993'), Symbol('update')];
for (let pass = 0; pass < 2; pass++) {
  for (const operation of memberUpdateOperations) {
    for (const input of memberUpdateInputs) {
      const base = {value: input};
      memberUpdateResults.push([operation.name, observeMemberUpdate(() => operation(base, 'value')),
        encodeMemberUpdate(base.value)]);
    }
    const base = {value: { [Symbol.toPrimitive](hint) { memberUpdateTrace.push(hint); return BigInt('7'); } }};
    memberUpdateResults.push([observeMemberUpdate(() => operation(base, 'value')), encodeMemberUpdate(base.value)]);
  }
}

for (const operation of [memberPostIncrement, memberPreDecrement, strictMemberIncrement,
  computedPostIncrement, strictComputedIncrement]) {
  const base = {};
  let stored;
  Object.defineProperty(base, 'value', {
    configurable: true,
    get() {
      memberUpdateTrace.push('get');
      return { [Symbol.toPrimitive](hint) { memberUpdateTrace.push('convert ' + hint); return '11'; } };
    },
    set(value) { memberUpdateTrace.push('set ' + value); stored = value; },
  });
  memberUpdateResults.push([observeMemberUpdate(() => operation(base, 'value')), stored]);
  Object.defineProperty(base, 'value', {value: 8, writable: false, configurable: true});
  memberUpdateResults.push(observeMemberUpdate(() => operation(base, 'value')), base.value);
  Object.defineProperty(base, 'value', {
    configurable: true,
    get() { memberUpdateTrace.push('throwing getter'); throw new RangeError('get'); },
  });
  memberUpdateResults.push(observeMemberUpdate(() => operation(base, 'value')));
  Object.defineProperty(base, 'value', {
    configurable: true,
    get() { return { valueOf() { memberUpdateTrace.push('conversion throws'); throw new SyntaxError('number'); } }; },
    set() { memberUpdateTrace.push('unexpected setter'); },
  });
  memberUpdateResults.push(observeMemberUpdate(() => operation(base, 'value')));
  Object.defineProperty(base, 'value', {
    configurable: true,
    get() { return '4'; },
    set(value) { memberUpdateTrace.push('throwing set ' + value); throw new RangeError('set'); },
  });
  memberUpdateResults.push(observeMemberUpdate(() => operation(base, 'value')));
}

for (const operation of [computedPostIncrement, computedPreDecrement, strictComputedIncrement]) {
  const target = {value: '3'};
  const proxy = new Proxy(target, {
    get(object, key, receiver) { memberUpdateTrace.push('proxy get ' + String(key)); return Reflect.get(object, key, receiver); },
    set(object, key, value, receiver) {
      memberUpdateTrace.push('proxy set ' + String(key) + '=' + value);
      return Reflect.set(object, key, value, receiver);
    },
  });
  const key = 'value';
  memberUpdateResults.push([observeMemberUpdate(() => operation(proxy, key)), target.value]);
  const refusing = new Proxy({value: 3}, {
    set(object, key, value) { memberUpdateTrace.push('refuse ' + value); return false; },
  });
  memberUpdateResults.push(observeMemberUpdate(() => operation(refusing, 'value')));
}

const memberUpdateSymbol = Symbol('slot');
for (const operation of [computedPostIncrement, computedPreDecrement]) {
  const base = {[memberUpdateSymbol]: BigInt('9')};
  const key = Object(memberUpdateSymbol);
  memberUpdateResults.push([observeMemberUpdate(() => operation(base, key)), encodeMemberUpdate(base[memberUpdateSymbol])]);
}

let memberUpdateTarget;
let memberUpdateKey;
for (const operation of [updateGlobalMember, updateGlobalComputed]) {
  memberUpdateTarget = {value: {
    valueOf() { memberUpdateTrace.push('numeric base replacement'); memberUpdateTarget = {value: 50}; return 4; },
  }};
  memberUpdateKey = 'value';
  const original = memberUpdateTarget;
  memberUpdateResults.push([observeMemberUpdate(operation), original.value, memberUpdateTarget.value]);
}

for (const base of [null, undefined]) {
  const key = { toString() { memberUpdateTrace.push('unexpected null key coercion'); return 'value'; } };
  memberUpdateResults.push(observeMemberUpdate(() => computedExpressionIncrement(base, () => {
    memberUpdateTrace.push('key expression'); return key;
  })));
}
for (const operation of [memberPostIncrement, strictMemberIncrement, computedPostIncrement, strictComputedIncrement]) {
  memberUpdateResults.push(observeMemberUpdate(() => operation(3, 'value')));
}

const memberUpdateInherited = {
  get value() { memberUpdateTrace.push('inherited get ' + this.tag); return '6'; },
  set value(value) { memberUpdateTrace.push('inherited set ' + this.tag + '=' + value); },
};
const memberUpdateReceiver = Object.create(memberUpdateInherited);
memberUpdateReceiver.tag = 'receiver';
memberUpdateResults.push(observeMemberUpdate(() => memberPostIncrement(memberUpdateReceiver)));

function memberUpdateWithinWith(object) { with (object) { return memberPostIncrement(object); } }
const memberUpdateWithTarget = {value: '4'};
const memberUpdateWithObject = new Proxy(memberUpdateWithTarget, {
  has(object, key) { memberUpdateTrace.push('with has ' + String(key)); return Reflect.has(object, key); },
  get(object, key, receiver) { memberUpdateTrace.push('with get ' + String(key)); return Reflect.get(object, key, receiver); },
  set(object, key, value, receiver) { memberUpdateTrace.push('with set ' + String(key)); return Reflect.set(object, key, value, receiver); },
});
memberUpdateResults.push(observeMemberUpdate(() => memberUpdateWithinWith(memberUpdateWithObject)), memberUpdateWithTarget.value);

const updateMemberResult = [memberUpdateResults, memberUpdateTrace];
