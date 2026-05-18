const bcrypt = BcryptjsLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
// Set deterministic random for testing
let seed = 12345;
bcrypt.setRandomFallback((len) => {
  const buf = new Array(len);
  for (let i = 0; i < len; i++) {
    seed = (seed * 1103515245 + 12345) & 0x7fffffff;
    buf[i] = seed & 0xff;
  }
  return buf;
});
async function run() {
  const salt = await bcrypt.genSalt(4);
  const hash = await bcrypt.hash("password", salt);
  log("hash.starts", hash.startsWith("$2"));
  log("hash.length", hash.length);
  log("verify.correct", await bcrypt.compare("password", hash));
  log("verify.wrong", await bcrypt.compare("wrong", hash));
  const sync = bcrypt.hashSync("phasis", bcrypt.genSaltSync(4));
  log("sync.verify", bcrypt.compareSync("phasis", sync));
  console.log(out.join("\n"));
}
run();
