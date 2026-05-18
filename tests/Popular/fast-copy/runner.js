const fc = FastCopy.default ?? FastCopy.copy ?? FastCopy;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const obj = { a: 1, b: [2, 3, { c: "x" }] };
const cloned = fc(obj);
log("equal", JSON.stringify(cloned) === JSON.stringify(obj));
log("different.ref", cloned !== obj);
cloned.b[2].c = "M";
log("isolated", obj.b[2].c === "x");

log("primitive", fc(42));
log("null", fc(null));
log("arr", fc([1, 2, 3]));
log("nested", fc({ d: new Date(0).toISOString() }).d);

const m = new Map([["k", 1]]);
log("map", JSON.stringify([...fc(m)]));
const s = new Set([1, 2, 3]);
log("set", JSON.stringify([...fc(s)]));

console.log(out.join("\n"));
