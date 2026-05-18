const async = AsyncLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
async function run() {
  const map = await async.map([1, 2, 3, 4], (n, cb) => setTimeout(() => cb(null, n * 2), 1));
  log("map", map);
  const filter = await async.filter([1, 2, 3, 4, 5], (n, cb) => setTimeout(() => cb(null, n % 2 === 0), 1));
  log("filter", filter);
  const series = await async.series([
    (cb) => setTimeout(() => cb(null, 1), 1),
    (cb) => setTimeout(() => cb(null, 2), 1),
    (cb) => setTimeout(() => cb(null, 3), 1),
  ]);
  log("series", series);
  const reduce = await async.reduce([1, 2, 3, 4], 0, (acc, n, cb) => cb(null, acc + n));
  log("reduce", reduce);
  console.log(out.join("\n"));
}
run();
