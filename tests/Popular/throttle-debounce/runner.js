const { throttle, debounce } = TdLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
async function run() {
  let count = 0;
  const inc = () => count++;
  const throttled = throttle(50, inc);
  throttled(); throttled(); throttled();
  await new Promise(r => setTimeout(r, 5));
  log("throttle.early", count);
  await new Promise(r => setTimeout(r, 100));
  log("throttle.after", count);
  let dcount = 0;
  const dinc = () => dcount++;
  const debounced = debounce(30, dinc);
  debounced(); debounced(); debounced();
  log("debounce.early", dcount);
  await new Promise(r => setTimeout(r, 60));
  log("debounce.after", dcount);
  console.log(out.join("\n"));
}
run();
