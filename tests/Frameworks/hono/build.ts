import { builtinModules } from "node:module";
import { dirname, relative, resolve } from "node:path";
import { pathToFileURL } from "node:url";
import * as Argument from "effect/cli/Argument";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { build } from "../../../scripts/lib/build.ts";
import { emit, insist, main, script } from "../../../scripts/lib/cli.ts";
import { readJson } from "../../../scripts/lib/files.ts";
import { localOnly, sha256 } from "../tooling.ts";
import type { Metadata } from "./inventory.ts";

const directory = import.meta.dirname;
export const command = script("build-hono", {
  description:
    "Prepare the original Hono fixture graph for local native and PHP workers.",
  flags: { files: Argument.String("fixture").pipe(Argument.variadic()) },
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(
          input,
          { status: "planned", files: input.files },
          JSON.stringify,
        );
      yield* localOnly();
      const fs = yield* FileSystem.FileSystem;
      const metadata = yield* readJson<Metadata>(
        resolve(directory, "upstream.json"),
      );
      const upstream = resolve(directory, metadata.directory);
      const project = process.env.PHASIS_HONO_PROJECT || "main";
      const output = resolve(
        directory,
        ".generated",
        ...(project === "main" ? [] : [project]),
      );
      const modules = resolve(output, "modules");
      yield* fs.makeDirectory(modules, { recursive: true });
      const writeGenerated = (path: string, contents: string | Uint8Array) =>
        Effect.acquireUseRelease(
          fs.writeFile(
            path + "." + process.pid + ".tmp",
            typeof contents === "string"
              ? new TextEncoder().encode(contents)
              : contents,
          ),
          () => fs.rename(path + "." + process.pid + ".tmp", path),
          () =>
            fs
              .remove(path + "." + process.pid + ".tmp", { force: true })
              .pipe(Effect.orDie),
        );
      const stamp =
        metadata.commit +
        ":cjs-graph-1:" +
        sha256(yield* fs.readFile(import.meta.filename));
      const stampFile = resolve(output, "stamp");
      const previous = (yield* fs.exists(stampFile))
        ? yield* fs.readFileString(stampFile)
        : undefined;
      if (previous !== stamp) {
        const files: string[] = [];
        const assets: string[] = [];
        for (const area of ["src", "scripts", "build", "runtime-tests"]) {
          const path = resolve(upstream, area);
          if (!(yield* fs.exists(path))) continue;
          for (const name of yield* fs.readDirectory(path, {
            recursive: true,
          })) {
            const file = resolve(path, name);
            if ((yield* fs.stat(file)).type !== "File") continue;
            if (/\.(tsx?|js)$/.test(file) && !file.endsWith(".d.ts"))
              files.push(file);
            else assets.push(file);
          }
        }
        const result = yield* build({
          entryPoints: files,
          outbase: upstream,
          outdir: modules,
          bundle: false,
          format: "cjs",
          platform: "node",
          target: "es2022",
          write: false,
          jsx: "automatic",
          jsxImportSource: resolve(
            modules,
            project === "jsx-runtime-dom" ? "src/jsx/dom" : "src/jsx",
          ),
          supported: { "dynamic-import": false },
          define: {
            "import.meta.url": "__honoSourceUrl",
            "import.meta.dirname": "__honoSourceDirectory",
            "import.meta.filename": "__honoSourceFile",
            __filename: "__honoSourceFile",
            __dirname: "__honoSourceDirectory",
          },
          logLevel: "warning",
        });
        const originalFiles = new Map(
          files.map((file) => [
            resolve(
              modules,
              relative(upstream, file).replace(/\.tsx?$/, ".js"),
            ),
            file,
          ]),
        );
        for (const file of result.outputFiles!) {
          const original = originalFiles.get(file.path);
          yield* insist(
            original !== undefined,
            "Unknown transpiled source: " + file.path,
          );
          const metadata = `var __honoSourceUrl = ${JSON.stringify(pathToFileURL(original!).href)}, __honoSourceFile = ${JSON.stringify(original)}, __honoSourceDirectory = ${JSON.stringify(dirname(original!))};\n`;
          yield* fs.makeDirectory(dirname(file.path), { recursive: true });
          const contents = file.text
            .replace(
              "get: all[name], enumerable: true",
              "get: all[name], enumerable: true, configurable: true",
            )
            .replace(
              "get: () => from[key], enumerable:",
              "get: () => from[key], configurable: true, enumerable:",
            )
            .replace(
              "__toESM = (mod, isNodeMode, target) => (target =",
              "__toESM = (mod, isNodeMode, target) => mod?.__esModule ? mod : (target =",
            );
          yield* writeGenerated(
            file.path,
            '"use strict";\n' + metadata + contents,
          );
        }
        for (const file of assets) {
          const target = resolve(modules, relative(upstream, file));
          yield* fs.makeDirectory(dirname(target), { recursive: true });
          yield* writeGenerated(target, yield* fs.readFile(file));
        }
        yield* writeGenerated(
          resolve(modules, "package.json"),
          JSON.stringify({ type: "commonjs" }),
        );
        const vitest = resolve(modules, "node_modules/vitest");
        yield* fs.makeDirectory(vitest, { recursive: true });
        yield* writeGenerated(
          resolve(vitest, "package.json"),
          JSON.stringify({ name: "vitest", main: "index.cjs" }),
        );
        yield* writeGenerated(
          resolve(vitest, "index.cjs"),
          "module.exports = require(" +
            JSON.stringify(resolve(output, "harness.cjs")) +
            ");\n",
        );
        yield* writeGenerated(stampFile, stamp);
      }
      const harness = yield* build({
        entryPoints: [resolve(directory, "harness.mjs")],
        outfile: resolve(output, "harness.cjs"),
        bundle: true,
        format: "cjs",
        platform: "node",
        target: "es2022",
        write: false,
        external: ["node:*", ...builtinModules],
        logLevel: "warning",
      });
      for (const file of harness.outputFiles!)
        yield* writeGenerated(file.path, '"use strict";\n' + file.text);
      const outputs: string[] = [];
      for (const name of input.files) {
        const file = resolve(modules, name.replace(/\.tsx?$/, ".js"));
        yield* fs.stat(file);
        const target = resolve(
          output,
          name.replace(/[\\/]/g, "__").replace(/\.(tsx?|js)$/, ".mjs"),
        );
        const entry = `import {createRequire} from 'node:module';
const require = createRequire(import.meta.url);
const harness = require(${JSON.stringify(resolve(output, "harness.cjs"))});
globalThis.__honoCase ||= value => process.stdout.write('__PHASIS_HONO_CASE__' + value + '\\n');
globalThis.__honoResetModules = () => {
  for (const key of Object.keys(require.cache)) {
    if (key.startsWith(${JSON.stringify(modules + "/")})) delete require.cache[key];
  }
};
require(${JSON.stringify(file)});
harness.run().then(report => {
  globalThis.__honoReport = report;
  if (globalThis.__honoComplete) globalThis.__honoComplete();
  else process.stdout.write('__PHASIS_HONO_REPORT__' + JSON.stringify(report) + '\\n', () => process.exit(0));
});
`;
        yield* writeGenerated(target, entry);
        outputs.push(target);
      }
      yield* emit(input, { outputs }, (value) => value.outputs.join("\n"));
    }),
});
if (import.meta.main) main(command);
