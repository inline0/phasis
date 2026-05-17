// Runner: exercise bignumber.js's arbitrary-precision arithmetic.
// We keep the workload modest (no .pow(10000), no factorial(50)) so
// the test finishes quickly under Phasis — these still cover parse,
// stringify, +, -, *, /, mod, sqrt, comparison, rounding modes,
// base conversion, scientific notation.
//
// bignumber.js registers itself on globalThis as BigNumber (UMD).

const out = [];
const log = (label, value) => out.push(label + " " + JSON.stringify(value));

BigNumber.config({ DECIMAL_PLACES: 20, ROUNDING_MODE: 4 }); // ROUND_HALF_UP

// Basic arithmetic
log("add", new BigNumber("123456789012345678901234567890")
  .plus("987654321098765432109876543210")
  .toString());
log("sub", new BigNumber("1e30").minus("1").toString());
log("mul", new BigNumber("987654321").times("123456789").toString());
log("div", new BigNumber(1).div(7).toFixed(20));
log("idiv", new BigNumber(100).idiv(7).toString());
log("mod", new BigNumber("12345678901234567890").mod(7).toString());

// Power and root — small exponent only
log("pow.100", new BigNumber(2).pow(100).toString());
log("sqrt", new BigNumber(2).sqrt().toFixed(20));

// Negative + comparison
log("abs", new BigNumber("-1234.5678").abs().toString());
log("neg", new BigNumber("42").negated().toString());
log("cmp.gt", new BigNumber("1e20").gt("1e19"));
log("cmp.eq", new BigNumber("1.0").eq("1"));
log("cmp.exact-decimals", new BigNumber("0.1").plus("0.2").eq("0.3")); // true

// Rounding
log("round.half-up", new BigNumber("1.5").integerValue(4).toString()); // 2
log("round.half-down", new BigNumber("1.5").integerValue(5).toString()); // 1
log("round.floor", new BigNumber("1.9").integerValue(3).toString()); // 1
log("round.ceil", new BigNumber("1.1").integerValue(2).toString()); // 2
log("dp.4", new BigNumber("3.14159265358979").decimalPlaces(4).toString());
log("dp.10", new BigNumber("3.14159265358979").decimalPlaces(10).toString());

// Base conversion
log("toString.2", new BigNumber("255").toString(2));
log("toString.16", new BigNumber("65535").toString(16));
log("fromString.16", new BigNumber("ff", 16).toString());
log("fromString.2", new BigNumber("101010", 2).toString());

// Scientific notation
log("toExponential", new BigNumber("12345").toExponential(2));
log("toPrecision", new BigNumber("123456789").toPrecision(5));

// Factorial 15 — small, validates large-integer multiply path
let fact = new BigNumber(1);
for (let i = 2; i <= 15; i++) fact = fact.times(i);
log("factorial.15", fact.toString());

// Shifting via *2 / div(2)
log("shiftLeft.10", new BigNumber("1").times(new BigNumber(2).pow(10)).toString());
log("shiftRight.10", new BigNumber("1024").div(new BigNumber(2).pow(10)).toString());

console.log(out.join("\n"));
