const cn = ColorName.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("red", cn.red);
log("white", cn.white);
log("black", cn.black);
log("rebeccapurple", cn.rebeccapurple);
log("hotpink", cn.hotpink);
log("dodgerblue", cn.dodgerblue);
log("aliceblue", cn.aliceblue);
log("count", Object.keys(cn).length);
log("seagreen", cn.seagreen);
log("salmon", cn.salmon);

console.log(out.join("\n"));
