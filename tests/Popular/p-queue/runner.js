const PQueue = PqueueLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
async function run() {
  const q = new PQueue({ concurrency: 2 });
  const order = [];
  q.add(async () => { await new Promise(r => setTimeout(r, 5)); order.push("a"); return "a"; });
  q.add(async () => { await new Promise(r => setTimeout(r, 1)); order.push("b"); return "b"; });
  q.add(async () => { await new Promise(r => setTimeout(r, 2)); order.push("c"); return "c"; });
  q.add(async () => { await new Promise(r => setTimeout(r, 1)); order.push("d"); return "d"; });
  await q.onIdle();
  log("size.before.idle", q.size);
  log("order.contains.all", ["a","b","c","d"].every(x => order.includes(x)));
  console.log(out.join("\n"));
}
run();
