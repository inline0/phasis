const set = SetvalueLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
const obj = {};
set(obj, "a.b.c", 42);
log("nested", obj);
set(obj, "arr.0.x", 1);
log("array", obj);
console.log(out.join("\n"));
