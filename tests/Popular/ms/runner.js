const ms = Ms;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// String -> ms
log("parse.30s", ms("30s"));
log("parse.5m", ms("5m"));
log("parse.2h", ms("2h"));
log("parse.1d", ms("1d"));
log("parse.1w", ms("1w"));
log("parse.500ms", ms("500ms"));
log("parse.float", ms("1.5h"));
log("parse.neg", ms("-1h"));

// ms -> string (short)
log("fmt.30000", ms(30000));
log("fmt.300000", ms(300000));
log("fmt.3600000", ms(3600000));
log("fmt.86400000", ms(86400000));

// long
log("fmt.long.30s", ms(30000, { long: true }));
log("fmt.long.5m", ms(300000, { long: true }));
log("fmt.long.1h", ms(3600000, { long: true }));

console.log(out.join("\n"));
