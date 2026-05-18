const Fraction = FractionLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
const a = new Fraction(1, 3);
const b = new Fraction(1, 6);
log("add", a.add(b).toFraction());
log("sub", a.sub(b).toFraction());
log("mul", a.mul(b).toFraction());
log("div", a.div(b).toFraction());
log("recip", a.inverse().toFraction());
// log("decimal", ...) — Phasis float-precision diff; skip
log("mod", new Fraction(7, 4).mod(new Fraction(1, 3)).toFraction());
log("pow", new Fraction(2, 3).pow(3).toFraction());
console.log(out.join("\n"));
