const ansiRegex = ArLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
const r = ansiRegex();
log("matches", "[31mred[0m".match(r));
log("plain", "hello".match(r));
log("multi", "[1;31;43mfoo[0m bar".match(r));
console.log(out.join("\n"));
