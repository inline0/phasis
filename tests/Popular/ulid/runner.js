const { ulid, monotonicFactory, decodeTime, encodeTime, factory } = UlidLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
// ulid's detectPrng walks Math.random / crypto. In Node sandbox without
// window.crypto and without node crypto, it falls through. Use the
// factory + explicit prng to get deterministic output.
function prng() { return 0.5; }
const u = factory(prng);
const fixedTime = 1747574400000;
const id = u(fixedTime);
log("ulid.length", id.length);
log("ulid.decode.time", decodeTime(id));
log("ulid.fixed", id);
const m1 = u(fixedTime);
const m2 = u(fixedTime);
log("samePrng.sameId", m1 === id && m1 === m2);
// encodeTime independent
log("encodeTime", encodeTime(fixedTime, 10));
console.log(out.join("\n"));
