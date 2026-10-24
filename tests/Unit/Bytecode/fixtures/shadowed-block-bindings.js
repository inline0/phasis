const shadowedBlockResult = [];
function literalKeys() {
  function read(record) { return {value: record.value}.value; }
  let result = 0;
  for (var index = 0; index < 3; index++) {
    const value = index;
    result += read({value});
  }
  return result;
}
function innerLexical() {
  function read(values) {
    let result = 0;
    while (values.length) {
      const token = values.pop();
      result += token;
    }
    return result;
  }
  let result = 0;
  for (var index = 0; index < 3; index++) {
    const token = index;
    result += token + read([3, 5]);
  }
  return result;
}
function innerDeadZone() {
  function read() {
    try { return token; } catch (error) { return error.name; }
    const token = 9;
  }
  let result = '';
  for (var index = 0; index < 2; index++) {
    const token = index;
    result += token + ':' + read() + ';';
  }
  return result;
}
function mixedCapture() {
  var saved;
  for (var index = 0; index < 3; index++) {
    const token = index;
    saved = () => {
      if (false) { const token = 99; return token; }
      return token;
    };
  }
  return saved();
}
function defaultCapture() {
  var saved;
  for (var index = 0; index < 3; index++) {
    const token = index;
    saved = function read(value = token) { const token = 99; return value; };
  }
  return saved();
}
function computedCapture() {
  var saved;
  for (var index = 0; index < 3; index++) {
    const token = index;
    saved = () => ({[token]: 7});
  }
  return Object.keys(saved());
}
function shorthandCapture() {
  var saved;
  for (var index = 0; index < 3; index++) {
    const token = index;
    saved = () => ({token});
  }
  return saved().token;
}
function nestedClassEval() {
  var Saved;
  for (var index = 0; index < 3; index++) {
    const token = index;
    Saved = class {
      read() {
        if (false) { const token = 8; }
        return eval('token');
      }
    };
  }
  return new Saved().read();
}
function nestedClassKeyEval() {
  var Saved;
  for (var index = 0; index < 3; index++) {
    const token = index;
    Saved = class { token() { return eval('token'); } };
  }
  return new Saved().token();
}
function nestedEval() {
  var saved;
  for (var index = 0; index < 3; index++) {
    const token = index;
    saved = function read() {
      if (false) { const token = 8; }
      return eval('token');
    };
  }
  return saved();
}
function nestedWith() {
  var saved;
  for (var index = 0; index < 3; index++) {
    const token = index;
    saved = function read() {
      if (false) { const token = 8; }
      with ({}) { return token; }
    };
  }
  return saved();
}
function throwShadowValue() { throw 'inner'; }
function innerThrow() {
  function read() { const token = throwShadowValue(); return token; }
  let result = '';
  for (var index = 0; index < 2; index++) {
    const token = index;
    try { read(); } catch (error) { result += token + ':' + error + ';'; }
  }
  return result;
}
function siblingBlocks() {
  function read(enabled) {
    if (enabled) { const token = 3; return () => token; }
    { let token = 7; return () => ++token; }
  }
  let result = 0;
  for (var index = 0; index < 2; index++) {
    const token = index;
    const first = read(true);
    const second = read(false);
    result += token + first() + second() + second();
  }
  return result;
}
for (let pass = 0; pass < 3; pass++) {
  shadowedBlockResult.push(literalKeys(), innerLexical(), innerDeadZone(), mixedCapture(), defaultCapture(),
    computedCapture(), shorthandCapture(), nestedClassEval(), nestedClassKeyEval(), nestedEval(), nestedWith(),
    innerThrow(), siblingBlocks());
}
