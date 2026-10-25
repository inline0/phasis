import { resolve } from "node:path";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { build } from "../../../scripts/lib/build.ts";
import { emit, main, script } from "../../../scripts/lib/cli.ts";
import { localOnly } from "../tooling.ts";

export const command = script("build-hono-controls", {
  description:
    "Prepare the original timeout and scheduling controls for local workers.",
  flags: {},
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(input, { status: "planned" }, JSON.stringify);
      yield* localOnly();
      const fs = yield* FileSystem.FileSystem;
      const directory = import.meta.dirname;
      const output = resolve(directory, ".generated/selftest");
      yield* fs.makeDirectory(output, { recursive: true });
      const names = ["timeouts", "scheduling", "timeout-scaling"];
      for (const name of names) {
        yield* build({
          entryPoints: [resolve(directory, "fixtures/" + name + "-entry.mjs")],
          outfile: resolve(output, name + ".cjs"),
          bundle: true,
          platform: "node",
          format: "cjs",
          target: "es2022",
          banner: { js: '"use strict";' },
          logLevel: "warning",
        });
        yield* fs.writeFileString(
          resolve(output, name + ".mjs"),
          "import {createRequire} from 'node:module';\nconst require = createRequire(import.meta.url);\nrequire('./" +
            name +
            ".cjs');\n",
        );
      }
      yield* emit(input, { controls: names }, JSON.stringify);
    }),
});
if (import.meta.main) main(command);
