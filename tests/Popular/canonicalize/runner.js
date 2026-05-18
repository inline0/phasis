const canonicalize = CanonLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("obj", canonicalize({ b: 2, a: 1 }));
log("nested", canonicalize({ z: { y: [3, 1, 2] }, a: null }));
log("string", canonicalize("hello"));
log("number", canonicalize(42));
log("ordered", canonicalize({ a: 1, b: 2 }) === canonicalize({ b: 2, a: 1 }));
console.log(out.join("\n"));
