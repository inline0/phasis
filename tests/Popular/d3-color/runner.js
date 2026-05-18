const d3c = D3Color;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const c = d3c.color("#f00");
log("color.rgb", c.toString());
log("color.formatHex", c.formatHex());
log("color.formatRgb", c.formatRgb());

log("rgb.create", d3c.rgb(0, 128, 255).formatHex());
log("rgb.brighter", d3c.rgb(100, 100, 100).brighter().formatHex());
log("rgb.darker", d3c.rgb(100, 100, 100).darker().formatHex());

log("hsl.create", d3c.hsl(120, 0.5, 0.5).formatHex());
log("hsl.toRgb", d3c.hsl("#f00").formatRgb());

log("lab.fromHex", d3c.lab("#f00").formatRgb());
log("hcl.fromHex", d3c.hcl("#f00").formatRgb());
log("cubehelix.create", d3c.cubehelix(300, 0.5, 0.5).formatRgb());

const named = d3c.color("rebeccapurple");
log("named.hex", named.formatHex());

console.log(out.join("\n"));
