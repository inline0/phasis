var switchResults = [];
function selectedCase(value, events) {
  function probe(value, label) { events.push(label); return value; }
  switch (probe(value, 'subject')) {
    case probe(1, 'test1'):
      events.push('one');
      break;
    default:
      events.push('default');
    case probe(2, 'test2'):
      events.push('two');
    case probe(3, 'test3'):
      events.push('three');
  }
  events.push('end');
  return events;
}
for (const value of [1, 2, 3, 4, undefined, null]) switchResults.push(selectedCase(value, []));

var switchSymbol = Symbol('case');
var switchObject = {};
var switchBigInt = 1n;
function strictCases(value) {
  switch (value) {
    case 1: return 'number';
    case '1': return 'string';
    case switchBigInt: return 'bigint';
    case NaN: return 'nan';
    case -0: return 'zero';
    case switchSymbol: return 'symbol';
    case switchObject: return 'object';
    default: return 'default';
  }
}
for (const value of [1, '1', 1n, NaN, -0, 0, switchSymbol, Symbol('case'), switchObject, {}]) {
  switchResults.push(strictCases(value));
}
function noDefault(value) {
  var output = 0;
  switch (value) { case 1: output = 10; break; case 2: output = 20; }
  return output;
}
switchResults.push(noDefault(0), noDefault(1), noDefault(2));

function nestedSwitches() {
  const output = [];
  for (var index = 0; index < 4; index++) {
    switch (index) {
      case 0: continue;
      case 1: break;
      case 2: {
        const value = index + 10;
        output.push(value);
        switch (value) { case 12: output.push('inner'); break; default: return ['wrong']; }
      }
      default: output.push('body');
    }
    output.push(index);
  }
  return output;
}
switchResults.push(nestedSwitches());

function forOfSwitch(input) {
  const output = [];
  for (const item of input) {
    switch (item) { case 1: continue; case 2: break; case 3: return output; }
    output.push(item);
  }
  return output;
}
const iteratorEvents = [];
const iterable = {
  [Symbol.iterator]() {
    let current = 0;
    return {
      next() { iteratorEvents.push('next'); return { value: ++current, done: current > 3 }; },
      return() { iteratorEvents.push('return'); return {}; }
    };
  }
};
switchResults.push(forOfSwitch(iterable), iteratorEvents);

function finallySwitch(value, events) {
  try {
    switch (value) { case 1: return 10; case 2: throw 'case'; default: events.push('default'); }
    return 20;
  } finally { events.push('finally'); }
}
for (const value of [0, 1, 2]) {
  const events = [];
  try { events.push(finallySwitch(value, events)); } catch (error) { events.push(error); }
  switchResults.push(events);
}

function caseBlockTdz(value) {
  switch (value) {
    case hidden: return 'wrong';
    default: let hidden = 1; return hidden;
  }
}
try { caseBlockTdz(0); } catch (error) { switchResults.push(error.name); }

function blockScopeEscape(value) {
  'use strict';
  switch (value) { case 1: { let hidden = 1; } }
  hidden = 2;
}
try { blockScopeEscape(1); } catch (error) { switchResults.push(error.name); }

function resetSwitchLet() {
  const output = [];
  for (var index = 0; index < 2; index++) {
    switch (index) { default: { let value; output.push(value === undefined); value = 1; } }
  }
  return output;
}
switchResults.push(resetSwitchLet());

function finallyContinue() {
  const output = [];
  for (var index = 0; index < 2; index++) {
    switch (index) {
      default: try { continue; } finally { output.push(index); }
    }
  }
  return output;
}
switchResults.push(finallyContinue());

function argumentEffects(events) {
  const sentinel = {};
  function fail() { events.push('case'); throw sentinel; }
  try {
    switch ((events.push('subject'), 1)) { case fail(): return 'wrong'; default: return 'wrong'; }
  } catch (error) { return error === sentinel; }
}
const argumentEvents = [];
switchResults.push(argumentEffects(argumentEvents), argumentEvents);
