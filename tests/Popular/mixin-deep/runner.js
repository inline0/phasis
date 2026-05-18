const mixinDeep = MixindeepLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
const a = { a: 1, b: { x: 1 } };
const result = mixinDeep(a, { b: { y: 2 } }, { c: 3 });
log("mixed", result);
log("mutates.a", a === result);
console.log(out.join("\n"));
