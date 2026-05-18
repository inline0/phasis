const mergeOptions = MergeoptionsLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("merge", mergeOptions({ a: 1 }, { b: 2 }));
log("nested", mergeOptions({ a: { x: 1 } }, { a: { y: 2 } }));
console.log(out.join("\n"));
