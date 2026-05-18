const stripAnsi = SaLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("colored", stripAnsi("[31mhello[0m"));
log("plain", stripAnsi("just text"));
log("mixed", stripAnsi("[1mBold[0m and [31mred[0m"));
console.log(out.join("\n"));
