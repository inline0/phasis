const pLimit = PlimitLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
async function run() {
  const limit = pLimit(2);
  const order = [];
  function task(label, delay) {
    return limit(() => new Promise((resolve) => setTimeout(() => {
      order.push(label);
      resolve(label);
    }, delay)));
  }
  const results = await Promise.all([
    task("a", 10),
    task("b", 5),
    task("c", 1),
    task("d", 2),
  ]);
  log("results", results);
  log("concurrent.exhausted", limit.activeCount === 0);
  console.log(out.join("\n"));
}
run();
