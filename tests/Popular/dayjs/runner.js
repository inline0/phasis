// Runner: exercise dayjs's API surface. The test harness pins both
// Node (via `TZ=UTC node …`) and Phasis (via phpunit.xml.dist
// `date.timezone=UTC`) to UTC so local-time output is byte-stable.

const out = [];
const log = (label, value) => out.push(label + " " + JSON.stringify(value));

const anchor = "2024-03-15T10:30:45.123Z";
const d = dayjs(anchor);

// Core
log("isoString", d.toISOString());
log("valueOf", d.valueOf());
log("unix.seconds", d.unix());
log("year", d.year());
log("month", d.month()); // 0-indexed in dayjs
log("date", d.date());
log("hour", d.hour());
log("day-of-week", d.day()); // 0=Sun

// Formatting (local-TZ pinned to UTC, so deterministic)
log("format.default", d.format());
log("format.YMD", d.format("YYYY-MM-DD"));
log("format.full", d.format("YYYY-MM-DDTHH:mm:ss.SSS[Z]"));
log("format.tokens", d.format("[Year:]YYYY [Month:]MM [Day:]DD"));
log("format.12h", d.format("h:mm:ss A"));

// Arithmetic
log("add.7d", d.add(7, "day").format("YYYY-MM-DD"));
log("add.3M", d.add(3, "month").format("YYYY-MM-DD"));
log("add.1y", d.add(1, "year").format("YYYY-MM-DD"));
log("sub.30m", d.subtract(30, "minute").format("HH:mm:ss"));
log("sub.2w", d.subtract(2, "week").format("YYYY-MM-DD"));

// startOf / endOf
log("startOf.day", d.startOf("day").format("YYYY-MM-DDTHH:mm:ss.SSS"));
log("endOf.day", d.endOf("day").format("YYYY-MM-DDTHH:mm:ss.SSS"));
log("startOf.month", d.startOf("month").format("YYYY-MM-DD"));
log("endOf.month", d.endOf("month").format("YYYY-MM-DD"));
log("startOf.year", d.startOf("year").format("YYYY-MM-DD"));

// diff
const future = dayjs("2025-12-25T00:00:00Z");
log("diff.days", future.diff(d, "day"));
log("diff.months", future.diff(d, "month"));
log("diff.years", future.diff(d, "year"));
log("diff.minutes", future.diff(d, "minute"));
log("diff.ms", future.diff(d));

// Comparisons
const earlier = dayjs("2024-01-01T00:00:00Z");
const later = dayjs("2024-12-31T23:59:59Z");
log("isBefore", d.isBefore(later));
log("isAfter", d.isAfter(earlier));
log("isSame.year", d.isSame(later, "year"));
log("isSame.month", d.isSame(dayjs("2024-03-30T00:00:00Z"), "month"));

// Get / set
log("set.month", d.set("month", 0).format("YYYY-MM-DD"));
log("set.year", d.set("year", 2030).format("YYYY-MM-DD"));

// Immutability
const base = dayjs("2024-01-01T00:00:00Z");
const shifted = base.add(10, "day");
log("immutability", {
  base: base.format("YYYY-MM-DD"),
  shifted: shifted.format("YYYY-MM-DD"),
});

// Days-in-month / leap-year sanity
log("daysInMonth.Feb2024", dayjs("2024-02-15").daysInMonth());
log("daysInMonth.Feb2023", dayjs("2023-02-15").daysInMonth());

// Cross-month arithmetic edge
log("add.31d-from-Jan-31", dayjs("2024-01-31").add(1, "month").format("YYYY-MM-DD"));

// Construct from epoch ms and from Date — both land at the same UTC instant.
log("from.epochMs", dayjs(1710501045123).toISOString());
log("from.Date", dayjs(new Date(1710501045123)).toISOString());

// fromUnix
log("fromUnix.seconds", dayjs.unix(1710501045).toISOString());

// isValid
log("isValid.true", dayjs("2024-03-15T10:30:45Z").isValid());
log("isValid.false", dayjs("not-a-date").isValid());

console.log(out.join("\n"));
