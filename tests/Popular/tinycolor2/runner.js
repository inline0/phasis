const TC = TinyColor.default ?? TinyColor;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const red = TC("#f00");
log("hex.in.hex.out", red.toHexString());
log("hex.in.rgb.out", red.toRgbString());
log("hex.in.hsl.out", red.toHslString());
log("hex.in.name.out", red.toName());

log("named.in.hex", TC("rebeccapurple").toHexString());
log("rgb.in.hex", TC("rgb(255,127,0)").toHexString());
log("hsl.in.hex", TC("hsl(120,50%,50%)").toHexString());
log("hsla.in.rgba", TC("hsla(180, 100%, 50%, 0.5)").toRgbString());

const c = TC("#0080ff");
log("rgb", c.toRgb());
log("hsl", c.toHsl());
log("hsv", c.toHsv());
log("brightness", c.getBrightness());
log("luminance", c.getLuminance().toFixed(4));
log("isDark", c.isDark());
log("isLight", c.isLight());

log("lighten", TC("#f00").lighten(20).toHexString());
log("darken", TC("#f00").darken(20).toHexString());
log("desaturate", TC("#f00").desaturate(50).toHexString());
log("complement", TC("#f00").complement().toHexString());

log("isReadable", TC.isReadable("#000", "#fff"));
log("readability", Math.round(TC.readability("#000", "#fff") * 100) / 100);

log("toString", TC("#f00").toString());

console.log(out.join("\n"));
