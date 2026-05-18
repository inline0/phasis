const pDefer = PdeferLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
async function run() {
  const d1 = pDefer();
  setTimeout(() => d1.resolve(42), 1);
  log("resolve", await d1.promise);
  const d2 = pDefer();
  setTimeout(() => d2.reject(new Error("nope")), 1);
  try { await d2.promise; log("reject", "no throw"); } catch (e) { log("reject", e.message); }
  console.log(out.join("\n"));
}
run();
