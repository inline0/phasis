function collectLoop(input, log) {
  const values = [];
  for (const value of input) {
    log.push('body:' + value);
    values.push(value);
  }
  return values;
}
function stopLoop(input, log) {
  const values = [];
  for (const value of input) {
    log.push('body:' + value);
    values.push(value);
    if (value === 2) break;
  }
  return values;
}
function continueLoop(input, log) {
  const values = [];
  for (let value of input) {
    if (value === 2) continue;
    value += 10;
    log.push('body:' + value);
    values.push(value);
  }
  return values;
}
function returnLoop(input, log) {
  for (const value of input) {
    log.push('body:' + value);
    return value;
  }
  return 'empty';
}
function throwLoop(input, log) {
  for (const value of input) {
    log.push('body:' + value);
    throw new RangeError('body');
  }
}
function identityThrowLoop(input, marker) {
  for (const value of input) { throw marker; }
}
function nestedLoop(outer, inner, log) {
  for (const first of outer) {
    for (const second of inner) {
      log.push('body:' + first + ':' + second);
      return first + second;
    }
  }
  return 0;
}
function varCaptureLoop(input) {
  const readers = [];
  for (var value of input) readers.push(function () { return value; });
  return [readers.map(function (reader) { return reader(); }), value];
}
function letCaptureLoop(input) {
  const readers = [];
  for (let value of input) readers.push(function () { return value; });
  return readers.map(function (reader) { return reader(); });
}
function outsideLoop(input) {
  for (const hidden of input) {}
  return typeof hidden;
}
function headerTdzLoop() {
  for (let item of [item]) {}
}
function shadowLoop(item) {
  for (const item of [1, 2]) {}
  return item;
}
function blockOutsideLoop(input) {
  for (const value of input) { let hidden = value; }
  return typeof hidden;
}
function resetLetLoop(input) {
  const result = [];
  for (const value of input) {
    let temporary;
    if (value === 1) temporary = 7;
    result.push(temporary);
  }
  return result;
}
function catchCaptureLoop(input) {
  const readers = [];
  for (const value of input) {
    try { throw value; }
    catch (error) {
      for (var error of [value]) {}
      readers.push(function () { return error; });
    }
  }
  return readers.map(function (reader) { return reader(); });
}
function classOutsideLoop(input) {
  for (const value of input) { class Hidden {} }
  return typeof Hidden;
}
function finalizerLoop(input, log) {
  try {
    for (const value of input) {
      try {
        return value;
      } finally {
        log.push('inner finally');
      }
    }
  } finally {
    log.push('outer finally');
  }
}
function catchLoop(input, log) {
  try {
    for (const value of input) {
      try {
        if (value === 1) throw new Error('caught inside');
      } catch (inner) {
        log.push('inner:' + inner.name);
      }
      if (value === 2) throw new RangeError('caught outside');
    }
  } catch (outer) {
    log.push('outer:' + outer.name);
  }
  return 'finished';
}
function loopIterable(log, mode, prefix) {
  let index = 0;
  const iterator = {
    get next() {
      log.push(prefix + 'get next');
      return function () {
        log.push(prefix + 'next:' + index);
        if (mode === 'next throws') throw new SyntaxError('next');
        if (mode === 'next primitive') return 1;
        return {
          get done() {
            log.push(prefix + 'done:' + index);
            if (mode === 'done throws') throw new SyntaxError('done');
            return index >= 3;
          },
          get value() {
            log.push(prefix + 'value:' + index);
            if (mode === 'value throws') throw new SyntaxError('value');
            return ++index;
          },
        };
      };
    },
    get return() {
      log.push(prefix + 'get return');
      if (mode === 'return getter throws') throw new TypeError('return getter');
      if (mode === 'return missing') return undefined;
      if (mode === 'return null') return null;
      if (mode === 'return noncallable') return 1;
      return function () {
        log.push(prefix + 'return');
        if (mode === 'return throws') throw new TypeError('return');
        if (mode === 'return primitive') return 1;
        return {};
      };
    },
  };
  return {
    [Symbol.iterator]() {
      log.push(prefix + 'iterator');
      return iterator;
    },
  };
}
function observeLoop(action) {
  try { return ['return', action()]; }
  catch (error) { return ['throw', error.name]; }
}
const forOfResult = [];
for (const operation of [collectLoop, stopLoop, continueLoop, returnLoop, throwLoop, finalizerLoop, catchLoop]) {
  for (const mode of ['normal', 'next throws', 'next primitive', 'done throws', 'value throws',
    'return missing', 'return null', 'return noncallable', 'return getter throws', 'return throws', 'return primitive']) {
    const log = [];
    const input = loopIterable(log, mode, '');
    const result = observeLoop(function () { return operation(input, log); });
    forOfResult.push([operation.name, mode, result, log]);
  }
}
for (const mode of ['normal', 'return getter throws', 'return primitive']) {
  const log = [];
  const result = observeLoop(function () {
    return nestedLoop(loopIterable(log, 'normal', 'outer '), loopIterable(log, mode, 'inner '), log);
  });
  forOfResult.push(['nested', mode, result, log]);
}
forOfResult.push(varCaptureLoop([1, 2, 3]), letCaptureLoop([1, 2, 3]), outsideLoop([1]),
  observeLoop(headerTdzLoop), shadowLoop(9), blockOutsideLoop([1]), resetLetLoop([1, 2]),
  catchCaptureLoop([1, 2]), classOutsideLoop([1]));
forOfResult.push(collectLoop('a\u{1F600}\uD800', []).map(function (value) {
  return [value.length, value.charCodeAt(0), value.charCodeAt(1)];
}), collectLoop(new Set([1, 2]), []));
const originalLoopThrow = {};
const closeLoopThrow = {};
const identityIterator = {
  [Symbol.iterator]() { return this; },
  next() { return {done: false, value: 1}; },
  return() { throw closeLoopThrow; },
};
try { identityThrowLoop(identityIterator, originalLoopThrow); }
catch (error) { forOfResult.push(['original throw identity', error === originalLoopThrow]); }
try { returnLoop(identityIterator, []); }
catch (error) { forOfResult.push(['close throw identity', error === closeLoopThrow]); }
const reentrantLog = [];
const reentrantIterator = {
  [Symbol.iterator]() { return this; },
  next() { return {done: false, value: 41}; },
  return() {
    reentrantLog.push('outer return');
    const inner = returnLoop(loopIterable(reentrantLog, 'normal', 'inner '), reentrantLog);
    reentrantLog.push('inner result:' + inner);
    return {};
  },
};
forOfResult.push(['reentrant', returnLoop(reentrantIterator, reentrantLog), reentrantLog]);
