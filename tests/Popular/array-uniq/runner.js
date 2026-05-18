const uniq = ArrayuniqLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("nums", uniq([1, 1, 2, 3, 2, 4]));
log("strs", uniq(["a", "b", "a", "c", "b"]));
log("mixed", uniq([1, "1", 2, "2", 1]));
log("empty", uniq([]));
console.log(out.join("\n"));
