'use strict';

var finallyTrace = [];
function complete(mode) {
  let value = 1;
  try {
    finallyTrace.push('try:' + mode);
    if (mode === 0) return value;
    if (mode === 1) return;
    if (mode === 2) throw undefined;
    if (mode >= 3) throw 'initial';
  } catch (error) {
    finallyTrace.push('catch:' + String(error));
    if (mode === 2) return error;
    if (mode === 3) return 'caught';
    throw 'replacement';
  } finally {
    finallyTrace.push('finally:' + mode);
    value = 2;
    if (mode === 5) return 'override';
    if (mode === 6) throw 'final';
  }
  return value;
}
function nested(mode) {
  try {
    try {
      return 'initial';
    } finally {
      finallyTrace.push('inner');
      if (mode === 0) return 'inner';
      if (mode === 1) throw 'inner';
    }
  } finally {
    finallyTrace.push('outer');
    if (mode === 2) return 'outer';
  }
}
function outerCatch() {
  try {
    try { return 'saved'; }
    catch (inner) { return 'wrong catch'; }
    finally { throw 'from finally'; }
  } catch (outer) {
    finallyTrace.push('outer catch');
    return outer;
  } finally {
    finallyTrace.push('outer cleanup');
  }
}
function returnExpression(object) {
  try { return object.value; }
  catch (error) { return error; }
  finally { finallyTrace.push('expression cleanup'); }
}
function looped() {
  let sum = 0;
  for (let i = 0; i < 4; i++) {
    try {
      if (i === 3) return sum;
      sum += i;
    } finally {
      finallyTrace.push('loop:' + i);
    }
  }
}
function finallyTail(count, result) {
  try { throw 'discarded'; }
  finally {
    if (count === 0) return result;
    return finallyTail(count - 1, result + 1);
  }
}
var finallyCompletionResult = [];
for (let repeat = 0; repeat < 2; repeat++) {
  for (let mode = -1; mode <= 6; mode++) {
    try { finallyCompletionResult.push(['return', String(complete(mode))]); }
    catch (error) { finallyCompletionResult.push(['throw', String(error)]); }
  }
  for (let mode = 0; mode < 3; mode++) {
    try { finallyCompletionResult.push(['nested return', nested(mode)]); }
    catch (error) { finallyCompletionResult.push(['nested throw', error]); }
  }
  finallyCompletionResult.push(outerCatch());
  finallyCompletionResult.push(returnExpression({get value() { throw 'getter'; }}));
  finallyCompletionResult.push(returnExpression({value: 9}));
  finallyCompletionResult.push(looped(), finallyTail(10, 0));
}
finallyCompletionResult.push(finallyTrace);
