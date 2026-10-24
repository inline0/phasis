var nativeAsciiObservations = [];
function nativeAsciiIndex(value, search, position) { return value.indexOf(search, position); }
function nativeAsciiSlice(value, start, end) { return value.slice(start, end); }
function nativeAsciiUnits(value) {
    const units = [];
    for (let i = 0; i < value.length; i++) units.push(value.charCodeAt(i));
    return units;
}
const positions = [-Infinity, -2.8, -0, 0, 1.9, 2, 99, Infinity, NaN, undefined];
for (const pair of [['', ''], ['abcabc', 'bc'], ['abcabc', ''], ['a\0b', '\0'],
    ['A\uD83D\uDE00B', '\uDE00'], ['A\uD83D\uDE00B', 'B'], ['\uD800x\uDC00', '\uD800'], ['éa', 'a']]) {
    for (const position of positions) nativeAsciiObservations.push(nativeAsciiIndex(pair[0], pair[1], position));
}
nativeAsciiObservations.push('undefined'.indexOf(), ''.indexOf());
for (const value of ['', 'ab\0cd\x7f', 'A\uD83D\uDE00B', '\uD800x\uDC00', 'éa']) {
    for (const start of [-Infinity, -2.8, -0, 1.2, 99, Infinity, NaN, undefined]) {
        for (const end of [undefined, -Infinity, -1.2, 0, 2.8, 99, Infinity, NaN]) {
            nativeAsciiObservations.push(nativeAsciiUnits(nativeAsciiSlice(value, start, end)));
        }
    }
}
let effects = [];
function coerced(label, value) {
    return {[Symbol.toPrimitive](hint) {effects.push(label + ':' + hint); return value;}};
}
let result = String.prototype.indexOf.call(coerced('receiver', 'abc'), coerced('search', 'b'), coerced('position', 1));
nativeAsciiObservations.push(['index coercions', result, effects]);
effects = [];
result = nativeAsciiIndex('abc', 'b', coerced('position', 1));
nativeAsciiObservations.push(['ASCII index coercion', result, effects]);
effects = [];
result = String.prototype.slice.call(coerced('receiver', 'abc'), coerced('start', 1), coerced('end', 2));
nativeAsciiObservations.push(['slice coercions', nativeAsciiUnits(result), effects]);
effects = [];
result = nativeAsciiSlice('abc', coerced('start', 1), coerced('end', 2));
nativeAsciiObservations.push(['ASCII slice coercions', nativeAsciiUnits(result), effects]);
for (const kind of ['index null', 'index symbol', 'index bigint', 'slice null', 'slice symbol', 'slice bigint']) {
    effects = [];
    let errorName = null;
    try {
        if (kind === 'index null') String.prototype.indexOf.call(null, coerced('search', 'a'), coerced('position', 0));
        if (kind === 'index symbol') nativeAsciiIndex('abc', Symbol(), coerced('position', 0));
        if (kind === 'index bigint') nativeAsciiIndex('abc', coerced('search', 'a'), 1n);
        if (kind === 'slice null') String.prototype.slice.call(null, coerced('start', 0), coerced('end', 1));
        if (kind === 'slice symbol') nativeAsciiSlice('abc', Symbol(), coerced('end', 1));
        if (kind === 'slice bigint') nativeAsciiSlice('abc', coerced('start', 0), 1n);
    } catch (error) {errorName = error.name;}
    nativeAsciiObservations.push([kind, errorName, effects]);
}
