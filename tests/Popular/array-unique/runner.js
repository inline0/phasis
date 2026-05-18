const unique = ArrayuniqueLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("nums", unique([1, 1, 2, 3, 2, 4]));
log("strs", unique(["a", "b", "a", "c"]));
log("empty", unique([]));
console.log(out.join("\n"));
