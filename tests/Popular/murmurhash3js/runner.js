const mh = Mmh3;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// x86.hash32
log("x86.32.empty", mh.x86.hash32(""));
log("x86.32.a", mh.x86.hash32("a"));
log("x86.32.abc", mh.x86.hash32("abc"));
log("x86.32.fox", mh.x86.hash32("the quick brown fox"));
log("x86.32.seeded", mh.x86.hash32("a", 42));

// x86.hash128
log("x86.128.abc", mh.x86.hash128("abc"));

// x64.hash128
log("x64.128.empty", mh.x64.hash128(""));
log("x64.128.abc", mh.x64.hash128("abc"));
log("x64.128.same", mh.x64.hash128("abc") === mh.x64.hash128("abc"));

console.log(out.join("\n"));
