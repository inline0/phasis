var stringIteratorStorage = (() => {
    const units = value => {
        const result = [];
        for (let i = 0; i < value.length; i++) result.push(value.charCodeAt(i));
        return result;
    };
    function spread(value) { return [...value].map(units); }
    function astSpread(value) { with ({}) {} return [...value].map(units); }
    function collect(value) { const result = []; for (const item of value) result.push(units(item)); return result; }
    const result = [];
    for (const value of ['', 'a', 'A\u{1F600}B', '\uD800', '\uDC00', '\uD800x\uDC00', '\uDBFF\uDFFF', '\uD800\uD800\uDC00']) {
        result.push([spread(value), astSpread(value), collect(value)]);
    }
    const iterator = 'ab'[Symbol.iterator]();
    const first = iterator.next();
    const second = iterator.next();
    const third = iterator.next();
    result.push([first.value, first.done, second.value, second.done, third.value === undefined, third.done,
        iterator.next().done, first !== second, Object.keys(first), Object.getOwnPropertyDescriptor(first, 'value')]);
    const prototype = Object.getPrototypeOf(iterator);
    const next = prototype.next;
    result.push([Object.getOwnPropertyNames(iterator), Object.prototype.toString.call(iterator)]);
    for (const receiver of [null, undefined, {}, Object.create(prototype), new Proxy('x'[Symbol.iterator](), {})]) {
        try { next.call(receiver); result.push('no error'); } catch (error) { result.push(error instanceof TypeError); }
    }
    const log = [];
    const source = 'abcd'[Symbol.iterator]();
    Object.defineProperty(source, 'next', { get() { log.push('next getter'); return next; }});
    source.return = function () { log.push('return'); return {}; };
    const iterable = { [Symbol.iterator]() { log.push('iterator'); return source; }};
    const target = { set value(value) { log.push(value); prototype.next = function () { throw new Error('new next'); }; }};
    function pair(value, target) { [target.value, target.value] = value; }
    try { pair(iterable, target); } finally { prototype.next = next; }
    result.push(log);
    const originalIterator = String.prototype[Symbol.iterator];
    Object.defineProperty(String.prototype, Symbol.iterator, { configurable: true, get() {
        log.push(typeof this + ':' + String(this));
        return new Proxy(function () { log.push('factory:' + typeof this); return originalIterator.call(this); }, {
            apply(target, receiver, args) { log.push('apply'); return Reflect.apply(target, receiver, args); }
        });
    }});
    try { result.push(spread('xy')); } finally {
        Object.defineProperty(String.prototype, Symbol.iterator, { value: originalIterator, writable: true, configurable: true });
    }
    result.push(log.slice());
    prototype.next = function () { log.push('custom next'); return { value: 'replacement', done: false }; };
    try { const [value] = 'x'; result.push(value); } finally { prototype.next = next; }
    const proxyIterator = 'pq'[Symbol.iterator]();
    const proxyLog = [];
    proxyIterator.next = new Proxy(next, { apply(target, receiver, args) {
        proxyLog.push(receiver === proxyIterator);
        return Reflect.apply(target, receiver, args);
    }});
    proxyIterator.return = function () { proxyLog.push('return'); return {}; };
    const [proxyValue] = { [Symbol.iterator]() { return proxyIterator; }};
    result.push([proxyValue, proxyLog]);
    const failureLog = [];
    const failing = 'x'[Symbol.iterator]();
    failing.next = function () { failureLog.push('next'); throw new Error('step'); };
    failing.return = function () { failureLog.push('return'); return {}; };
    try { spread({ [Symbol.iterator]() { return failing; }}); } catch (error) { failureLog.push(error.message); }
    result.push(failureLog);
    const coercionLog = [];
    const sourceObject = { [Symbol.toPrimitive](hint) { coercionLog.push(hint); return 'A\u{1F600}'; }};
    const coerced = originalIterator.call(sourceObject);
    sourceObject[Symbol.toPrimitive] = () => { throw new Error('coercion repeated'); };
    result.push([units(coerced.next().value), units(coerced.next().value), coerced.next().done, coercionLog]);
    const frozen = Object.freeze('rs'[Symbol.iterator]());
    Object.setPrototypeOf(frozen, Object.getPrototypeOf(frozen));
    result.push([next.call(frozen).value, next.call(frozen).value, next.call(frozen).done]);
    return result;
})();
if (typeof process !== 'undefined') process.stdout.write(JSON.stringify(stringIteratorStorage));
