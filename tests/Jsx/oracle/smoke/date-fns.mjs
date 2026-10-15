import { format, addDays, differenceInCalendarDays, parseISO } from "date-fns";
const d = parseISO("2026-10-13T12:00:00Z");
console.log(format(addDays(d, 5), "yyyy-MM-dd"), differenceInCalendarDays(addDays(d, 40), d));
