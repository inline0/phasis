const df = DateFns;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Anchor at fixed UTC instant
const anchor = new Date("2024-03-15T10:30:45.000Z");

log("year", df.getYear(anchor));
log("month", df.getMonth(anchor));
log("date", df.getDate(anchor));
log("day", df.getDay(anchor));
log("hour", anchor.getUTCHours());

log("addDays.7", df.addDays(anchor, 7).toISOString());
log("addMonths.3", df.addMonths(anchor, 3).toISOString());
log("addYears.1", df.addYears(anchor, 1).toISOString());
log("subDays.5", df.subDays(anchor, 5).toISOString());
log("subMinutes.30", df.subMinutes(anchor, 30).toISOString());

log("startOfDay", df.startOfDay(anchor).toISOString());
log("endOfDay", df.endOfDay(anchor).toISOString());
log("startOfMonth", df.startOfMonth(anchor).toISOString());
log("startOfYear", df.startOfYear(anchor).toISOString());

const future = new Date("2025-12-25T00:00:00Z");
log("diffDays", df.differenceInDays(future, anchor));
log("diffMonths", df.differenceInMonths(future, anchor));
log("diffMinutes", df.differenceInMinutes(future, anchor));

log("isBefore", df.isBefore(anchor, future));
log("isAfter", df.isAfter(future, anchor));
log("isSameDay", df.isSameDay(anchor, new Date("2024-03-15T20:00:00Z")));

log("format.iso", df.formatISO(anchor));
log("format.dist", df.formatDistance(anchor, future));

const parsed = df.parseISO("2024-06-15T12:00:00.000Z");
log("parseISO", parsed.toISOString());

log("isValid.ok", df.isValid(anchor));
log("isValid.bad", df.isValid(new Date("nope")));

console.log(out.join("\n"));
