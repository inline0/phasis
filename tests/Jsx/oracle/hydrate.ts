import path from "node:path";
import { pathToFileURL } from "node:url";
import * as Argument from "effect/cli/Argument";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { build } from "../../../scripts/lib/build.ts";
import { emit, fail, main, script } from "../../../scripts/lib/cli.ts";
import * as Command from "../../../scripts/lib/command.ts";
import { attempt } from "../../../scripts/lib/files.ts";

const here = import.meta.dirname;
const root = path.resolve(here, "../../..");
interface Fixture {
  readonly hydrate?: boolean;
}
interface Report {
  readonly ok: boolean;
  readonly problems: string[];
}
export const command = script("hydrate", {
  description:
    "Check real React hydration against every original server-rendered fixture.",
  flags: { files: Argument.String("fixture").pipe(Argument.variadic()) },
  run: (input) =>
    Effect.gen(function* () {
      const fs = yield* FileSystem.FileSystem;
      const directory = path.resolve(here, "../react");
      const fixtures =
        input.files.length > 0
          ? input.files.map((file) => path.resolve(file))
          : (yield* fs.readDirectory(directory))
              .filter((file) => /\.(jsx|tsx)$/.test(file))
              .sort()
              .map((file) => path.join(directory, file));
      if (input.dryRun)
        return yield* emit(
          input,
          { status: "planned", fixtures },
          JSON.stringify,
        );
      const output = path.join(here, ".out/hydrate");
      yield* fs.makeDirectory(output, { recursive: true });
      const skipped: string[] = [];
      const reports: Array<{
        fixture: string;
        mode: string;
        ok: boolean;
        problems: string[];
      }> = [];
      for (const fixture of fixtures) {
        const name = path.basename(fixture, ".jsx");
        const server = yield* Command.run({
          command: "php",
          args: [
            "-d",
            "memory_limit=1G",
            path.join(root, "tests/Jsx/react-run.php"),
            fixture,
          ],
          cwd: root,
        });
        const { prerender } = yield* attempt(
          () => JSON.parse(server.stdout) as { prerender: string },
        );
        const bundle = path.join(output, name + ".mjs");
        yield* build({
          entryPoints: [fixture],
          outfile: bundle,
          bundle: true,
          format: "esm",
          platform: "node",
          jsx: "automatic",
          external: ["react", "react/*", "react-dom", "react-dom/*"],
          logLevel: "silent",
        });
        const module = yield* Effect.tryPromise(
          () => import(pathToFileURL(bundle).href) as Promise<Fixture>,
        );
        if (module.hydrate === false || prerender.startsWith("ERROR: ")) {
          skipped.push(
            name +
              (module.hydrate === false
                ? " (not hydratable by React itself)"
                : " (render error)"),
          );
          continue;
        }
        const htmlFile = path.join(output, name + ".html");
        yield* fs.writeFileString(htmlFile, prerender);
        for (const mode of ["production", "development"]) {
          const captured = yield* Command.run({
            command: process.execPath,
            args: [path.join(here, "probes/hydrate.mjs"), bundle, htmlFile],
            cwd: here,
            env: { NODE_ENV: mode, TZ: "UTC" },
            timeout: "30 seconds",
            check: false,
          }).pipe(
            Effect.map((result) =>
              result.exitCode === 0
                ? result.stdout
                : JSON.stringify({
                    ok: false,
                    problems: ["crashed: " + result.stderr],
                  }),
            ),
            Effect.catch((error) =>
              Effect.succeed(
                JSON.stringify({
                  ok: false,
                  problems: ["crashed: " + error.message],
                }),
              ),
            ),
          );
          const report = yield* attempt(
            () => JSON.parse(captured.trim().split("\n").at(-1)!) as Report,
          );
          reports.push({ fixture: name, mode, ...report });
        }
      }
      const failed = reports.filter((report) => !report.ok);
      yield* emit(
        input,
        {
          passed: reports.length - failed.length,
          checked: reports.length,
          failed,
          skipped,
        },
        (value) =>
          `hydration: ${value.passed}/${value.checked} passed\nskipped: ${value.skipped.join(", ")}` +
          value.failed
            .map(
              (report) =>
                `\nFAIL ${report.fixture} (${report.mode}): ${report.problems.join("; ")}`,
            )
            .join(""),
      );
      if (failed.length > 0) return yield* fail("Hydration failed");
    }),
});
if (import.meta.main) main(command);
