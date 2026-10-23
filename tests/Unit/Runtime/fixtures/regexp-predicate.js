const regexpPredicateResult = [];
const regexpPredicateTrace = [];
function recordPredicate(regexp, input) {
  const value = regexp.test(input);
  recordPredicateState(regexp, value);
}
function recordPredicateState(regexp, value) {
  regexpPredicateResult.push([value, regexp.lastIndex,
    RegExp.input, RegExp.lastMatch, RegExp.leftContext, RegExp.rightContext,
    RegExp.lastParen, RegExp.$1, RegExp.$2]);
}
for (const regexp of [/a/, /(a)(b)/, /(?<first>a)(b)/, /a(?=b)/, /a(?!c)/,
    /(?:ab|cd)/, /[a-z]+/i, /α/u, /a/d, /a/g, /a/y]) {
  for (const input of ['zabz', 'abc', 'z', 'αa', '😀a', '']) {
    /(seed)(value)/.exec('seedvalue');
    regexp.lastIndex = 0;
    recordPredicate(regexp, input);
  }
}
for (const [regexp, input] of [[/./, 'a😀b'], [/./u, 'a😀b'], [/./g, '😀'],
    [/(a(b)?)+/, 'zabaaz'], [/(?<=(a))b/, 'zabz'], [/(?:(a)|b)+/, 'zabz'],
    [/[\ud800-\udfff]/u, 'a\ud800b'], [/^\p{Any}+$/u, '\ud800a'],
    [/(a)()/, 'a'], [/(a)|(b)/, 'a'], [/(a)(b)?/, 'a']]) {
  /(seed)(value)/.exec('seedvalue');
  recordPredicateState(regexp, regexp.exec(input) !== null);
  regexp.lastIndex = 0;
  recordPredicate(regexp, input);
  regexp.lastIndex = 0;
  recordPredicate(regexp, 'zzz');
}
for (const index of [-0, NaN, Infinity, -4, 3]) {
  const regexp = /a/;
  regexp.lastIndex = index;
  regexpPredicateResult.push([regexp.test('aba'), Object.is(regexp.lastIndex, index),
    Object.is(regexp.lastIndex, -0)]);
}

const recompiledDuringIndex = /a/;
recompiledDuringIndex.lastIndex = {
  valueOf() {
    regexpPredicateTrace.push('lastIndex coercion');
    recompiledDuringIndex.compile('b', '');
    return 17;
  }
};
recordPredicate(recompiledDuringIndex, 'b');

const inputChangesMatcher = /a/;
recordPredicate(inputChangesMatcher, {
  toString() {
    regexpPredicateTrace.push('input coercion');
    inputChangesMatcher.compile('b', '');
    return 'b';
  }
});

const builtinExec = RegExp.prototype.exec;
const getterExec = /a/;
Object.defineProperty(getterExec, 'exec', {
  get() { regexpPredicateTrace.push('exec getter'); return builtinExec; }
});
recordPredicate(getterExec, 'ba');

const customExec = /a/;
customExec.exec = function (input) {
  regexpPredicateTrace.push(['custom exec', input, this === customExec]);
  return {0: 'different', index: 0, length: 1};
};
recordPredicate(customExec, 'ba');

const proxyChain = /a/;
Object.setPrototypeOf(proxyChain, new Proxy(RegExp.prototype, {
  get(target, key, receiver) {
    regexpPredicateTrace.push('get ' + String(key));
    return Reflect.get(target, key, receiver);
  },
  getOwnPropertyDescriptor(target, key) {
    regexpPredicateTrace.push('descriptor ' + String(key));
    return Reflect.getOwnPropertyDescriptor(target, key);
  },
  getPrototypeOf(target) {
    regexpPredicateTrace.push('prototype');
    return Reflect.getPrototypeOf(target);
  }
}));
recordPredicate(proxyChain, 'ba');
regexpPredicateResult.push(regexpPredicateTrace);

function encodePredicateSurrogates(value) {
  if (Array.isArray(value)) return value.map(encodePredicateSurrogates);
  if (typeof value !== 'string') return value;
  const units = [];
  let containsSurrogate = false;
  for (let index = 0; index < value.length; index++) {
    const unit = value.charCodeAt(index);
    units.push(unit);
    if (unit >= 0xd800 && unit <= 0xdfff) containsSurrogate = true;
  }
  return containsSurrogate ? {utf16: units} : value;
}
for (let index = 0; index < regexpPredicateResult.length; index++) {
  regexpPredicateResult[index] = encodePredicateSurrogates(regexpPredicateResult[index]);
}
