const stringify = JssLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
const circ = { a: 1 };
circ.self = circ;
log("circ", stringify(circ));
log("normal", stringify({ a: 1, b: 2 }));
log("nested.circ", stringify({ root: circ }));
console.log(out.join("\n"));
