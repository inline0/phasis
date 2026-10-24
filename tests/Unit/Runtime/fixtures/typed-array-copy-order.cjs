var copyOrderObservations = [];
function constructCopy(Type, source, NewTarget) {
    return Reflect.construct(Type, [source], NewTarget);
}
function copyOrderCase(label, Type, source, action) {
    const effects = [];
    const prototype = Object.create(Type.prototype);
    const target = (function Target() {}).bind(null);
    Object.defineProperty(target, 'prototype', {get() {
        effects.push('prototype');
        action(source);
        return prototype;
    }});
    try {
        const result = constructCopy(Type, source, target);
        copyOrderObservations.push([label, effects, 'copy', result.length,
            Array.from(result, value => String(value)), Object.getPrototypeOf(result) === prototype,
            result.buffer !== source.buffer]);
    } catch (error) {
        copyOrderObservations.push([label, effects, error === copyOrderSentinel ? 'sentinel' : error.name]);
    }
}
const copyOrderSentinel = {};
for (const Type of [Uint8Array, Float64Array, BigInt64Array]) {
    const input = Type === BigInt64Array ? [1n, 2n] : [1, 2];
    const live = new Type(input);
    const normal = constructCopy(Type, live, Type);
    copyOrderObservations.push([Type.name + ' intrinsic copy', normal.length,
        Array.from(normal, value => String(value)), normal.buffer !== live.buffer]);
    const detached = new Type(input);
    detached.buffer.transfer();
    copyOrderCase(Type.name + ' detached throw', Type, detached, () => {throw copyOrderSentinel;});
    copyOrderCase(Type.name + ' detached read', Type, detached, () => {});
    copyOrderCase(Type.name + ' detach in getter', Type, new Type(input), source => {source.buffer.transfer();});
    const size = Type.BYTES_PER_ELEMENT;
    const outOfBounds = new Type(new ArrayBuffer(4 * size, {maxByteLength: 8 * size}), 0, 4);
    outOfBounds.buffer.resize(2 * size);
    copyOrderCase(Type.name + ' out of bounds throw', Type, outOfBounds, () => {throw copyOrderSentinel;});
    copyOrderCase(Type.name + ' out of bounds read', Type, outOfBounds, () => {});
}
const fixed = new Uint8Array(new ArrayBuffer(4, {maxByteLength: 8}), 0, 4);
fixed.set([1, 2, 3, 4]);
copyOrderCase('fixed shrink', Uint8Array, fixed, source => {source.buffer.resize(2);});
