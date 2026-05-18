const T = EsToolkit;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("chunk", T.chunk([1, 2, 3, 4, 5, 6, 7], 3));
log("compact", T.compact([0, 1, false, 2, null, "", "x"]));
log("uniq", T.uniq([1, 2, 2, 3, 1, 4]));
log("difference", T.difference([1, 2, 3, 4], [2, 4]));
log("intersection", T.intersection([1, 2, 3], [2, 3, 4]));
log("union", T.union([1, 2], [2, 3], [3, 4]));
log("at", T.at([10, 20, 30, 40], [0, 2, -1]));
log("groupBy", T.groupBy([1, 2, 3, 4, 5], n => n % 2 === 0 ? "even" : "odd"));
log("partition", T.partition([1, 2, 3, 4], x => x % 2 === 0));
log("orderBy", T.orderBy([{a:3},{a:1},{a:2}], ["a"], ["asc"]));
log("countBy", T.countBy([1, 1, 2, 3, 3, 3], n => n));
log("zip", T.zip([1, 2, 3], ["a", "b", "c"]));
log("range", T.range(0, 5));
log("flatten", T.flatten([[1, 2], [3, 4]]));
log("sum", T.sum([1, 2, 3, 4]));
log("mean", T.mean([2, 4, 6, 8]));

console.log(out.join("\n"));
