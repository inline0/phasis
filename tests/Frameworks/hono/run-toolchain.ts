import { join } from "node:path";
import * as Flag from "effect/cli/Flag";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { emit, fail, main, script } from "../../../scripts/lib/cli.ts";
import { readJson } from "../../../scripts/lib/files.ts";
import { execute, localOnly, runId, sha256 } from "../tooling.ts";
import { directory, inventory } from "./inventory.ts";

export const command = script("hono-declaration-checks", {
  description:
    "Check original Hono declarations using the pinned native toolchain, locally.",
  flags: { timeout: Flag.Finite("timeout").pipe(Flag.withDefault(180)) },
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(input, { status: "planned" }, JSON.stringify);
      yield* localOnly();
      if (input.timeout <= 0)
        return yield* fail("--timeout must be positive", 2);
      const fs = yield* FileSystem.FileSystem;
      const id = runId();
      const archive = join(directory, "results/runs", id);
      yield* fs.makeDirectory(archive, { recursive: true });
      const upstream = yield* inventory();
      const dependencies = (yield* readJson<{
        devDependencies: Record<string, string>;
      }>(join(directory, "../package.json"))).devDependencies;
      const manifest: {
        id: string;
        project: string;
        host: string;
        upstreamProjects: typeof upstream;
        selected: string[];
        dependencies: Record<string, string>;
        lockSha256: string;
        status?: string;
        counts?: object;
        exitCode?: number;
      } = {
        id,
        project: "toolchain",
        host: "native TypeScript compiler",
        upstreamProjects: upstream,
        selected: upstream.projects.main.toolchainFiles ?? [],
        dependencies: Object.fromEntries(
          ["hono", "typescript", "vitest"].map((name) => [
            name,
            dependencies[name],
          ]),
        ),
        lockSha256: sha256(
          yield* fs.readFile(join(directory, "../package-lock.json")),
        ),
      };
      let result = yield* execute(
        [process.execPath, join(directory, "prepare-toolchain.ts")],
        input.timeout,
        "toolchain",
        join(archive, "prepare.log"),
      );
      if (result.status) manifest.status = "preparation-blocked";
      else {
        const reportFile = join(archive, "native.json");
        result = yield* execute(
          [
            process.execPath,
            join(directory, "../node_modules/vitest/vitest.mjs"),
            "run",
            "--config",
            join(directory, "vitest.config.mjs"),
            "--reporter=json",
            "--outputFile=" + reportFile,
          ],
          input.timeout,
          "toolchain",
          join(archive, "native.log"),
        );
        if (yield* fs.exists(reportFile)) {
          const report =
            yield* readJson<Record<string, number | boolean>>(reportFile);
          manifest.counts = Object.fromEntries(
            [
              "numTotalTests",
              "numPassedTests",
              "numFailedTests",
              "numPendingTests",
            ].map((name) => [name, report[name]]),
          );
          manifest.status =
            result.status === 0 && report.success && report.numTotalTests
              ? "pass"
              : "fail";
        } else manifest.status = "process-blocked";
      }
      manifest.exitCode = result.status;
      yield* fs.writeFileString(
        join(archive, "manifest.json"),
        JSON.stringify(manifest, null, 2),
      );
      yield* emit(
        input,
        { manifest: join(archive, "manifest.json"), ...manifest },
        JSON.stringify,
      );
      if (manifest.status !== "pass")
        return yield* fail("Hono declaration checks failed");
    }),
});
if (import.meta.main) main(command);
