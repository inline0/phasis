import { basename, dirname, join } from "node:path";
import { isDeepStrictEqual } from "node:util";
import * as Argument from "effect/cli/Argument";
import * as Flag from "effect/cli/Flag";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { emit, fail, main, script } from "../../../scripts/lib/cli.ts";
import { readJson } from "../../../scripts/lib/files.ts";
import { localOnly } from "../tooling.ts";
import type { Report } from "./run.ts";

interface NativeFile {
  readonly name: string;
  readonly message?: string;
  readonly assertionResults: Array<{
    ancestorTitles: string[];
    title: string;
    status: string;
  }>;
}
const count = (entries: ReadonlyArray<readonly [string, string]>) => {
  const counts = new Map<string, number>();
  for (const entry of entries) {
    const key = JSON.stringify(entry);
    counts.set(key, (counts.get(key) ?? 0) + 1);
  }
  return counts;
};
const difference = (left: Map<string, number>, right: Map<string, number>) =>
  [...left].flatMap(([key, value]) =>
    Array.from(
      { length: Math.max(0, value - (right.get(key) ?? 0)) },
      () => JSON.parse(key) as [string, string],
    ),
  );
export const command = script("compare-native-hono", {
  description:
    "Compare every runtime case against an independent original-source Vitest report.",
  flags: {
    native: Argument.String("native-report"),
    manifest: Argument.String("run-manifest"),
    runtime: Flag.Literals("runtime", ["node", "phasis"]).pipe(
      Flag.withDefault("node"),
    ),
  },
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(
          input,
          { status: "planned", runtime: input.runtime },
          JSON.stringify,
        );
      yield* localOnly();
      const fs = yield* FileSystem.FileSystem;
      const reference = yield* readJson<{ testResults: NativeFile[] }>(
        input.native,
      );
      const manifest = yield* readJson<{
        id: string;
        upstream: { directory: string };
        selected: string[];
        timeoutScale?: number;
      }>(input.manifest);
      const marker = basename(manifest.upstream.directory) + "/";
      const nativeFiles = new Map(
        reference.testResults.map((file) => [
          file.name.slice(
            file.name.indexOf(marker) < 0
              ? 0
              : file.name.indexOf(marker) + marker.length,
          ),
          file,
        ]),
      );
      const statuses: Record<string, string> = {
        pass: "passed",
        "upstream-skip": "skipped",
        "suite-blocked": "skipped",
        fail: "failed",
        "harness-blocked": "harness-blocked",
      };
      const files: Array<{
        file: string;
        status: string;
        message?: string;
        nativeCases?: number;
        runtimeCases?: number;
        missing?: [string, string][];
        unexpected?: [string, string][];
      }> = [];
      for (const name of manifest.selected) {
        const row: (typeof files)[number] = { file: name, status: "match" };
        files.push(row);
        const native = nativeFiles.get(name);
        if (!native) {
          row.status = "native-file-missing";
          continue;
        }
        if (native.assertionResults.length === 0) {
          row.status = "native-collection-blocked";
          row.message = native.message ?? "";
          continue;
        }
        const file = join(
          dirname(input.manifest),
          name.replaceAll("/", "__").replace(/\.[^.]+$/, "") +
            "-" +
            input.runtime +
            ".json",
        );
        if (!(yield* fs.exists(file))) {
          row.status = "runtime-report-missing";
          continue;
        }
        const report = yield* readJson<Report>(file);
        const expected = count(
          native.assertionResults.map((entry) => [
            [...entry.ancestorTitles, entry.title].join(" > "),
            entry.status,
          ]),
        );
        const actual = count(
          report.results.map((entry) => [
            entry.name,
            statuses[entry.status] ?? entry.status,
          ]),
        );
        row.nativeCases = native.assertionResults.length;
        row.runtimeCases = report.results.length;
        if (!isDeepStrictEqual(expected, actual)) {
          row.status = "case-results-differ";
          row.missing = difference(expected, actual);
          row.unexpected = difference(actual, expected);
        }
      }
      const counts: Record<string, number> = {};
      for (const row of files)
        counts[row.status] = (counts[row.status] ?? 0) + 1;
      const result = {
        run: manifest.id,
        runtime: input.runtime,
        files,
        timeoutScale: manifest.timeoutScale ?? 1,
        nativeVitestTimeouts:
          "original Vitest configuration; framework timeout scaling is not applied",
        counts,
      };
      const output = join(
        dirname(input.manifest),
        "native-comparison-" + input.runtime + ".json",
      );
      yield* fs.writeFileString(output, JSON.stringify(result, null, 2));
      yield* emit(input, { report: output, counts }, JSON.stringify);
      if (files.some((row) => row.status !== "match"))
        return yield* fail("Native case results differ");
    }),
});
if (import.meta.main) main(command);
