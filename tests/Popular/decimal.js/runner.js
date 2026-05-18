const D = Decimal;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

D.set({ precision: 40 });

log("add", new D("0.1").plus("0.2").toFixed(20));
log("mul", new D("123456789").times("987654321").toString());
log("div", new D(1).div(7).toFixed(30));
log("mod", new D("1234567890123").mod(7).toString());

log("pow", new D(2).pow(100).toString());
log("sqrt", new D(2).sqrt().toFixed(40));
log("exp", new D(1).exp().toFixed(30));
log("ln", new D(2).ln().toFixed(30));
log("log10", new D(1000).log(10).toString());

log("cmp.lt", new D(1).cmp(2));
log("cmp.gt", new D(2).cmp(1));
log("cmp.eq", new D(1).cmp(1));

log("abs", new D("-3.14").abs().toString());
log("neg", new D(5).neg().toString());

log("toString.2", new D(255).toString(2));
log("toString.16", new D(65535).toString(16));

let fact = new D(1);
for (let i = 2; i <= 20; i++) fact = fact.times(i);
log("factorial.20", fact.toString());

log("round.up", new D("1.5").round().toString());
log("round.dp", new D("1.23456789").toDecimalPlaces(4).toString());
log("significantDigits", new D("123.456789").toSignificantDigits(5).toString());

console.log(out.join("\n"));
