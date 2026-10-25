import { createRequire } from "node:module";
import { join } from "node:path";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { build } from "../../../scripts/lib/build.ts";
import { emit, insist, main, script } from "../../../scripts/lib/cli.ts";

const here = import.meta.dirname;
export const command = script("build-react-vendor", {
  description:
    "Build the pinned real React oracle with one shared React instance.",
  flags: {},
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(input, { status: "planned" }, JSON.stringify);
      const fs = yield* FileSystem.FileSystem;
      const require = createRequire(join(here, "x.js"));
      const version = (require("react/package.json") as { version: string })
        .version;
      const output = join(here, "..", "vendor", "react-" + version);
      const temporary = join(here, ".vendor-entries");
      const entries = {
        react: "react",
        "jsx-runtime": "react/jsx-runtime",
        "react-dom": "react-dom",
        server: "react-dom/server.edge",
        static: "react-dom/static.edge",
      };
      yield* insist(
        !(yield* fs.exists(temporary)),
        "Vendor staging directory already exists: " + temporary,
      );
      yield* Effect.acquireUseRelease(
        Effect.sync(() => {
          const previous = process.env.NODE_ENV;
          process.env.NODE_ENV = "production";
          return previous;
        }),
        () =>
          Effect.acquireUseRelease(
            fs.makeDirectory(temporary),
            () =>
              Effect.gen(function* () {
                const entryPoints: Record<string, string> = {};
                for (const [name, module] of Object.entries(entries)) {
                  const keys = Object.keys(require(module) as object).filter(
                    (key) => /^[A-Za-z_$][\w$]*$/.test(key),
                  );
                  const file = join(temporary, name + ".js");
                  yield* fs.writeFileString(
                    file,
                    `import M from '${module}';\nexport const { ${keys.join(", ")} } = M;\nexport default M;\n`,
                  );
                  entryPoints[name] = file;
                }
                yield* fs.remove(output, { recursive: true, force: true });
                yield* build({
                  entryPoints,
                  outdir: output,
                  outExtension: { ".js": ".mjs" },
                  bundle: true,
                  splitting: true,
                  format: "esm",
                  platform: "neutral",
                  mainFields: ["main"],
                  conditions: [
                    "edge-light",
                    "worker",
                    "browser",
                    "production",
                    "default",
                  ],
                  define: { "process.env.NODE_ENV": '"production"' },
                  chunkNames: "chunk-[hash]",
                  legalComments: "inline",
                  logLevel: input.json ? "silent" : "warning",
                });
                yield* fs.copyFile(
                  join(here, "node_modules/react/LICENSE"),
                  join(output, "LICENSE"),
                );
              }),
            () => fs.remove(temporary, { recursive: true }).pipe(Effect.orDie),
          ),
        (previous) =>
          Effect.sync(() => {
            if (previous === undefined) delete process.env.NODE_ENV;
            else process.env.NODE_ENV = previous;
          }),
      );
      yield* emit(input, { version, output }, JSON.stringify);
    }),
});
if (import.meta.main) main(command);
