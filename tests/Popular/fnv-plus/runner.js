const fnv = FnvPlus;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("hash.empty", fnv.hash("").hex());
log("hash.a", fnv.hash("a").hex());
log("hash.abc", fnv.hash("abc").hex());
log("hash.fox", fnv.hash("the quick brown fox").hex());

log("fast1a32hex", fnv.fast1a32hex("hello"));
log("fast1a32hex.same", fnv.fast1a32hex("hello") === fnv.fast1a32hex("hello"));
log("fast1a52hex", fnv.fast1a52hex("hello"));

// 64-bit
log("hash64.hex", fnv.hash("hello", 64).hex());

console.log(out.join("\n"));
