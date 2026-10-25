import { join, resolve } from "node:path";
import { isDeepStrictEqual } from "node:util";
import * as Argument from "effect/cli/Argument";
import * as Flag from "effect/cli/Flag";
import * as Effect from "effect/Effect";
import * as Exit from "effect/Exit";
import * as FileSystem from "effect/FileSystem";
import * as Option from "effect/Option";
import { emit, fail, insist, main, script } from "../../../scripts/lib/cli.ts";
import { attempt } from "../../../scripts/lib/files.ts";
import {
  execute,
  filesHash,
  localOnly,
  phpMemoryLimit,
  repository,
  runId,
  sha256,
  timeoutScale,
  treeHash,
} from "../tooling.ts";
import { inventory } from "./inventory.ts";

export const directory = import.meta.dirname;
export const prefix = "__PHASIS_HONO_REPORT__";
export interface TestResult {
  readonly id: string;
  readonly name: string;
  readonly status: string;
  readonly assertions?: unknown;
  readonly originalTimeout?: number | string;
  readonly effectiveTimeout?: number | string;
  readonly error?: {
    readonly message?: string;
    readonly originalTimeout?: number;
    readonly effectiveTimeout?: number;
  };
}
export interface Report {
  readonly timeoutScale: number;
  readonly results: TestResult[];
  readonly counts: Record<string, number>;
  readonly suiteErrors?: unknown[];
}
export const sourceHash = () =>
  treeHash(join(repository, "src"), false, [".php", ".cjs", ".mjs"]);
export const harnessHash = () =>
  filesHash(directory, [
    "build.ts",
    "harness.mjs",
    "deadline.mjs",
    "run.ts",
    "run-php.php",
    "inventory.ts",
    "upstream.json",
    "../tooling.ts",
    "../setup.ts",
  ]);
export const dependenciesHash = () =>
  Effect.gen(function* () {
    const fs = yield* FileSystem.FileSystem;
    return sha256(yield* fs.readFile(join(directory, "../package-lock.json")));
  });
