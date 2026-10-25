import vm from "node:vm";
import { resolve } from "node:path";
import * as Argument from "effect/cli/Argument";
import * as Console from "effect/Console";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { main, script } from "./lib/cli.ts";
import { attempt } from "./lib/files.ts";

export const command = script("parse-oracle", {
  description: "Parse source with the committed Acorn reference package.",
  flags: { file: Argument.String("file") },
  run: (input) =>
    Effect.gen(function* () {
      const fs = yield* FileSystem.FileSystem;
      const source = yield* fs.readFileString(input.file);
      if (input.dryRun)
        return yield* Console.log(
          JSON.stringify({ file: input.file, status: "planned" }),
        );
      const context = vm.createContext({});
      const reference = yield* fs.readFileString(
        resolve(import.meta.dirname, "../tests/Popular/acorn/acorn.js"),
      );
      yield* attempt(() => vm.runInContext(reference, context));
      const acorn = context.acorn as {
        parse: (source: string, options: { ecmaVersion: "latest" }) => unknown;
      };
      const ast = yield* attempt(() =>
        acorn.parse(source, { ecmaVersion: "latest" }),
      );
      yield* Console.log(JSON.stringify(ast, null, input.json ? undefined : 2));
    }),
});
if (import.meta.main) main(command);
