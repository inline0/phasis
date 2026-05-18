const flatten = ArrayFlatten.flatten;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("flat", flatten([1, [2, 3], [4, [5, 6]]]));
log("deep", flatten([1, [2, [3, [4, [5]]]]]));
log("empty", flatten([]));
log("single", flatten([1, 2, 3]));
log("with-depth.1", flatten([1, [2, [3, 4]]], 1));
log("with-depth.2", flatten([1, [2, [3, [4]]]], 2));
log("string-elements", flatten(["a", ["b", "c"], "d"]));

console.log(out.join("\n"));
