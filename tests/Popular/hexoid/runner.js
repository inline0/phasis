// hexoid is a deterministic-length hex ID generator. Output is
// random, so we check structural properties only.
const hexoid = Hexoid.hexoid;

const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const make = hexoid(16);
const id = make();
log("length", id.length);
log("hex.shape", /^[0-9a-f]+$/.test(id));
log("uniq.100", new Set(Array.from({ length: 100 }, () => make())).size === 100);

const m32 = hexoid(32);
log("custom-len", m32().length);

console.log(out.join("\n"));
