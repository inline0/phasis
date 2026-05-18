const extend = ExtendLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("shallow", extend({}, { a: 1 }, { b: 2 }));
log("deep", extend(true, {}, { a: { x: 1 } }, { a: { y: 2 } }));
console.log(out.join("\n"));
