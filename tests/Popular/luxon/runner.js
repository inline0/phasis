// luxon — DateTime, Duration, Interval. We pin everything to UTC
// and ISO inputs so the oracle is reproducible.
const { DateTime, Duration, Interval } = Luxon.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Construction + ISO round trip
const dt = DateTime.fromISO("2026-05-18T12:30:00.000Z", { zone: "utc" });
log("iso", dt.toISO());
log("year", dt.year);
log("month", dt.month);
log("day", dt.day);
log("hour", dt.hour);
log("weekday", dt.weekday);
log("ordinal", dt.ordinal);
log("daysInMonth", dt.daysInMonth);

// Arithmetic
log("plus.days", dt.plus({ days: 30 }).toISO());
log("plus.months", dt.plus({ months: 6 }).toISO());
log("minus.weeks", dt.minus({ weeks: 2 }).toISO());

// Formatting
log("fmt.short", dt.toFormat("yyyy-MM-dd"));
log("fmt.long", dt.toFormat("EEEE, LLLL d, yyyy"));
log("fmt.time", dt.toFormat("HH:mm:ss"));

// Diff
const d2 = DateTime.fromISO("2026-06-18T12:30:00.000Z", { zone: "utc" });
const diff = d2.diff(dt, ["days", "hours"]).toObject();
log("diff", diff);

// Duration
const dur = Duration.fromObject({ hours: 25, minutes: 90 });
log("dur.iso", dur.toISO());
log("dur.normalized", dur.shiftTo("hours", "minutes").toObject());

// Interval
const iv = Interval.fromDateTimes(dt, d2);
log("iv.length.days", iv.length("days"));
log("iv.contains", iv.contains(dt.plus({ days: 5 })));

// Parsing variants
const fromFmt = DateTime.fromFormat("18-05-2026", "dd-MM-yyyy", { zone: "utc" });
log("fromFormat", fromFmt.toISODate());

// Comparison
log("equals", dt.equals(DateTime.fromISO("2026-05-18T12:30:00.000Z", { zone: "utc" })));
log("lt", dt < d2);

// Invalid handling
const bad = DateTime.fromISO("not a date");
log("invalid", { valid: bad.isValid, reason: bad.invalidReason });

console.log(out.join("\n"));
