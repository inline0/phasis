var typedArraySetOrderObservations = [];
const orderSentinel = {};
function orderedSet(target, source, offset) {
    return target.set(source, offset);
}
function observeSetOrder(label, Target, Source, targetLength, sourceLength, offsetValue, detachSource, throws) {
    const target = new Target(targetLength);
    const source = new Source(sourceLength);
    if (detachSource) source.buffer.transfer();
    const trace = [];
    try {
        orderedSet(target, source, {valueOf() {
            trace.push('offset');
            if (throws) throw orderSentinel;
            return offsetValue;
        }});
        typedArraySetOrderObservations.push([label, trace, 'returned']);
    } catch (error) {
        typedArraySetOrderObservations.push([label, trace,
            error === orderSentinel ? 'sentinel' : error.name]);
    }
}
for (const pair of [[BigInt64Array, Uint8Array], [Uint8Array, BigInt64Array]]) {
    const [Target, Source] = pair;
    const label = Target.name + ' from ' + Source.name;
    observeSetOrder(label + ' oversized', Target, Source, 1, 2, 0, false, false);
    observeSetOrder(label + ' offset exceeds range', Target, Source, 1, 1, 1, false, false);
    observeSetOrder(label + ' infinite offset', Target, Source, 1, 0, Infinity, false, false);
    observeSetOrder(label + ' content mismatch', Target, Source, 1, 1, 0, false, false);
    observeSetOrder(label + ' detached oversized source', Target, Source, 1, 2, 0, true, false);
    observeSetOrder(label + ' detached source infinite offset', Target, Source, 1, 0, Infinity, true, false);
    observeSetOrder(label + ' detached source negative offset', Target, Source, 1, 2, -1, true, false);
    observeSetOrder(label + ' throw before detached source', Target, Source, 1, 2, 0, true, true);
}
