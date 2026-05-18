const klona = Klona.klona;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const obj = { a: 1, b: [2, 3, { c: "x" }] };
const cloned = klona(obj);
log("equal", JSON.stringify(cloned) === JSON.stringify(obj));
log("different.ref", cloned !== obj);
log("nested.different.ref", cloned.b !== obj.b);

cloned.b[2].c = "MUTATED";
log("isolated", obj.b[2].c === "x");

log("date.iso", klona(new Date("2024-01-01T00:00:00Z")).toISOString());
log("regex.source", klona(/abc/gi).source);
log("primitive", klona(42));
log("null", klona(null));

// Date / RegExp deep clone
const map = new Map([["a", 1], ["b", 2]]);
const set = new Set([1, 2, 3]);
log("map.same-data", JSON.stringify([...klona(map)]) === JSON.stringify([...map]));
log("set.same-data", JSON.stringify([...klona(set)]) === JSON.stringify([...set]));

console.log(out.join("\n"));
