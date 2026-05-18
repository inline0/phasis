const stringWidth = SwLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
log("ascii", stringWidth("hello"));
log("cjk", stringWidth("你好"));
log("emoji", stringWidth("👋"));
log("mixed", stringWidth("abc 你好 xyz"));
log("ansi", stringWidth("[31mhello[0m"));
console.log(out.join("\n"));
