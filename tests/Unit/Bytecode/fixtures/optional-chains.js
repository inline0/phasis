'use strict';

function property(value) { return value?.x; }
function computed(value, trace) { return value?.[(trace.push('key'), 'x')]; }
function method(value, trace) { return value?.m((trace.push('argument'), 3)); }
function optionalMethod(value, trace) { return value.m?.((trace.push('argument'), 4)); }
function direct(value, trace) { return value?.((trace.push('argument'), 5)); }
function continuation(value, trace) { return value?.x[(trace.push('key'), 'y')].m((trace.push('argument'), 6)); }
function callContinuation(value, trace) { return value.m?.((trace.push('argument'), 7)).x; }
function groupedProperty(value) { return (value?.x).y; }
function groupedMethod(value, trace) { return (value?.m)((trace.push('argument'), 8)); }
function groupedOptionalMethod(value, trace) { return (value?.m)?.((trace.push('argument'), 9)); }
function computedMethod(value, trace) { return value?.[(trace.push('key'), 'm')]?.((trace.push('argument'), 10)); }
function nested(value, trace) {
  function collect(a, b, c) { return [a, b, c]; }
  return collect('before', [value?.x, (trace.push('middle'), value?.m?.(1))], 'after');
}
function spread(value, iterable) { return value?.m?.(...iterable); }
function tail(count, value) { return count ? tail?.(count - 1, value + 1) : value; }
function tailUndefined(value) { return value?.m?.(); }
function normalized(value) { return [value?.x === undefined, tailUndefined(value) === undefined, Object.is(value?.x, undefined)]; }
function protectedCall(value, trace) {
  try { return value?.m(); }
  catch (error) { trace.push(error); return trace?.join(':'); }
}

function makeObject(mode, trace) {
  const object = {
    tag: 'receiver',
    get x() {
      trace.push('get x');
      return mode === 'missing x' ? undefined : {
        y: { tag: 'child', m(...args) { trace.push('child call:' + this.tag); return args; } }
      };
    },
    get m() {
      trace.push('get m');
      if (mode === 'missing m') return undefined;
      if (mode === 'noncallable') return 1;
      if (mode === 'getter') throw 'getter failure';
      if (mode === 'native') return Array.isArray;
      const method = function (...args) {
        trace.push('call:' + (this === object));
        if (mode === 'call') throw 'call failure';
        if (mode === 'return object') return { x: args.join(',') };
        return [this === object, args];
      };
      return mode === 'proxy' ? new Proxy(method, {}) : method;
    }
  };
  return object;
}

var optionalChainResult = [];
for (let repeat = 0; repeat < 3; repeat++) {
  for (const test of [
    [property, 'null'], [property, 'undefined'], [computed, 'null'], [computed, 'normal'],
    [method, 'null'], [method, 'normal'], [method, 'missing m'], [method, 'getter'],
    [method, 'native'], [method, 'proxy'],
    [optionalMethod, 'normal'], [optionalMethod, 'missing m'], [optionalMethod, 'null'], [optionalMethod, 'noncallable'],
    [continuation, 'null'], [continuation, 'normal'], [continuation, 'missing x'],
    [callContinuation, 'missing m'], [callContinuation, 'return object'],
    [groupedProperty, 'null'], [groupedProperty, 'missing x'],
    [groupedMethod, 'null'], [groupedMethod, 'normal'],
    [groupedOptionalMethod, 'null'], [groupedOptionalMethod, 'normal'], [groupedOptionalMethod, 'missing m'],
    [computedMethod, 'null'], [computedMethod, 'normal'], [computedMethod, 'missing m'],
    [nested, 'null'], [protectedCall, 'call'], [protectedCall, 'null'], [normalized, 'null']
  ]) {
    const trace = [];
    const input = test[1] === 'null' ? null : test[1] === 'undefined' ? undefined : makeObject(test[1], trace);
    try { optionalChainResult.push([test[0].name, test[1], test[0](input, trace), trace]); }
    catch (error) { optionalChainResult.push([test[0].name, test[1], error instanceof Error ? error.name : error, trace]); }
  }
  for (const value of [null, undefined, 1, function (number) { return [this === undefined, number]; }]) {
    const trace = [];
    try { optionalChainResult.push(['direct', direct(value, trace), trace]); }
    catch (error) { optionalChainResult.push(['direct', error.name, trace]); }
  }
  for (const mode of ['null', 'missing m', 'normal']) {
    const trace = [];
    const iterable = { *[Symbol.iterator]() { trace.push('iterate'); yield 1; yield 2; } };
    optionalChainResult.push(['spread', mode, spread(mode === 'null' ? null : makeObject(mode, trace), iterable), trace]);
  }
  optionalChainResult.push(['tail', tail(20, 0)]);
  optionalChainResult.push(['primitive', property(false), computed('', []), 'abc'?.slice?.(1)]);
}
