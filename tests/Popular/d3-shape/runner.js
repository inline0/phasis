const d3 = D3ShapeLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
const line = d3.line()
  .x((d, i) => i * 10)
  .y((d) => d * 5);
log("line", line([10, 20, 15, 30, 25]));
const area = d3.area()
  .x((d, i) => i * 10)
  .y0(0)
  .y1((d) => d * 5);
log("area", area([10, 20, 15]));
const arc = d3.arc().innerRadius(50).outerRadius(100).startAngle(0).endAngle(Math.PI / 2);
log("arc", arc());
const pie = d3.pie()([1, 2, 3, 4]);
log("pie.angles", pie.map((p) => ({ start: p.startAngle.toFixed(4), end: p.endAngle.toFixed(4), value: p.value })));
console.log(out.join("\n"));
