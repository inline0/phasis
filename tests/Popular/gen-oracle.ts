import { join, resolve } from "node:path";
import * as Argument from "effect/cli/Argument";
import * as Console from "effect/Console";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { fail, main, script } from "../../scripts/lib/cli.ts";
import * as Command from "../../scripts/lib/command.ts";

export const command = script("popular-oracle", {
  description:
    "Capture unchanged popular-package observations in an isolated native Node realm.",
  flags: { directory: Argument.String("package-directory") },
  run: (input) =>
    Effect.gen(function* () {
      const fs = yield* FileSystem.FileSystem;
      const directory = resolve(input.directory);
      const library = (yield* fs.readDirectory(directory)).find(
        (file) =>
          file.endsWith(".js") &&
          file !== "runner.js" &&
          !file.startsWith("oracle"),
      );
      if (library === undefined)
        return yield* fail("No library .js file found in " + directory);
      if (input.dryRun)
        return yield* Console.log(
          JSON.stringify({ directory, library, status: "planned" }),
        );
      const lib = yield* fs.readFileString(join(directory, library));
      const runner = yield* fs.readFileString(join(directory, "runner.js"));
      const result = yield* Command.run({
        command: process.execPath,
        args: [join(import.meta.dirname, "probes/vm.cjs")],
        stdin: JSON.stringify({ dir: input.directory, lib, runner }),
      });
      if (input.json)
        yield* Console.log(JSON.stringify({ output: result.stdout }));
      else yield* Effect.sync(() => process.stdout.write(result.stdout));
    }),
});
if (import.meta.main) main(command);
