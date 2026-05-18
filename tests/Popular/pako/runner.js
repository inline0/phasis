const pako = Pako;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const sample = "the quick brown fox jumps over the lazy dog. ".repeat(10);
const enc = new TextEncoder();
const dec = new TextDecoder();

const deflated = pako.deflate(enc.encode(sample));
log("deflate.shrinks", deflated.length < sample.length);

const inflated = pako.inflate(deflated);
log("inflate.match", dec.decode(inflated) === sample);

const gz = pako.gzip(enc.encode(sample));
log("gzip.magic", [gz[0], gz[1]]);
log("gunzip.match", dec.decode(pako.ungzip(gz)) === sample);

const raw = pako.deflateRaw(enc.encode("hello"));
log("raw.roundtrip", dec.decode(pako.inflateRaw(raw)) === "hello");

console.log(out.join("\n"));
