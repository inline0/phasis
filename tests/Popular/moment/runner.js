// moment — classic JS date library. UMD self-registers `moment`
// on globalThis. Everything pinned to UTC for reproducibility.
const m = moment;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const d = m.utc("2026-05-18T12:30:00.000Z");
log("iso", d.toISOString());
log("year", d.year());
log("month", d.month());
log("date", d.date());
log("day", d.day());
log("hour", d.hour());

// Formatting
log("fmt.iso", d.format("YYYY-MM-DD"));
log("fmt.long", d.format("dddd, MMMM Do YYYY, h:mm:ss a"));
log("fmt.time", d.format("HH:mm:ss"));

// Arithmetic (clone before mutating, moment is mutable)
log("add.days", d.clone().add(30, "days").toISOString());
log("subtract.weeks", d.clone().subtract(2, "weeks").toISOString());
log("startOf.month", d.clone().startOf("month").toISOString());
log("endOf.month", d.clone().endOf("month").toISOString());

// Diff
const d2 = m.utc("2026-06-18T12:30:00.000Z");
log("diff.days", d2.diff(d, "days"));
log("diff.hours", d2.diff(d, "hours"));
log("from", d.from(d2, true));

// Duration
const dur = m.duration({ hours: 25, minutes: 90 });
log("dur.hours", dur.asHours());
log("dur.humanize", dur.humanize());

// Parsing
log("parse.fmt", m.utc("18-05-2026", "DD-MM-YYYY").toISOString());
log("parse.invalid", m.utc("not-a-date").isValid());

// Comparison
log("isBefore", d.isBefore(d2));
log("isAfter", d.isAfter(d2));
log("isSame", d.isSame(m.utc("2026-05-18T12:30:00.000Z")));

// Calendar helpers
log("weekday", d.format("dddd"));
log("daysInMonth", d.daysInMonth());

console.log(out.join("\n"));
