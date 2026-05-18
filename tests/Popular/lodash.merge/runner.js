const merge = LodashmergeLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("merge", merge({ a: 1 }, { b: 2 }));
log("nested", merge({ a: { x: 1 } }, { a: { y: 2 } }));
log("override", merge({ a: 1 }, { a: 99 }));
log("arrays", merge({ a: [1, 2] }, { a: [3] }));
console.log(out.join("\n"));
