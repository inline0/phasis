const diff = ArrDiff;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("simple", diff([1, 2, 3], [2, 4]));
log("noOverlap", diff([1, 2, 3], [4, 5, 6]));
log("fullOverlap", diff([1, 2, 3], [1, 2, 3]));
log("string", diff(["a", "b", "c"], ["b"]));
log("multipleSources", diff([1, 2, 3, 4, 5], [1], [3], [5]));
log("emptyDiff", diff([1, 2, 3], []));
log("emptySource", diff([], [1, 2]));

console.log(out.join("\n"));
