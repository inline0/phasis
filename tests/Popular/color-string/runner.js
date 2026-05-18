const cs = ColorString.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("get.hex", cs.get("#aabbcc"));
log("get.hex3", cs.get("#abc"));
log("get.rgb", cs.get("rgb(255, 0, 0)"));
log("get.rgba", cs.get("rgba(255, 0, 0, 0.5)"));
log("get.hsl", cs.get("hsl(120, 50%, 50%)"));
log("get.named", cs.get("rebeccapurple"));
log("get.bad", cs.get("nonsense"));

log("to.hex.rgb", cs.to.hex([255, 0, 0]));
log("to.hex.alpha", cs.to.hex([255, 0, 0, 0.5]));
log("to.rgb", cs.to.rgb([255, 100, 50]));
log("to.rgb.alpha", cs.to.rgb([255, 100, 50, 0.5]));
log("to.hsl", cs.to.hsl([120, 50, 50]));
log("to.hsl.alpha", cs.to.hsl([120, 50, 50, 0.5]));
log("to.keyword", cs.to.keyword([0, 255, 255]));

console.log(out.join("\n"));
