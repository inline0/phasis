const regexpReplaceResult = [];
function recordReplace(regexp, input, replacement) {
  const result = regexp[Symbol.replace](input, replacement);
  regexpReplaceResult.push([result, regexp.lastIndex,
    RegExp.input, RegExp.lastMatch, RegExp.leftContext, RegExp.rightContext,
    RegExp.lastParen, RegExp.$1, RegExp.$2]);
}

for (const regexp of [/[-.+?()[\]{}|\\]/g, /a/, /a/g, /a/gi, /a/dg, /a/gu,
    /(?:ab|cd)+/g, /a(?=b)/g, /a(?!c)/g, /z/g, /^a/mg]) {
  for (const replacement of ['X', '\\$&', '$$|$&|$`|$\'|$1|$01|$<name>', '']) {
    /(seed)(value)/.exec('seedvalue');
    regexp.lastIndex = 4;
    recordReplace(regexp, 'a.ab-cd+a\na[b]\\', replacement);
  }
}

for (const [regexp, input] of [[/a*/g, 'abc'], [/(?:)/g, 'ab'], [/(a)?b/g, 'bab'],
    [/(?<name>a)/g, 'aab'], [/(?:a?)*b/g, 'aab'], [/./gu, 'a😀b'],
    [/./g, '\ud800a'], [/a/y, 'ba'], [/a/gy, 'aa-ba']]) {
  recordReplace(regexp, input, '[$&][$1][$<name>]');
}

for (const index of [-0, NaN, Infinity, -4, 3]) {
  for (const regexp of [/a/, /a/g]) {
    regexp.lastIndex = index;
    const result = 'aba'.replace(regexp, 'x');
    regexpReplaceResult.push([result, Object.is(regexp.lastIndex, index), Object.is(regexp.lastIndex, -0)]);
  }
}

const regexpReplaceTrace = [];
const getterExec = /a/g;
const intrinsicExec = RegExp.prototype.exec;
Object.defineProperty(getterExec, 'exec', {
  get() { regexpReplaceTrace.push('exec getter'); return intrinsicExec; }
});
recordReplace(getterExec, 'aba', '$&$&');

const customExec = /a/g;
let customCalls = 0;
customExec.exec = function (input) {
  regexpReplaceTrace.push(['exec call', input, this === customExec]);
  return customCalls++ === 0 ? {0: 'ab', 1: 'capture', length: 2, index: 1} : null;
};
recordReplace(customExec, 'zabz', '$1:$&');

const prototypeProxy = new Proxy(RegExp.prototype, {
  get(target, key, receiver) {
    regexpReplaceTrace.push('get ' + String(key));
    return Reflect.get(target, key, receiver);
  },
  getOwnPropertyDescriptor(target, key) {
    regexpReplaceTrace.push('descriptor ' + String(key));
    return Reflect.getOwnPropertyDescriptor(target, key);
  },
  getPrototypeOf(target) {
    regexpReplaceTrace.push('prototype');
    return Reflect.getPrototypeOf(target);
  }
});
const proxyChain = /a/g;
// Node 26.12 reads sticky before unicodeSets, unlike ECMA-262's getter order.
// Keep this oracle focused on exec traps; flag getter order is tested separately.
Object.defineProperty(proxyChain, 'flags', {value: 'g'});
Object.setPrototypeOf(proxyChain, prototypeProxy);
const proxyResult = RegExp.prototype[Symbol.replace].call(proxyChain, 'aba', '$&$&');
regexpReplaceResult.push([proxyResult, proxyChain.lastIndex]);

const indexObject = /a/;
indexObject.lastIndex = {valueOf() { regexpReplaceTrace.push('lastIndex'); return 17; }};
recordReplace(indexObject, 'aba', 'x');

for (const regexp of [/a/, /a/g]) {
  Object.defineProperty(regexp, 'lastIndex', {writable: false});
  try { recordReplace(regexp, 'aba', 'x'); }
  catch (error) { regexpReplaceResult.push(error.name); }
}

const flagOverride = /a/;
Object.defineProperty(flagOverride, 'flags', {
  get() { regexpReplaceTrace.push('flags getter'); return 'g'; }
});
let overriddenCalls = 0;
flagOverride.exec = function (input) {
  regexpReplaceTrace.push('overridden exec');
  return overriddenCalls++ === 0 ? {0: 'a', length: 1, index: 0} : null;
};
recordReplace(flagOverride, 'aba', 'x');

const recompiled = /a/g;
recordReplace(recompiled, {
  toString() { regexpReplaceTrace.push('input conversion'); return 'aba'; }
}, {
  toString() {
    regexpReplaceTrace.push('replacement conversion');
    recompiled.compile('b', 'g');
    return '$&$&';
  }
});

const functional = /a/g;
const functionalOutput = 'aba'.replace(functional, function (match, index, input) {
  regexpReplaceTrace.push(['replacement callback', match, index, input, functional.lastIndex, RegExp.lastMatch]);
  /z/.exec('z');
  functional.exec = function () { throw new Error('results must already be collected'); };
  return index;
});
regexpReplaceResult.push(functionalOutput);
regexpReplaceResult.push(regexpReplaceTrace);

function encodeReplaceSurrogates(value) {
  if (Array.isArray(value)) return value.map(encodeReplaceSurrogates);
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
for (let index = 0; index < regexpReplaceResult.length; index++) {
  regexpReplaceResult[index] = encodeReplaceSurrogates(regexpReplaceResult[index]);
}
