const numeral = Numeral;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("fmt.0,0", numeral(1234567).format("0,0"));
log("fmt.0,0.00", numeral(1234.5678).format("0,0.00"));
log("fmt.percent", numeral(0.974878234).format("0.000%"));
log("fmt.currency", numeral(1230.5).format("$0,0.00"));
log("fmt.thousands", numeral(1234567).format("0.0a"));
log("fmt.bytes", numeral(1024 * 1024 * 5).format("0b"));
log("fmt.ordinal", numeral(1).format("0o"));
log("fmt.exp", numeral(1234567).format("0,0e+0"));
log("fmt.time", numeral(2520).format("00:00:00"));

// numeral 2.0.6 dropped unformat from instance/static; skip it.
log("add", numeral(1000).add(250).value());
log("subtract", numeral(1000).subtract(150).value());
log("multiply", numeral(100).multiply(0.5).value());
log("divide", numeral(100).divide(4).value());
log("difference", numeral(100).difference(75));

console.log(out.join("\n"));
