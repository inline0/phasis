const sum = HashSum;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("string", sum("hello"));
log("string.long", sum("the quick brown fox jumps over the lazy dog"));
log("number", sum(42));
log("array", sum([1, 2, 3]));
log("obj", sum({ a: 1, b: 2 }));
log("obj.same", sum({ a: 1, b: 2 }) === sum({ b: 2, a: 1 }));
log("obj.diff", sum({ a: 1 }) !== sum({ a: 2 }));
log("nested", sum({ a: { b: { c: 1 } } }));
log("null", sum(null));
log("undefined", sum(undefined));
log("bool", sum(true));
log("date", sum(new Date(0).toISOString()));

console.log(out.join("\n"));
