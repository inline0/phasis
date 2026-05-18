const JSBI = Jsbi.default ?? Jsbi;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const a = JSBI.BigInt(123456789);
const b = JSBI.BigInt("987654321");

log("add", JSBI.add(a, b).toString());
log("sub", JSBI.subtract(b, a).toString());
log("mul", JSBI.multiply(a, b).toString());
log("div", JSBI.divide(b, a).toString());
log("mod", JSBI.remainder(b, a).toString());
log("pow", JSBI.exponentiate(JSBI.BigInt(2), JSBI.BigInt(64)).toString());
log("eq", JSBI.equal(JSBI.BigInt(42), JSBI.BigInt(42)));
log("lt", JSBI.lessThan(a, b));
log("gt", JSBI.greaterThan(b, a));

log("toString.16", JSBI.BigInt(65535).toString(16));
log("toString.2", JSBI.BigInt(255).toString(2));

log("bitAnd", JSBI.bitwiseAnd(JSBI.BigInt(0xff), JSBI.BigInt(0x0f)).toString());
log("bitOr", JSBI.bitwiseOr(JSBI.BigInt(0xf0), JSBI.BigInt(0x0f)).toString());
log("shl", JSBI.leftShift(JSBI.BigInt(1), JSBI.BigInt(10)).toString());

console.log(out.join("\n"));
