import { resolve } from "node:path";
import { deflateSync, inflateSync } from "node:zlib";
import * as Flag from "effect/cli/Flag";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { download } from "./lib/build.ts";
import { emit, insist, main, script } from "./lib/cli.ts";
import { attempt } from "./lib/files.ts";

const revision = "51f138436f50f8110f854252f9a506ccfe06ef1e";
const base = "https://raw.githubusercontent.com/unicode-org/icu4x/" + revision;
const directory = resolve(import.meta.dirname, "../src/BuiltIn/data");
const pattern =
  /PackedEastAsianTraditionalYearData::new\((\d+), \[([ls, ]+)\], (None|Some\((\d+)\)), gregorian\((\d+), (\d+), (\d+)\)\)/g;
export const refresh = (text: string, source: string) => {
  const start = /'start' => (-?\d+)/.exec(text);
  const packed = /'blob' => '([^']+)'/.exec(text);
  if (start === null || packed === null)
    throw new Error("Calendar data has no packed records");
  const bytes = inflateSync(Buffer.from(packed[1], "base64"));
  const original = Buffer.from(bytes);
  const records = [...source.matchAll(pattern)];
  if (records.length === 0) throw new Error("No ICU4X source records found");
  for (const record of records) {
    const leapYear = record[3] !== "None";
    const count = leapYear ? 13 : 12;
    const lengths = record[2].split(", ").slice(0, count);
    const bits = lengths.reduce(
      (value, length, index) => value + (length === "l" ? 1 << index : 0),
      0,
    );
    const epoch = Math.floor(
      Date.UTC(Number(record[5]), Number(record[6]) - 1, Number(record[7])) /
        86400000,
    );
    const offset = (Number(record[1]) - Number(start[1])) * 8;
    if (offset < 0 || offset + 8 > bytes.length)
      throw new Error("Calendar record outside the packed range");
    bytes.writeInt32LE(epoch, offset);
    bytes.writeInt8(leapYear ? Number(record[4]) - 2 : -1, offset + 4);
    bytes.writeUInt16LE(bits, offset + 5);
    bytes.writeUInt8(count, offset + 7);
  }
  // Keep the original stream when records are unchanged. Different zlib
  // implementations can encode identical data into different valid streams.
  if (bytes.equals(original)) return { text, records: records.length };
  const encoded = deflateSync(bytes, { level: 9 }).toString("base64");
  const rewritten =
    text.slice(0, packed.index) +
    packed[0].replace(packed[1], encoded) +
    text.slice(packed.index + packed[0].length);
  return { text: rewritten, records: records.length };
};
export const command = script("gen-lunisolar-modern", {
  description:
    "Refresh modern Chinese and Korean calendar records from pinned ICU4X data.",
  flags: { check: Flag.Boolean("check").pipe(Flag.withDefault(false)) },
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(
          input,
          { status: "planned", revision },
          JSON.stringify,
        );
      const fs = yield* FileSystem.FileSystem;
      const calendars: Record<string, number> = {};
      for (const [calendar, country] of [
        ["chinese", "china"],
        ["dangi", "korea"],
      ]) {
        const source = new TextDecoder().decode(
          yield* download(
            `${base}/components/calendar/src/cal/east_asian_traditional/${country}_data.rs`,
          ),
        );
        const path = resolve(directory, calendar + "_calendar.php");
        const original = yield* fs.readFileString(path);
        const result = yield* attempt(() => refresh(original, source));
        if (input.check)
          yield* insist(
            result.text === original,
            calendar + ": calendar data differs",
          );
        else yield* fs.writeFileString(path, result.text);
        calendars[calendar] = result.records;
      }
      const license = yield* download(base + "/LICENSE");
      const path = resolve(directory, "ICU4X-LICENSE");
      if (input.check)
        yield* insist(
          Buffer.from(yield* fs.readFile(path)).equals(license),
          "ICU4X license differs",
        );
      else yield* fs.writeFile(path, license);
      yield* emit(input, { revision, calendars }, JSON.stringify);
    }),
});
if (import.meta.main) main(command);
