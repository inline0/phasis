// esbuild bundles chroma-js with the callable under .default.
const chroma = Chroma.default;
const { mix, contrast, distance, scale, rgb, hsl, lab } = Chroma;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const red = chroma("#f00");
log("hex.in.hex", red.hex());
log("hex.in.rgb", red.rgb());

log("named", chroma("rebeccapurple").hex());
log("rgb", rgb(128, 200, 50).hex());
log("hsl", hsl(240, 0.5, 0.5).hex());
log("lab", lab(50, 40, 30).hex());

log("mix.rgb", mix("#f00", "#00f", 0.5, "rgb").hex());
log("mix.lab", mix("#f00", "#00f", 0.5, "lab").hex());
log("mix.hsl", mix("#f00", "#00f", 0.5, "hsl").hex());

log("brighten", chroma("#444").brighten(1).hex());
log("darken", chroma("#bbb").darken(1).hex());
log("desaturate", chroma("#f00").desaturate(2).hex());
log("alpha", chroma("#f00").alpha(0.5).rgba());

log("contrast", Math.round(contrast("#000", "#fff") * 100) / 100);
log("distance", Math.round(distance("#f00", "#00f", "rgb")));

const sc = scale(["#f00", "#0f0", "#00f"]);
log("scale.0", sc(0).hex());
log("scale.0.5", sc(0.5).hex());
log("scale.1", sc(1).hex());

console.log(out.join("\n"));
