const bytes = Bytes;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("fmt.1024", bytes(1024));
log("fmt.1048576", bytes(1048576));
log("fmt.1073741824", bytes(1073741824));
log("fmt.500", bytes(500));
log("fmt.0", bytes(0));
log("parse.1KB", bytes("1KB"));
log("parse.1MB", bytes("1MB"));
log("parse.1.5GB", bytes("1.5GB"));
log("parse.500", bytes("500"));
log("fmt.thousandsSep", bytes(1500, { thousandsSeparator: "," }));
log("fmt.decimalPlaces", bytes(1536, { decimalPlaces: 0 }));
log("fmt.fixedDecimals", bytes(1024, { fixedDecimals: true, decimalPlaces: 2 }));
log("fmt.unit", bytes(1024, { unit: "KB" }));
log("fmt.unitSep", bytes(1024, { unitSeparator: " " }));

console.log(out.join("\n"));
