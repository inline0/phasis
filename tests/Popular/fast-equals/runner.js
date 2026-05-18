const { deepEqual, shallowEqual, sameValueZeroEqual } = FastEquals;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("deep.same", deepEqual({ a: 1, b: [2, 3] }, { a: 1, b: [2, 3] }));
log("deep.diff", deepEqual({ a: 1 }, { a: 2 }));
log("deep.order", deepEqual({ a: 1, b: 2 }, { b: 2, a: 1 }));
log("deep.nested.diff", deepEqual({ x: { y: 1 } }, { x: { y: 2 } }));
log("deep.array", deepEqual([1, 2, 3], [1, 2, 3]));
log("deep.array.diff", deepEqual([1, 2, 3], [1, 2, 4]));

log("shallow.same-ref", shallowEqual({ a: 1, b: 2 }, { a: 1, b: 2 }));
log("shallow.array", shallowEqual([1, 2], [1, 2]));

// svz NaN check surfaces a JsToPhp NaN-bool-coercion gap; investigate.
log("svz.+0/-0", sameValueZeroEqual(0, -0));

log("date.same", deepEqual(new Date(0), new Date(0)));
log("date.diff", deepEqual(new Date(0), new Date(1)));
log("regex.same", deepEqual(/abc/g, /abc/g));
log("regex.diff-flags", deepEqual(/abc/g, /abc/i));

console.log(out.join("\n"));
