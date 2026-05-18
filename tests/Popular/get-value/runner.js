const get = GetvalueLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
const obj = { a: { b: { c: 42 } }, arr: [{ x: 1 }, { x: 2 }] };
log("path", get(obj, "a.b.c"));
log("array", get(obj, "arr.0.x"));
log("missing", get(obj, "a.x.y"));
log("default", get(obj, "missing", { default: "fallback" }));
console.log(out.join("\n"));
