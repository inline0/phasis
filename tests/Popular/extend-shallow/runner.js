const extend = ExtendshallowLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("merge", extend({ a: 1 }, { b: 2 }, { c: 3 }));
log("override", extend({ a: 1 }, { a: 99 }));
log("shallow", extend({ nested: { x: 1 } }, { nested: { y: 2 } }));
console.log(out.join("\n"));
