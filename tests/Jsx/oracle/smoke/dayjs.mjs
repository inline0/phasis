import dayjs from "dayjs";
import utc from "dayjs/plugin/utc.js";
dayjs.extend(utc);
console.log(dayjs.utc("2026-10-13T12:00:00Z").add(1, "month").format("YYYY-MM-DD HH:mm"));
