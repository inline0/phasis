'use strict';

function first(value) { let a; const result = ([a] = value); return [a, result === value]; }
function pair(value) { let a, b; [a, b] = value; return [a, b]; }
function elided(value) { let a; [, a] = value; return a; }
function empty(value) { return ([] = value) === value; }
function rest(value) { let a, remaining; [a, ...remaining] = value; return [a, remaining]; }
function duplicate(value) { let a; [a, a] = value; return a; }
function captured(value) {
  let a = 1, b = 2;
  value.observe = () => [a, b].join(':');
  [a, b] = value;
  return [a, b];
}
function member(value, target) {
  let index = 0;
  [target[(index++, target.key())]] = value;
  return [index, target.x];
}
function restMember(value, target) {
  let a;
  [a, ...target[target.key()]] = value;
  return [a, target.x];
}
function receiver(value) { [this.x] = value; return this.x; }
function changedBase(value) {
  const before = {};
  const after = {};
  let target = before;
  value.beforeNext = () => { target = after; };
  [target.x] = value;
  return [before.x, after.x];
}
function partial(value, target) {
  let a = 'before';
  try { [a, target[target.key()]] = value; }
  catch (error) { return [a, error instanceof Error ? error.name : error]; }
  return [a, target.x];
}
var arrow = value => ([] = value);

function makeIterable(mode, trace) {
  let step = 0;
  const source = {
    [Symbol.iterator]() {
      trace.push('iterator');
      return {
        get next() {
          trace.push('get next');
          const next = function () {
            step++;
            trace.push('next:' + step);
            if (source.observe) trace.push('observe:' + source.observe());
            if (source.beforeNext) source.beforeNext();
            if (mode === 'next') throw 'next failure';
            if (mode === 'primitive result') return 1;
            return {
              get done() {
                trace.push('done:' + step);
                if (mode === 'done') throw 'done failure';
                return step > 2;
              },
              get value() {
                trace.push('value:' + step);
                if (mode === 'value' || (mode === 'hole' && step === 1)) throw 'value failure';
                return step * 10;
              }
            };
          };
          return mode === 'proxy next' ? new Proxy(next, {}) : next;
        },
        get return() {
          trace.push('get return');
          if (mode === 'return getter') throw 'return getter failure';
          const close = function () {
            trace.push('return');
            if (mode === 'return value') return 1;
            if (mode === 'return throw') throw 'return failure';
            return {};
          };
          return mode === 'proxy return' ? new Proxy(close, {}) : close;
        }
      };
    }
  };
  return source;
}

function makeTarget(mode, trace) {
  const target = {
    key() {
      trace.push('target expression');
      if (mode === 'expression') throw 'target failure';
      return {
        [Symbol.toPrimitive]() {
          trace.push('key conversion');
          if (mode === 'key') throw 'key failure';
          return 'x';
        }
      };
    }
  };
  if (mode === 'setter') Object.defineProperty(target, 'x', { set(value) { trace.push('setter:' + value); throw 'setter failure'; } });
  return target;
}

var arrayAssignmentResult = [];
for (let repeat = 0; repeat < 3; repeat++) {
  for (const test of [
    [first, 'normal'], [pair, 'normal'], [elided, 'hole'], [empty, 'normal'],
    [rest, 'normal'], [duplicate, 'normal'], [captured, 'normal'], [changedBase, 'normal'],
    [first, 'next'], [first, 'done'], [first, 'value'], [first, 'primitive result'],
    [first, 'proxy next'], [first, 'proxy return'], [first, 'return getter'], [first, 'return value'],
    [member, 'normal', 'normal'], [member, 'normal', 'expression'], [member, 'normal', 'key'],
    [member, 'return throw', 'setter'], [restMember, 'normal', 'normal'],
    [restMember, 'normal', 'expression'], [partial, 'normal', 'setter'], [partial, 'normal', 'expression']
  ]) {
    const trace = [];
    try { arrayAssignmentResult.push([test[0].name, test[1], test[2], test[0](makeIterable(test[1], trace), makeTarget(test[2], trace)), trace]); }
    catch (error) { arrayAssignmentResult.push([test[0].name, test[1], test[2], error instanceof Error ? error.name : error, trace]); }
  }
  arrayAssignmentResult.push(pair([]), pair('😀x'), rest([1, 2, 3]), duplicate([4, 5]));
  arrayAssignmentResult.push(receiver.call({}, [7]), arrow([1]).join(','));
  for (const value of [null, undefined, 2, {}]) {
    try { first(value); }
    catch (error) { arrayAssignmentResult.push(error.name); }
  }
}
