'use strict';

function first(value) { const [a] = value; return a; }
function pair(value) { let [a, b] = value; return [a, b]; }
function elided(value) { const [, a] = value; return a; }
function empty(value) { const [] = value; return 'empty'; }
function rest(value) { const [a, ...remaining] = value; return [a, remaining]; }
function duplicate(value) { var [a, a] = value; return a; }
function captured(value) {
  function observe() { return a; }
  value.observe = observe;
  const [a, b] = value;
  return [a, b, observe()];
}
function makeIterable(mode, trace) {
  let step = 0;
  const source = {
    [Symbol.iterator]() {
      trace.push('iterator');
      return {
        get next() {
          trace.push('get next');
          return function () {
            step++;
            trace.push('next:' + step);
            if (source.observe) {
              try { trace.push('observe:' + source.observe()); }
              catch (error) { trace.push('observe:' + error.name); }
            }
            if (mode === 'next') throw 'next failure';
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
        },
        get return() {
          trace.push('get return');
          if (mode === 'return getter') throw 'return getter failure';
          if (mode === 'proxy') return new Proxy(function () { trace.push('proxy return'); return {}; }, {});
          return function () {
            trace.push('return');
            if (mode === 'return value') return 1;
            return {};
          };
        }
      };
    }
  };
  return source;
}
var arrayBindingResult = [];
for (let repeat = 0; repeat < 2; repeat++) {
  for (const test of [
    [first, 'normal'], [pair, 'normal'], [elided, 'hole'], [empty, 'normal'],
    [rest, 'normal'], [duplicate, 'normal'], [captured, 'normal'],
    [first, 'next'], [first, 'done'], [first, 'value'], [first, 'proxy'],
    [first, 'return getter'], [first, 'return value']
  ]) {
    const trace = [];
    try { arrayBindingResult.push([test[0].name, test[1], test[0](makeIterable(test[1], trace)), trace]); }
    catch (error) { arrayBindingResult.push([test[0].name, test[1], error instanceof Error ? error.name : String(error), trace]); }
  }
  arrayBindingResult.push(pair([]), pair('😀x'), rest([1, 2, 3]), duplicate([4, 5]));
  for (const value of [null, undefined, 2, {}]) {
    try { first(value); }
    catch (error) { arrayBindingResult.push(error.name); }
  }
}
