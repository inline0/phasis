import { basename, join, resolve } from "node:path";
import { isDeepStrictEqual } from "node:util";
import * as Flag from "effect/cli/Flag";
import * as Effect from "effect/Effect";
import * as Exit from "effect/Exit";
import * as FileSystem from "effect/FileSystem";
import * as Option from "effect/Option";
import { emit, fail, insist, main, script } from "../../../scripts/lib/cli.ts";
import { attempt, readJson } from "../../../scripts/lib/files.ts";
import {
  execute,
  filesHash,
  localOnly,
  repository,
  sha256,
  timeoutScale,
  treeHash,
} from "../tooling.ts";

const here = import.meta.dirname;
interface Case {
  readonly id: string;
  readonly title: string;
}
interface Entry {
  readonly path: string;
  readonly tests: number;
  readonly cases: Case[];
}
interface Manifest {
  readonly tests: number;
  readonly files: Entry[];
}
interface Report {
  readonly complete?: boolean;
  readonly discovered?: number;
  readonly pass?: number;
  readonly fail?: number;
  readonly pending?: number;
  readonly caseManifest?: Case[];
  readonly tests?: Array<{ id: string }>;
  readonly timeoutScale?: number;
  readonly loadErrors?: unknown[];
  readonly asynchronousErrors?: unknown[];
}
interface Result {
  readonly runtime: string;
  readonly file: string;
  readonly expectedTests: number;
  readonly exitCode: number | null;
  readonly timedOut: boolean;
  readonly log: string;
  readonly elapsedSeconds: number;
  readonly caseIdentityMatches: boolean;
  readonly unaccountedCases: string[];
  readonly timeoutScaleMatches: boolean;
  readonly report: Report;
}
export const sourceIdentity = (scale = 1) =>
  Effect.gen(function* () {
    const fs = yield* FileSystem.FileSystem;
    return {
      timeoutScale: scale,
      runtimeSha256: yield* treeHash(join(repository, "src"), true),
      upstreamSha256: yield* treeHash(join(here, "upstream"), true),
      manifestSha256: sha256(yield* fs.readFile(join(here, "manifest.json"))),
      packageLockSha256: sha256(
        yield* fs.readFile(join(here, "../package-lock.json")),
      ),
      harnessSha256: yield* filesHash(here, [
        "run.ts",
        "runner.cjs",
        "run-node.cjs",
        "run-phasis.php",
        "setup.ts",
        "upstream.json",
        "../tooling.ts",
      ]),
      toolingSha256: yield* treeHash(join(repository, "scripts/lib")),
      toolingLockSha256: sha256(
        yield* fs.readFile(join(repository, "pnpm-lock.yaml")),
      ),
    };
  });
