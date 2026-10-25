import assert from "node:assert/strict";
import { join } from "node:path";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { emit, fail, insist, main, script } from "../../../scripts/lib/cli.ts";
import { attempt, readJson } from "../../../scripts/lib/files.ts";
import { execute, localOnly, runId } from "../tooling.ts";
import {
  directory,
  harnessHash,
  prefix,
  sourceHash,
  type Report,
} from "./run.ts";

interface NativeReport {
  readonly numPassedTests: number;
  readonly numFailedTests: number;
  readonly testResults: Array<{
    assertionResults: Array<{
      ancestorTitles: string[];
      title: string;
      status: string;
      failureMessages: string[];
    }>;
  }>;
}
interface SelfReport extends Report {
  readonly suiteErrors: Array<{ error: { message: string } }>;
}
export const command = script("hono-controls", {
  description:
    "Compare original deadline and scheduling controls with real native Vitest, locally.",
  flags: {},
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(input, { status: "planned" }, JSON.stringify);
      yield* localOnly();
      const fs = yield* FileSystem.FileSystem;
      const id = runId() + "-selftest";
      const archive = join(directory, "results/runs", id);
      yield* fs.makeDirectory(archive, { recursive: true });
      const built = yield* execute(
        [process.execPath, join(directory, "build-selftest.ts")],
        60,
        "main",
        join(archive, "build.log"),
      );
      yield* insist(built.status === 0, "Harness self-test build failed");
      const nativeCommand = (config: string, output: string) => [
        process.execPath,
        join(directory, "../node_modules/vitest/vitest.mjs"),
        "run",
        "--config",
        join(directory, config),
        "--reporter=json",
        "--outputFile=" + join(archive, output),
      ];
      const workerCommand = (runtime: string, name: string) =>
        runtime === "node"
          ? [
              process.execPath,
              join(directory, ".generated/selftest", name + ".mjs"),
            ]
          : [
              "php",
              "-d",
              "memory_limit=1G",
              join(directory, "run-php.php"),
              join(directory, ".generated/selftest", name + ".mjs"),
            ];
      const parseReports = (output: string) =>
        attempt(() =>
          output
            .split("\n")
            .filter((line) => line.startsWith(prefix))
            .map((line) => JSON.parse(line.slice(prefix.length)) as SelfReport),
        );
      const results: Record<string, { cases: number; differences: string[] }> =
        {};
      const manifest: {
        id: string;
        kind: string;
        results: typeof results;
        timeoutScale: number;
        nativeVitestTimeouts: string;
        runtimeSource: string;
        harnessSource: string;
        scheduling?: Record<string, unknown>;
        timeoutScaling?: Record<string, unknown>;
      } = {
        id,
        kind: "harness-deadline-selftest",
        results,
        timeoutScale: 1,
        nativeVitestTimeouts: "original Vitest configuration",
        runtimeSource: yield* sourceHash(),
        harnessSource: yield* harnessHash(),
      };
      for (const runtime of ["native", "node", "phasis"]) {
        const command =
          runtime === "native"
            ? nativeCommand("selftest.config.mjs", "native.json")
            : workerCommand(runtime, "timeouts");
        const captured = yield* execute(
          command,
          60,
          "main",
          join(archive, runtime + ".log"),
        );
        if (runtime === "native")
          yield* insist(
            captured.status === 1,
            "Native Vitest must report the intentional deadline failures",
          );
        else {
          const reports = yield* parseReports(captured.output);
          yield* insist(
            captured.status === 0 && reports.length === 1,
            runtime + " did not finish the deadline fixtures",
          );
          yield* fs.writeFileString(
            join(archive, runtime + ".json"),
            JSON.stringify(reports[0]),
          );
        }
      }
      const native = yield* readJson<NativeReport>(
        join(archive, "native.json"),
      );
      const cases = new Map(
        native.testResults
          .flatMap((file) => file.assertionResults)
          .map((entry) => [
            [...entry.ancestorTitles, entry.title].join(" > "),
            entry,
          ]),
      );
      yield* insist(
        cases.size === 15 &&
          native.numPassedTests === 4 &&
          native.numFailedTests === 10,
        "Native deadline fixture inventory changed unexpectedly",
      );
      const statuses: Record<string, string> = {
        pass: "passed",
        fail: "failed",
        "suite-blocked": "skipped",
      };
      for (const runtime of ["node", "phasis"]) {
        const report = yield* readJson<SelfReport>(
          join(archive, runtime + ".json"),
        );
        const differences: string[] = [];
        if (report.results.length !== cases.size)
          differences.push("case inventory differs");
        for (const entry of report.results) {
          const reference = cases.get(entry.name);
          if (!reference || statuses[entry.status] !== reference.status) {
            differences.push(entry.name + ": outcome differs");
            continue;
          }
          if (reference.failureMessages.length) {
            const expected = reference.failureMessages[0]
              .split("\n")[0]
              .replace(/^Error: /, "");
            const actual = (entry.error?.message ?? "").split("\n")[0];
            if (expected !== actual)
              differences.push(entry.name + ": error differs");
          }
        }
        if (
          report.suiteErrors.length !== 1 ||
          !report.suiteErrors[0].error.message.startsWith(
            "Hook timed out in 30ms.",
          )
        ) {
          differences.push("suite setup failure was not preserved");
        }
        results[runtime] = { cases: report.results.length, differences };
      }
      const schedules: Record<string, unknown> = {};
      for (const runtime of ["native", "node", "phasis"]) {
        const command =
          runtime === "native"
            ? nativeCommand("scheduling.config.mjs", "scheduling-native.json")
            : workerCommand(runtime, "scheduling");
        const captured = yield* execute(
          command,
          60,
          "main",
          join(archive, "scheduling-" + runtime + ".log"),
        );
        const marker = "__PHASIS_HONO_SCHEDULE__";
        const traces = yield* attempt(() =>
          captured.output
            .split("\n")
            .filter((line) => line.startsWith(marker))
            .map((line) => JSON.parse(line.slice(marker.length)) as unknown),
        );
        yield* insist(
          captured.status === 0 && traces.length === 1,
          runtime + " did not finish the scheduling fixtures",
        );
        schedules[runtime] = traces[0];
        if (runtime === "native") {
          const report = yield* readJson<NativeReport>(
            join(archive, "scheduling-native.json"),
          );
          yield* insist(
            report.numPassedTests === 3 && report.numFailedTests === 0,
            "Native scheduling fixture inventory changed unexpectedly",
          );
        } else {
          const reports = yield* parseReports(captured.output);
          yield* insist(
            reports.length === 1 &&
              reports[0].counts.pass === 3 &&
              !reports[0].counts.fail,
            runtime + " failed the scheduling fixtures",
          );
          if (
            JSON.stringify(schedules[runtime]) !==
            JSON.stringify(schedules.native)
          )
            results[runtime].differences.push(
              "lifecycle job scheduling differs",
            );
        }
      }
      manifest.scheduling = schedules;
      const scaling: Record<string, unknown[]> = {};
      for (const scale of [1, 10]) {
        for (const runtime of ["node", "phasis"]) {
          const label = "timeout-scaling-" + runtime + "-" + scale;
          const captured = yield* execute(
            workerCommand(runtime, "timeout-scaling"),
            60,
            "main",
            join(archive, label + ".log"),
            scale,
          );
          const reports = yield* parseReports(captured.output);
          yield* insist(
            captured.status === 0 && reports.length === 1,
            label + " did not finish",
          );
          const report = reports[0];
          yield* fs.writeFileString(
            join(archive, label + ".json"),
            JSON.stringify(report, null, 2),
          );
          const expected =
            scale === 10
              ? {
                  pass: 9,
                  fail: 0,
                  "upstream-skip": 0,
                  "harness-blocked": 0,
                  "suite-blocked": 0,
                }
              : {
                  pass: 4,
                  fail: 4,
                  "upstream-skip": 0,
                  "harness-blocked": 0,
                  "suite-blocked": 1,
                };
          assert.equal(report.timeoutScale, scale);
          assert.deepEqual(
            report.counts,
            expected,
            label + " produced incorrect deadline outcomes",
          );
          for (const entry of report.results) {
            const original = entry.name.startsWith("explicit ")
              ? 20
              : entry.name.startsWith("disabled ")
                ? 0
                : entry.name.startsWith("infinite ")
                  ? "Infinity"
                  : 5000;
            const effective =
              typeof original === "number" ? original * scale : original;
            assert.deepEqual(
              [entry.originalTimeout, entry.effectiveTimeout],
              [original, effective],
              label + ": incorrect recorded limit for " + entry.name,
            );
            if (
              scale === 1 &&
              ["fail", "suite-blocked"].includes(entry.status)
            ) {
              assert.deepEqual(
                [entry.error?.originalTimeout, entry.error?.effectiveTimeout],
                [20, 20],
                label + ": missing original hook/test deadline evidence",
              );
            }
          }
          scaling[label] = report.results.map((entry) => [
            entry.id,
            entry.name,
            entry.status,
            entry.assertions,
            entry.originalTimeout,
            entry.effectiveTimeout,
          ]);
        }
        assert.deepEqual(
          scaling["timeout-scaling-node-" + scale],
          scaling["timeout-scaling-phasis-" + scale],
          "Node/Phasis timeout scaling case identities, assertions or outcomes differ",
        );
      }
      manifest.timeoutScaling = scaling;
      yield* fs.writeFileString(
        join(archive, "manifest.json"),
        JSON.stringify(manifest, null, 2),
      );
      yield* emit(
        input,
        { results, manifest: join(archive, "manifest.json") },
        JSON.stringify,
      );
      if (
        Object.values(results).some((result) => result.differences.length > 0)
      )
        return yield* fail("Harness controls differ");
    }),
});
if (import.meta.main) main(command);