export const selectedFiles = (
  available: ReadonlyArray<string>,
  native: ReadonlyArray<string>,
  requested: ReadonlyArray<string>,
) => {
  const files = requested.length === 0 ? [...available] : [...requested];
  if (new Set(files).size !== files.length)
    throw new Error("Each upstream file may appear only once in a run");
  for (const file of files) {
    if (native.includes(file))
      throw new Error(
        file + " is a native TypeScript check; use run-toolchain.ts",
      );
    if (!available.includes(file))
      throw new Error(file + " is not in the selected upstream project");
  }
  return files;
};
interface Verdict {
  readonly [name: string]: string | number | undefined;
}
interface FileRow {
  readonly file: string;
  readonly sha256: string;
  readonly runtimes: Record<string, Verdict>;
  readonly elapsedSeconds: Record<string, number>;
  build?: { status: string; exitCode: number };
  comparison?: string;
}
export const classify = (
  status: number,
  reports: ReadonlyArray<Report>,
  scale: number,
): Verdict => {
  if (status !== 0 || reports.length !== 1)
    return { status: "process-blocked", exitCode: status };
  const report = reports[0];
  if (report.timeoutScale !== scale)
    return { status: "timeout-configuration-mismatch", exitCode: status };
  if (!report.results?.length)
    return { status: "empty-discovery", exitCode: status };
  return {
    ...report.counts,
    ...(report.suiteErrors?.length
      ? { "suite-failures": report.suiteErrors.length }
      : {}),
  };
};
export const command = script("hono-compatibility", {
  description:
    "Run unchanged Hono cases locally with bounded workers and complete case identities.",
  flags: {
    files: Argument.String("fixture").pipe(Argument.variadic()),
    timeout: Flag.Finite("timeout").pipe(Flag.withDefault(1800)),
    scale: Flag.String("timeout-scale").pipe(Flag.optional),
    project: Flag.Literals("project", [
      "main",
      "jsx-runtime-default",
      "jsx-runtime-dom",
      "node",
    ]).pipe(Flag.withDefault("main")),
    runtime: Flag.Literals("runtime", ["node", "phasis", "both"]).pipe(
      Flag.withDefault("both"),
    ),
    noBuild: Flag.Boolean("no-build").pipe(Flag.withDefault(false)),
    memory: Flag.String("php-memory-limit").pipe(Flag.withDefault("4G")),
  },
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(
          input,
          {
            status: "planned",
            files: input.files,
            project: input.project,
            runtime: input.runtime,
          },
          JSON.stringify,
        );
      yield* localOnly();
      if (input.timeout <= 0)
        return yield* fail("--timeout must be positive", 2);
      const options = yield* attempt(() => ({
        scale: timeoutScale(
          Option.getOrElse(
            input.scale,
            () => process.env.PHASIS_FRAMEWORK_TIMEOUT_SCALE ?? "1",
          ),
        ),
        memory: phpMemoryLimit(input.memory),
      })).pipe(Effect.catch((error) => fail(error.message, 2)));
      const fs = yield* FileSystem.FileSystem;
      const upstream = yield* inventory();
      const available =
        upstream.projects[input.project].runtimeFiles ??
        upstream.projects[input.project].files;
      const files = yield* attempt(() =>
        selectedFiles(
          available,
          upstream.projects.main.toolchainFiles ?? [],
          input.files,
        ),
      ).pipe(Effect.catch((error) => fail(error.message, 2)));
      yield* insist(
        files.length > 0,
        "The selected upstream project contains no runtime files",
      );
      const id = runId();
      const results = join(directory, "results");
      const archive = join(results, "runs", id);
      yield* fs.makeDirectory(archive, { recursive: true });
      const rows: FileRow[] = [];
      const before = {
        runtime: yield* sourceHash(),
        harness: yield* harnessHash(),
        dependencies: yield* dependenciesHash(),
        tooling: yield* treeHash(join(repository, "scripts/lib")),
        toolingLock: sha256(
          yield* fs.readFile(join(repository, "pnpm-lock.yaml")),
        ),
      };
      const metadata = {
        id,
        upstream: upstream.upstream,
        project: input.project,
        inventory: available,
        upstreamProjects: upstream,
        selected: files,
        runtimes: input.runtime,
        timeoutSeconds: input.timeout,
        phpMemoryLimit: options.memory,
        timeoutScale: options.scale,
        timeouts: {
          test: {
            originalTimeout: 5000,
            effectiveTimeout: 5000 * options.scale,
          },
          hook: {
            originalTimeout: 10000,
            effectiveTimeout: 10000 * options.scale,
          },
        },
        nativeVitestTimeouts:
          "unchanged; configure the independent native runner separately",
        runtimeSourceAtStart: before.runtime,
        harnessSourceAtStart: before.harness,
        dependenciesAtStart: before.dependencies,
        toolingSourceAtStart: before.tooling,
        toolingLockAtStart: before.toolingLock,
        complete: false,
        files: rows,
      };
      const save = (name: string, contents: string) =>
        fs
          .writeFileString(join(archive, name), contents)
          .pipe(
            Effect.andThen(fs.writeFileString(join(results, name), contents)),
          );
      const saveManifest = () =>
        save("manifest.json", JSON.stringify(metadata, null, 2));
      yield* saveManifest();
      let changed = false;
      yield* Effect.gen(function* () {
        for (const name of files) {
          const identifier = name.replaceAll("/", "__").replace(/\.[^.]+$/, "");
          const row: FileRow = {
            file: name,
            sha256: sha256(
              yield* fs.readFile(
                resolve(directory, upstream.upstream.directory, name),
              ),
            ),
            runtimes: {},
            elapsedSeconds: {},
          };
          rows.push(row);
          const caseReports: Record<
            string,
            Array<[string, string, string]>
          > = {};
          yield* saveManifest();
          if (!input.noBuild) {
            const built = yield* execute(
              [process.execPath, join(directory, "build.ts"), name],
              input.timeout,
              input.project,
              undefined,
              options.scale,
            );
            yield* save(identifier + "-build.log", built.output);
            if (built.status) {
              row.build = { status: "build-blocked", exitCode: built.status };
              yield* saveManifest();
              continue;
            }
          }
          for (const runtime of input.runtime === "both"
            ? ["node", "phasis"]
            : [input.runtime]) {
            row.runtimes[runtime] = { status: "running" };
            yield* saveManifest();
            const generated = resolve(
              directory,
              ".generated",
              ...(input.project === "main" ? [] : [input.project]),
              identifier + ".mjs",
            );
            const argv =
              runtime === "node"
                ? [process.execPath, generated]
                : [
                    "php",
                    "-d",
                    "memory_limit=" + options.memory,
                    join(directory, "run-php.php"),
                    generated,
                  ];
            const started = performance.now();
            const captured = yield* execute(
              argv,
              input.timeout,
              input.project,
              join(archive, identifier + "-" + runtime + ".log"),
              options.scale,
            );
            row.elapsedSeconds[runtime] =
              Math.round(performance.now() - started) / 1000;
            yield* save(identifier + "-" + runtime + ".log", captured.output);
            const reports = yield* attempt(() =>
              captured.output
                .split("\n")
                .filter((line) => line.startsWith(prefix))
                .map((line) => JSON.parse(line.slice(prefix.length)) as Report),
            );
            const verdict = classify(captured.status, reports, options.scale);
            row.runtimes[runtime] = verdict;
            if (!verdict.status) {
              yield* save(
                identifier + "-" + runtime + ".json",
                JSON.stringify(reports[0], null, 2),
              );
              caseReports[runtime] = reports[0].results.map((entry) => [
                entry.id,
                entry.name,
                entry.status,
              ]);
            }
          }
          if (
            caseReports.node &&
            caseReports.phasis &&
            !isDeepStrictEqual(caseReports.node, caseReports.phasis)
          )
            row.comparison = "case-results-differ";
          yield* saveManifest();
        }
      }).pipe(
        Effect.onExit((exit) =>
          Effect.gen(function* () {
            const after = {
              runtime: yield* sourceHash(),
              harness: yield* harnessHash(),
              dependencies: yield* dependenciesHash(),
              tooling: yield* treeHash(join(repository, "scripts/lib")),
              toolingLock: sha256(
                yield* fs.readFile(join(repository, "pnpm-lock.yaml")),
              ),
            };
            changed = !isDeepStrictEqual(before, after);
            Object.assign(metadata, {
              runtimeSourceAtEnd: after.runtime,
              runtimeChangedDuringRun: before.runtime !== after.runtime,
              harnessSourceAtEnd: after.harness,
              harnessChangedDuringRun: before.harness !== after.harness,
              dependenciesAtEnd: after.dependencies,
              dependenciesChangedDuringRun:
                before.dependencies !== after.dependencies,
              toolingChangedDuringRun:
                before.tooling !== after.tooling ||
                before.toolingLock !== after.toolingLock,
              complete: Exit.isSuccess(exit),
            });
            yield* saveManifest();
          }),
        ),
      );
      const failed =
        changed ||
        rows.some(
          (row) =>
            row.build ||
            row.comparison ||
            Object.values(row.runtimes).some(
              (report) =>
                report.status ||
                report.fail ||
                report["harness-blocked"] ||
                report["suite-blocked"] ||
                report["suite-failures"],
            ),
        );
      yield* emit(
        input,
        { manifest: join(archive, "manifest.json"), failed },
        JSON.stringify,
      );
      if (failed)
        return yield* fail(
          "Hono compatibility failed; all available reports were retained",
        );
    }),
});
if (import.meta.main) main(command);
