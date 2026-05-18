// markdown-it-emoji is a plugin — wire it onto markdown-it. Since
// the bundle exports the plugin only, we re-use the markdown-it
// library that the popular suite already vendors (it's loaded into
// the same sandbox by gen-oracle.js's library + runner combo).
//
// But gen-oracle only loads ONE library per directory. So we need
// to do this without markdown-it. Easiest: assert structural API.

const M = MdItEmoji;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("has.bare", typeof M.bare);
log("has.full", typeof M.full);
log("has.light", typeof M.light);
log("bare.is.function", typeof M.bare === "function");

console.log(out.join("\n"));
