const pRetry = PretryLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
async function run() {
  let attempts = 0;
  const result = await pRetry(async () => {
    attempts++;
    if (attempts < 3) throw new Error("not yet");
    return "ok";
  }, { retries: 5, minTimeout: 1, maxTimeout: 5 });
  log("attempts", attempts);
  log("result", result);
  console.log(out.join("\n"));
}
run();
