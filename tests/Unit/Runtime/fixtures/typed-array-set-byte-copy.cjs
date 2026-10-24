var typedArraySetObservations = [];
function setArrayValues(target, source, offset) {
    return target.set(source, offset);
}
for (const Type of [Int8Array, Uint8Array, Uint8ClampedArray, Int16Array, Uint16Array,
    Int32Array, Uint32Array, Float16Array, Float32Array, Float64Array, BigInt64Array, BigUint64Array]) {
    const big = Type === BigInt64Array || Type === BigUint64Array;
    const convert = value => big ? BigInt(value) : value;
    const sourceBacking = new Type([8, 9, 1, 2, 3, 7].map(convert));
    const source = new Type(sourceBacking.buffer, 2 * Type.BYTES_PER_ELEMENT, 3);
    const targetBacking = new Type([6, 6, 6, 6, 6, 6, 6].map(convert));
    const target = new Type(targetBacking.buffer, Type.BYTES_PER_ELEMENT, 5);
    const result = setArrayValues(target, source, 1);
    typedArraySetObservations.push([Type.name + ' windows', Array.from(targetBacking, String),
        Array.from(source, String), result === undefined]);

    const forward = new Type([1, 2, 3, 4, 5, 6].map(convert));
    setArrayValues(forward, new Type(forward.buffer, 0, 4), 1);
    const backward = new Type([1, 2, 3, 4, 5, 6].map(convert));
    setArrayValues(backward, new Type(backward.buffer, Type.BYTES_PER_ELEMENT, 4), 0);
    setArrayValues(backward, backward, 0);
    typedArraySetObservations.push([Type.name + ' overlap', Array.from(forward, String), Array.from(backward, String)]);
}
const sourceWithProperties = new Uint8Array([4, 5, 6]);
const targetWithProperties = new Uint8Array(5);
let publicReads = 0;
for (const key of ['length', 'byteLength', 'byteOffset', 'buffer', 'constructor', Symbol.iterator]) {
    for (const view of [sourceWithProperties, targetWithProperties]) {
        Object.defineProperty(view, key, {get() {publicReads++; throw new Error('public property read');}});
    }
}
setArrayValues(targetWithProperties, sourceWithProperties, 1);
typedArraySetObservations.push(['public properties', targetWithProperties[1], targetWithProperties[2],
    targetWithProperties[3], publicReads]);
const payload = new Uint32Array([0x7fc01234, 0x7fa12345, 0x80000000]);
const floatTarget = new Float32Array(3);
setArrayValues(floatTarget, new Float32Array(payload.buffer), 0);
typedArraySetObservations.push(['float32 bits', Array.from(new Uint32Array(floatTarget.buffer))]);
const overlapPayload = new Uint32Array([0x7fa12345, 0x7fc01234, 0x80000000, 0]);
const overlapFloats = new Float32Array(overlapPayload.buffer);
setArrayValues(overlapFloats, new Float32Array(overlapPayload.buffer, 0, 3), 1);
typedArraySetObservations.push(['overlap float32 bits', Array.from(overlapPayload)]);
const converted = new Uint8Array(3);
setArrayValues(converted, new Int16Array([-1, 257, 300]), 0);
typedArraySetObservations.push(['different types', Array.from(converted)]);
for (const sharedSource of [false, true]) {
    const source = new Uint8Array(sharedSource ? new SharedArrayBuffer(3) : new ArrayBuffer(3));
    const target = new Uint8Array(sharedSource ? new ArrayBuffer(5) : new SharedArrayBuffer(5));
    source.set([3, 4, 5]);
    setArrayValues(target, source, 1);
    typedArraySetObservations.push(['shared ' + (sharedSource ? 'source' : 'target'), Array.from(target)]);
}
const resizableSource = new Uint8Array(new ArrayBuffer(4, {maxByteLength: 8}));
resizableSource.set([1, 2, 3, 4]);
const resizedTarget = new Uint8Array([9, 9, 9, 9, 9, 9]);
setArrayValues(resizedTarget, resizableSource, {valueOf() {resizableSource.buffer.resize(2); return 1;}});
typedArraySetObservations.push(['resizable source', Array.from(resizedTarget)]);
const resizableTarget = new Uint8Array(new ArrayBuffer(5, {maxByteLength: 8}));
setArrayValues(resizableTarget, new Uint8Array([7, 8]), {valueOf() {resizableTarget.buffer.resize(3); return 1;}});
typedArraySetObservations.push(['resizable target', Array.from(resizableTarget)]);
const sentinel = {};
function setFailure(label, target, source, action, offsetValue) {
    const trace = [];
    const offset = {valueOf() {trace.push('offset'); action(); return offsetValue;}};
    try {
        setArrayValues(target, source, offset);
        typedArraySetObservations.push([label, trace, 'returned']);
    } catch (error) {
        typedArraySetObservations.push([label, trace, error === sentinel ? 'sentinel' : error.name]);
    }
}
const detachedSource = new Uint8Array(2);
detachedSource.buffer.transfer();
setFailure('negative before detached source', new Uint8Array(1), detachedSource, () => {}, -1);
setFailure('throw before detached source', new Uint8Array(1), detachedSource, () => {throw sentinel;}, 0);
const detachTarget = new Uint8Array(2);
setFailure('detach target in offset', detachTarget, new Uint8Array([1]), () => {detachTarget.buffer.transfer();}, 0);
const detachSource = new Uint8Array(2);
setFailure('detach source in offset', new Uint8Array(2), detachSource, () => {detachSource.buffer.transfer();}, 0);
const shrinkSource = new Uint8Array(new ArrayBuffer(4, {maxByteLength: 8}), 0, 4);
setFailure('source out of bounds after offset', new Uint8Array(4), shrinkSource,
    () => {shrinkSource.buffer.resize(2);}, 0);
setFailure('mixed content before size', new BigInt64Array(1), new Uint8Array(2), () => {}, 0);
setFailure('range', new Uint8Array(1), new Uint8Array(2), () => {}, 0);
setFailure('infinite offset', new Uint8Array(2), new Uint8Array(0), () => {}, Infinity);
const fractional = new Uint8Array(4);
setArrayValues(fractional, new Uint8Array([7, 8]), 1.9);
setArrayValues(fractional, new Uint8Array([6]), -0);
typedArraySetObservations.push(['fractional and negative zero', Array.from(fractional),
    setArrayValues(fractional, new Uint8Array(0), 4) === undefined]);
const changedSource = new Uint8Array([1, 2]);
const changedTarget = new Uint8Array(4);
let offsetCalls = 0;
setArrayValues(changedTarget, changedSource, {valueOf() {offsetCalls++; changedSource[0] = 7; return 1;}});
typedArraySetObservations.push(['offset mutation', offsetCalls, Array.from(changedTarget)]);
const arrayLikeTrace = [];
const arrayLikeTarget = new Uint8Array(3);
setArrayValues(arrayLikeTarget, {
    get length() {arrayLikeTrace.push('length'); return 2;},
    get 0() {arrayLikeTrace.push('0'); return 257;},
    get 1() {arrayLikeTrace.push('1'); return -1;}
}, {valueOf() {arrayLikeTrace.push('offset'); return 1;}});
typedArraySetObservations.push(['array-like fallback', arrayLikeTrace, Array.from(arrayLikeTarget)]);
