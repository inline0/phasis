const dm = DeepMerge;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("merge.flat", dm({ a: 1, b: 2 }, { c: 3 }));
log("merge.override", dm({ a: 1, b: 2 }, { a: 99 }));
log("merge.nested", dm({ a: { x: 1, y: 2 } }, { a: { y: 99, z: 3 } }));
log("merge.arrays.concat", dm({ a: [1, 2] }, { a: [3, 4] }));

// arrayMerge: replace instead of concat
log("merge.arrays.replace", dm({ a: [1, 2] }, { a: [3, 4] }, { arrayMerge: (dst, src) => src }));

// deep deep nested
const a = { user: { profile: { name: "Alice", tags: ["a", "b"] } }, count: 1 };
const b = { user: { profile: { tags: ["c"], email: "x@y.com" } } };
log("deep", dm(a, b));

// merge all
log("all", dm.all([
  { a: 1 },
  { b: 2 },
  { c: 3 },
  { a: 99, d: 4 },
]));

// preserves immutability (doesn't mutate inputs)
const left = { a: 1 };
const right = { b: 2 };
const result = dm(left, right);
log("immutable.left", JSON.stringify(left) === JSON.stringify({ a: 1 }));
log("immutable.right", JSON.stringify(right) === JSON.stringify({ b: 2 }));

console.log(out.join("\n"));
