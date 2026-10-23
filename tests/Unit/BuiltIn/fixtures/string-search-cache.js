function cachedSearch(value, needle, position) {
  return value.indexOf(needle, position);
}
var searchResults = [];
var values = ['', 'aabaa', 'A\0B', 'A😀B😀', '\ud800\u6100b', '\udfff', '\ud83d', '\ude00', '\u0000\u0001', 'undefined'];
var needles = ['', 'a', '\0', 'B', '😀', '\ud83d', '\ude00', '\ud800', '\udfff', '\u6261', '\u0100', 'undefined'];
var positions = [undefined, null, NaN, -Infinity, Infinity, -7, -0, 0, 1, 1.9, 3, 999];
for (var round = 0; round < 2; round++) {
  for (var value of values) {
    for (var needle of needles) {
      for (var position of positions) searchResults.push(cachedSearch(value, needle, position));
    }
  }
  var log = [];
  var receiver = { toString() { log.push('receiver'); return 'cabca'; } };
  var needle = { toString() { log.push('needle'); receiver.toString = () => ''; return 'a'; } };
  var position = { valueOf() { log.push('position'); needle.toString = () => 'b'; return 1; } };
  searchResults.push(String.prototype.indexOf.call(receiver, needle, position), log.slice());
  log = [];
  searchResults.push(String.prototype.indexOf.call(receiver, needle, position), log.slice());
  var boxed = new String('cabca');
  boxed.toString = function () { log.push('boxed'); return '😀x😀'; };
  searchResults.push(String.prototype.indexOf.call(boxed, '\ude00', 1), log.slice());
  var original = String.prototype.indexOf;
  var replacementNeedle = { toString() { String.prototype.indexOf = () => 42; return 'a'; } };
  try {
    searchResults.push(cachedSearch('cabca', replacementNeedle, 0), cachedSearch('cabca', 'a', 0));
  } finally { String.prototype.indexOf = original; }
  for (var bad of [Symbol('needle'), { [Symbol.toPrimitive]() { return Symbol('needle'); } }]) {
    try { cachedSearch('abc', bad, 0); } catch (error) { searchResults.push(error.name); }
  }
}
var wrapperCases = [
  ['charAt', 2], ['charCodeAt', 2], ['indexOf', 'A'], ['lastIndexOf', 'A'],
  ['includes', 'A'], ['startsWith', ' '], ['endsWith', ' '], ['slice', 1, 4],
  ['substring', 1, 4], ['concat', '!'], ['at', 2], ['codePointAt', 2],
  ['toLowerCase'], ['toUpperCase'], ['trim'], ['trimStart'], ['trimEnd'],
  ['repeat', 2], ['padStart', 10, '-'], ['padEnd', 10, '-'], ['normalize'],
  ['isWellFormed'], ['toWellFormed'], ['substr', 1, 3], ['anchor', 'target'],
  ['split', 'A'], ['replace', 'A', 'Z'], ['replaceAll', 'A', 'Z']
];
var wrapperResults = [];
for (var primitive of ['ignored', 42, true]) {
  var wrapper = Object(primitive);
  wrapper.toString = function () { return ' A😀A '; };
  for (var entry of wrapperCases) {
    wrapperResults.push([typeof primitive, entry[0], String.prototype[entry[0]].apply(wrapper, entry.slice(1))]);
  }
  wrapper[Symbol.toPrimitive] = function (hint) {
    if (hint !== 'string') throw new Error('Wrong coercion hint');
    return ' A😀A ';
  };
  for (var entry of wrapperCases) {
    wrapperResults.push([typeof primitive, entry[0], String.prototype[entry[0]].apply(wrapper, entry.slice(1))]);
  }
}
var fakeSlotReads = 0;
var ordinary = { toString() { return 'correct'; } };
Object.defineProperty(ordinary, '[[PrimitiveValue]]', { get() { fakeSlotReads++; return 'incorrect'; } });
wrapperResults.push(String.prototype.indexOf.call(ordinary, 'correct'), fakeSlotReads);
globalThis.stringSearchCacheResult = { searches: searchResults, wrappers: wrapperResults };
