import { resolve } from "node:path";
import * as zlib from "node:zlib";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { emit, main, script } from "../lib/cli.ts";
import * as Command from "../lib/command.ts";
import { attempt } from "../lib/files.ts";

const directory = resolve(
  import.meta.dirname,
  "../../tests/Unit/Node/fixtures/primitives",
);
const compression = {
  gzip: zlib.gzipSync,
  deflate: zlib.deflateSync,
  deflateRaw: zlib.deflateRawSync,
  brotliCompress: zlib.brotliCompressSync,
};
const names = [
  "buffer",
  "events",
  "stream",
  "stream-web",
  "fs",
  "zlib",
  "vm",
  "child-process",
];
export const command = script("generate-primitive-oracles", {
  description: "Capture original Node primitive observations with native Node.",
  flags: {},
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(
          input,
          { status: "planned", fixtures: names },
          JSON.stringify,
        );
      const fs = yield* FileSystem.FileSystem;
      const bytes = Buffer.from("hello 🌍\0 binary\xff hello hello");
      const compressed = Object.fromEntries(
        Object.entries(compression).map(([name, compress]) => [
          name,
          compress(bytes).toString("base64"),
        ]),
      );
      yield* fs.writeFileString(
        resolve(directory, "zlib-inputs.json"),
        JSON.stringify(compressed, null, 2) + "\n",
      );
      const observations: Record<string, number> = {};
      for (const name of names) {
        const result = yield* Command.run({
          command: process.execPath,
          args: [resolve(directory, name + ".cjs")],
          timeout: "15 seconds",
        });
        const value = yield* attempt(
          () => JSON.parse(result.stdout.trim()) as unknown[],
        );
        yield* fs.writeFileString(
          resolve(directory, name + ".json"),
          JSON.stringify(value, null, 2) + "\n",
        );
        yield* fs.writeFileString(
          resolve(directory, name + ".mjs"),
          `import './${name}.cjs';\n`,
        );
        observations[name] = value.length;
      }
      yield* emit(input, observations, JSON.stringify);
    }),
});
if (import.meta.main) main(command);
