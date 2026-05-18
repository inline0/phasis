const R = Remeda;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("chunk", R.chunk([1, 2, 3, 4, 5, 6, 7], 3));
log("clamp", R.clamp(15, { min: 0, max: 10 }));
log("uniq", R.unique([1, 2, 2, 3, 1, 4]));
log("flatten", R.flat([[1, 2], [3, 4], [5]]));
log("range", R.range(0, 5));
log("times", R.times(4, i => i * i));
log("sortBy", R.sortBy([{ a: 3 }, { a: 1 }, { a: 2 }], R.prop("a")).map(x => x.a));
log("mapValues", R.mapValues({ a: 1, b: 2 }, x => x * 10));
log("pickBy", R.pickBy({ a: 1, b: 2, c: 3 }, v => v > 1));
log("groupBy", R.groupBy([1, 2, 3, 4, 5], n => n % 2 === 0 ? "even" : "odd"));
log("partition", R.partition([1, 2, 3, 4], x => x % 2 === 0));
log("zip", R.zip([1, 2, 3], ["a", "b", "c"]));
log("sum", R.sum([1, 2, 3, 4, 5]));
log("mean", R.mean([2, 4, 6, 8]));

console.log(out.join("\n"));
