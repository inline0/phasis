var typedArrayCopyObservations = [];
for (const Type of [Int8Array, Uint8Array, Uint8ClampedArray, Int16Array, Uint16Array,
    Int32Array, Uint32Array, Float16Array, Float32Array, Float64Array, BigInt64Array, BigUint64Array]) {
    const big = Type === BigInt64Array || Type === BigUint64Array;
    const input = big ? [8n, 9n, 1n, 2n, 3n, 7n] : [8, 9, 1, 2, 3, 7];
    const backing = new Type(input);
    const source = new Type(backing.buffer, 2 * Type.BYTES_PER_ELEMENT, 3);
    const copy = new Type(source);
    source[0] = big ? 9n : 9;
    copy[1] = big ? 8n : 8;
    typedArrayCopyObservations.push([Type.name, Array.from(copy, value => String(value)),
        Array.from(source, value => String(value)), copy.byteLength,
        copy.buffer !== source.buffer, Object.getPrototypeOf(copy) === Type.prototype]);
}
const publicSource = new Uint8Array([4, 5, 6]);
let publicReads = 0;
for (const key of ['length', 'byteLength', 'byteOffset', 'buffer', 'constructor', Symbol.iterator]) {
    Object.defineProperty(publicSource, key, {get() {publicReads++; throw new Error('public source read');}});
}
typedArrayCopyObservations.push(['public properties', Array.from(new Uint8Array(publicSource)), publicReads]);
typedArrayCopyObservations.push(['different element types', Array.from(new Uint8Array(new Int16Array([-1, 257])))]);
const payload = new Uint32Array([0x7fc01234, 0x7fa12345, 0x80000000]);
const floats = new Float32Array(payload.buffer);
typedArrayCopyObservations.push(['float32 bits', Array.from(new Uint32Array(new Float32Array(floats).buffer))]);
const negativeZero = new Float64Array(new Float64Array([-0, Infinity, -Infinity, NaN]));
typedArrayCopyObservations.push(['float64 values', Object.is(negativeZero[0], -0),
    negativeZero[1] === Infinity, negativeZero[2] === -Infinity, Number.isNaN(negativeZero[3])]);
const shared = new Uint8Array(new SharedArrayBuffer(3));
shared.set([3, 4, 5]);
const sharedCopy = new Uint8Array(shared);
typedArrayCopyObservations.push(['shared source', Array.from(sharedCopy),
    sharedCopy.buffer instanceof ArrayBuffer, !(sharedCopy.buffer instanceof SharedArrayBuffer)]);
const resizable = new Uint8Array(new ArrayBuffer(4, {maxByteLength: 8}));
resizable.set([1, 2, 3, 4]);
resizable.buffer.resize(2);
const resizedCopy = new Uint8Array(resizable);
resizable[0] = 9;
typedArrayCopyObservations.push(['resizable source', Array.from(resizedCopy), resizedCopy.buffer.resizable]);
function CustomTarget() {}
CustomTarget.prototype = Object.create(Uint8Array.prototype);
const custom = Reflect.construct(Uint8Array, [new Uint8Array([6, 7])], CustomTarget);
typedArrayCopyObservations.push(['custom newTarget', Array.from(custom), Object.getPrototypeOf(custom) === CustomTarget.prototype]);
typedArrayCopyObservations.push(['bigint signed', Array.from(new BigInt64Array(new BigInt64Array([-1n, -9223372036854775808n])), value => String(value))]);
typedArrayCopyObservations.push(['bigint unsigned', Array.from(new BigUint64Array(new BigUint64Array([18446744073709551615n, 0n])), value => String(value))]);
