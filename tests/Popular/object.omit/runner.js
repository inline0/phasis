const omit = ObjectomitLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("omit.one", omit({ a: 1, b: 2, c: 3 }, "b"));
log("omit.many", omit({ a: 1, b: 2, c: 3, d: 4 }, ["a", "c"]));
log("omit.missing", omit({ a: 1 }, "missing"));
console.log(out.join("\n"));
