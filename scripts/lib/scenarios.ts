import { basename, dirname, join, relative, resolve, sep } from "node:path";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { fail } from "./cli.ts";
import * as Command from "./command.ts";
import { readJson } from "./files.ts";

export const root = resolve(import.meta.dirname, "../..");
export const discover = (category = "", repository = root) =>
  Effect.gen(function* () {
    const fs = yield* FileSystem.FileSystem;
    const directory = resolve(repository, "scenarios", category);
    const parent = join(repository, "scenarios");
    if (directory !== parent && !directory.startsWith(parent + sep)) {
      return yield* fail("Scenario category must remain inside scenarios.");
    }
    if (!(yield* fs.exists(directory))) return [];
    return (yield* fs.readDirectory(directory, { recursive: true }))
      .filter((file) => file.split(sep).slice(-2).join("/") === "setup/test.js")
      .sort()
      .map((file) => dirname(dirname(join(directory, file))));
  });
export const locate = (name: string, repository = root) =>
  Effect.gen(function* () {
    const scenarios = yield* discover("", repository);
    const matches = scenarios.filter(
      (path) =>
        relative(join(repository, "scenarios"), path).split(sep).join("/") ===
          name || basename(path) === name,
    );
    if (matches.length !== 1) {
      return yield* fail(
        matches.length === 0
          ? `Scenario not found: ${name}`
          : `Ambiguous scenario name: ${name}; use its category path.`,
      );
    }
    return matches[0];
  });
export const mode = (directory: string) =>
  Effect.gen(function* () {
    const fs = yield* FileSystem.FileSystem;
    const manifest = join(directory, "scenario.json");
    if (!(yield* fs.exists(manifest))) return "parse";
    return (yield* readJson<{ mode?: string }>(manifest)).mode ?? "parse";
  });
export const capture = (
  directory: string,
  kind: "actual" | "oracle",
  dryRun: boolean,
  refresh = false,
) =>
  Effect.gen(function* () {
    const fs = yield* FileSystem.FileSystem;
    const file = join(directory, kind, "output.txt");
    if (kind === "oracle" && !refresh && (yield* fs.exists(file)))
      return "existing";
    if (dryRun) return "planned";
    const goal = yield* mode(directory);
    const entry = join(directory, "setup/test.js");
    const result = yield* Command.run(
      kind === "actual"
        ? {
            command: "php",
            args: [
              join(root, "bin/phasis"),
              ...(goal === "exec" ? [] : ["--ast"]),
              entry,
            ],
            check: false,
          }
        : goal === "exec"
          ? {
              command: process.execPath,
              args: [entry],
              check: false,
            }
          : {
              command: process.execPath,
              args: [join(root, "scripts/parse-oracle.ts"), entry],
              check: false,
            },
    );
    yield* fs.makeDirectory(dirname(file), { recursive: true });
    yield* fs.writeFileString(file, result.stdout + result.stderr);
    return result.exitCode === 0 ? "captured" : "error";
  });
export const compare = (directory: string, writeReport: boolean) =>
  Effect.gen(function* () {
    const fs = yield* FileSystem.FileSystem;
    const expected = yield* fs.readFileString(
      join(directory, "oracle/output.txt"),
    );
    const actual = yield* fs.readFileString(
      join(directory, "actual/output.txt"),
    );
    const result = {
      status: actual === expected ? "pass" : "fail",
      scenario: basename(directory),
    };
    if (writeReport) {
      const output = join(directory, "reports");
      yield* fs.makeDirectory(output, { recursive: true });
      yield* fs.writeFileString(
        join(output, "comparison.json"),
        JSON.stringify(result) + "\n",
      );
    }
    return result;
  });
