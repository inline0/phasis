const pick = ObjectpickLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("pick.one", pick({ a: 1, b: 2, c: 3 }, "b"));
log("pick.many", pick({ a: 1, b: 2, c: 3, d: 4 }, ["a", "c"]));
log("pick.missing", pick({ a: 1 }, "missing"));
console.log(out.join("\n"));