export const summarizeRuntime = (
  manifest: Manifest,
  results: ReadonlyArray<Result>,
  stable: boolean,
) => {
  const selected = new Map(results.map((result) => [result.file, result]));
  const incomplete = manifest.files
    .filter(
      (entry) =>
        !selected.get(entry.path)?.report.complete ||
        selected.get(entry.path)?.timedOut,
    )
    .map((entry) => entry.path);
  const unaccounted = manifest.files.flatMap((entry) =>
    entry.cases
      .filter(
        (entryCase) =>
          !selected.has(entry.path) ||
          selected.get(entry.path)!.unaccountedCases.includes(entryCase.id),
      )
      .map((entryCase) => entryCase.id),
  );
  const sum = (key: "pass" | "fail" | "pending" | "discovered") =>
    results.reduce((total, result) => total + (result.report[key] ?? 0), 0);
  const values = {
    complete: incomplete.length === 0,
    expectedTests: manifest.tests,
    discovered: sum("discovered"),
    incompleteFiles: incomplete,
    unaccountedTests: unaccounted.length,
    unaccountedCases: unaccounted,
    caseIdentityMatches: results.every((result) => result.caseIdentityMatches),
    pass: sum("pass"),
    fail: sum("fail"),
    pending: sum("pending"),
    loadErrors: results.reduce(
      (count, result) => count + (result.report.loadErrors?.length ?? 0),
      0,
    ),
    asynchronousErrors: results.reduce(
      (count, result) =>
        count + (result.report.asynchronousErrors?.length ?? 0),
      0,
    ),
  };
  return {
    ...values,
    success:
      stable &&
      values.complete &&
      values.caseIdentityMatches &&
      unaccounted.length === 0 &&
      values.pass === values.expectedTests &&
      values.fail === 0 &&
      values.pending === 0 &&
      values.loadErrors === 0 &&
      values.asynchronousErrors === 0 &&
      results.every(
        (result) => result.exitCode === 0 && result.timeoutScaleMatches,
      ),
  };
};
export const command = script("express-compatibility", {
  description:
    "Run original Express cases locally, retaining complete identities and reports.",
  flags: {
    runtime: Flag.Literals("runtime", ["node", "phasis", "both"]).pipe(
      Flag.withDefault("both"),
    ),
    jobs: Flag.Int("jobs").pipe(Flag.withDefault(1)),
    timeout: Flag.Finite("timeout").pipe(Flag.withDefault(1800)),
    scale: Flag.String("timeout-scale").pipe(Flag.optional),
    output: Flag.String("output"),
  },
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(
          input,
          { status: "planned", runtime: input.runtime, output: input.output },
          JSON.stringify,
        );
      yield* localOnly();
      if (![1, 2].includes(input.jobs) || input.timeout <= 0)
        return yield* fail("Use 1 or 2 jobs and a positive process timeout", 2);
      const scale = yield* attempt(() =>
        timeoutScale(
          Option.getOrElse(
            input.scale,
            () => process.env.PHASIS_FRAMEWORK_TIMEOUT_SCALE ?? "1",
          ),
        ),
      ).pipe(Effect.catch((error) => fail(error.message, 2)));
      const fs = yield* FileSystem.FileSystem;
      const output = resolve(input.output);
      yield* insist(
        !(yield* fs.exists(output)),
        "--output must name a new directory",
      );
      yield* fs.makeDirectory(output, { recursive: true });
      const manifest = yield* readJson<Manifest>(join(here, "manifest.json"));
      const source = yield* readJson<object>(join(here, "upstream.json"));
      const before = yield* sourceIdentity(scale);
      const runtimes =
        input.runtime === "both" ? ["node", "phasis"] : [input.runtime];
      const results: Result[] = [];
      let successful = false;
      yield* Effect.forEach(
        runtimes.flatMap((runtime) =>
          manifest.files.map((entry) => ({ runtime, entry })),
        ),
        ({ runtime, entry }) =>
          Effect.gen(function* () {
            const stem = entry.path.replaceAll("/", "__").replace(/\.js$/, "");
            const file = join(output, stem + "-" + runtime + ".json");
            const log = file.replace(/\.json$/, ".log");
            const argv =
              runtime === "node"
                ? [process.execPath, join(here, "run-node.cjs")]
                : [
                    "php",
                    "-d",
                    "memory_limit=1G",
                    join(here, "run-phasis.php"),
                  ];
            argv.push(
              "--file=" + entry.path,
              "--output=" + file,
              "--timeout-scale=" + scale,
            );
            const started = performance.now();
            const captured = yield* execute(
              argv,
              input.timeout,
              "main",
              log,
              scale,
            );
            const partial = file + ".partial.json";
            const reportFile = (yield* fs.exists(file)) ? file : partial;
            const report = (yield* fs.exists(reportFile))
              ? yield* readJson<Report>(reportFile).pipe(
                  Effect.catch(() =>
                    Effect.succeed({ complete: false } as Report),
                  ),
                )
              : {};
            const outcomes = new Set(
              report.tests?.map((entry) => entry.id) ?? [],
            );
            const result: Result = {
              runtime,
              file: entry.path,
              expectedTests: entry.tests,
              exitCode: captured.timedOut ? null : captured.status,
              timedOut: captured.timedOut,
              log: basename(log),
              elapsedSeconds: (performance.now() - started) / 1000,
              caseIdentityMatches: isDeepStrictEqual(
                report.caseManifest ?? [],
                entry.cases,
              ),
              unaccountedCases: entry.cases
                .filter((entry) => !outcomes.has(entry.id))
                .map((entry) => entry.id),
              timeoutScaleMatches: report.timeoutScale === scale,
              report,
            };
            results.push(result);
            yield* fs.writeFileString(
              join(output, "progress.json"),
              JSON.stringify(results, null, 2) + "\n",
            );
          }),
        { concurrency: input.jobs },
      ).pipe(
        Effect.onExit((exit) =>
          Effect.gen(function* () {
            const after = yield* sourceIdentity(scale);
            const stable = isDeepStrictEqual(before, after);
            const summaries = Object.fromEntries(
              runtimes.map((runtime) => [
                runtime,
                summarizeRuntime(
                  manifest,
                  results.filter((result) => result.runtime === runtime),
                  stable,
                ),
              ]),
            );
            successful =
              Exit.isSuccess(exit) &&
              stable &&
              Object.values(summaries).every((row) => row.success);
            const summary = {
              generatedAt: new Date().toISOString(),
              source,
              manifest,
              filtered: false,
              identityBefore: before,
              identityAfter: after,
              stableSource: stable,
              interrupted: Exit.isFailure(exit),
              execution: {
                isolation: "file",
                workers: input.jobs,
                timeoutSeconds: input.timeout,
                timeoutScale: scale,
              },
              runtimes: summaries,
              files: results,
            };
            yield* fs.writeFileString(
              join(output, "summary.json"),
              JSON.stringify(summary, null, 2) + "\n",
            );
          }),
        ),
      );
      yield* emit(input, { output, success: successful }, JSON.stringify);
      if (!successful)
        return yield* fail(
          "Express compatibility failed; all available reports were retained",
        );
    }),
});
if (import.meta.main) main(command);
