import path from "node:path";
import vm from "node:vm";
import type { Plugin } from "esbuild";
import * as Argument from "effect/cli/Argument";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { build } from "../../../scripts/lib/build.ts";
import { emit, fail, main, script } from "../../../scripts/lib/cli.ts";
import * as Command from "../../../scripts/lib/command.ts";
import { attempt } from "../../../scripts/lib/files.ts";

const here = import.meta.dirname;
const root = path.resolve(here, "../../..");
const jsxDirectory = path.resolve(here, "..");
interface Ast {
  readonly type: string;
  readonly [key: string]: unknown;
}
const isAst = (value: unknown): value is Ast =>
  typeof value === "object" &&
  value !== null &&
  "type" in value &&
  typeof value.type === "string";
export const summarize = (node: Ast, depth = 0): string[] => {
  const extra: string[] = [];
  for (const [field, label] of [
    ["name", "name"],
    ["operator", "op"],
    ["kind", "kind"],
  ]) {
    if (typeof node[field] === "string" && node[field] !== "")
      extra.push(label + "=" + node[field]);
  }
  if (
    node.value !== undefined &&
    node.value !== null &&
    typeof node.value !== "object"
  ) {
    extra.splice(
      typeof node.name === "string" && node.name !== "" ? 1 : 0,
      0,
      "value=" + JSON.stringify(node.value),
    );
  }
  const lines = [
    "  ".repeat(depth) +
      node.type +
      (extra.length ? " " + extra.join(" ") : ""),
  ];
  for (const [key, child] of Object.entries(node)) {
    if (["loc", "range", "extra"].includes(key) || key.endsWith("Comments"))
      continue;
    if (isAst(child)) lines.push(...summarize(child, depth + 1));
    else if (Array.isArray(child))
      for (const item of child)
        if (isAst(item)) lines.push(...summarize(item, depth + 1));
  }
  return lines;
};
const failureText = (error: unknown): string => {
  if (typeof error === "object" && error !== null) {
    if ("cause" in error && error.cause !== error)
      return failureText(error.cause);
    if (
      "errors" in error &&
      Array.isArray(error.errors) &&
      error.errors[0]?.text
    )
      return String(error.errors[0].text);
    if ("message" in error) return String(error.message);
  }
  return String(error);
};
const assets: Plugin = {
  name: "assets",
  setup(builder) {
    builder.onLoad({ filter: /\.(svg|png|jpg|woff2)$/ }, (args) => ({
      contents:
        "export default " +
        JSON.stringify("/assets/" + path.basename(args.path)) +
        ";",
      loader: "js",
    }));
  },
};
export const command = script("jsx-oracle", {
  description: "Refresh JSX reference outputs using pinned original packages.",
  flags: {
    suite: Argument.String("suite"),
    files: Argument.String("fixture").pipe(Argument.variadic()),
  },
  run: (input) =>
    Effect.gen(function* () {
      const fs = yield* FileSystem.FileSystem;
      const run = (file: string, production = false) =>
        Command.run({
          command: process.execPath,
          args: [file],
          cwd: here,
          env: { TZ: "UTC", ...(production ? { NODE_ENV: "production" } : {}) },
        }).pipe(Effect.map((result) => result.stdout));
      const runModule = (code: string) =>
        Effect.scoped(
          Effect.gen(function* () {
            const directory = yield* fs.makeTempDirectoryScoped({
              prefix: "jsx-oracle-",
            });
            const file = path.join(directory, "fixture.mjs");
            yield* fs.writeFileString(file, code);
            const result = yield* Command.run({
              command: process.execPath,
              args: [file],
              env: { TZ: "UTC" },
              check: false,
            });
            return result.exitCode === 0
              ? result.stdout
              : result.stdout +
                  "Error: " +
                  result.stderr.split("\n").find((line) => /Error/.test(line));
          }),
        );
      const lowerSuite = (fixture: string) =>
        Effect.gen(function* () {
          const compiled = yield* build({
            entryPoints: [fixture],
            bundle: true,
            write: false,
            format: "esm",
            platform: "neutral",
            jsx: "automatic",
            jsxImportSource: "recorder",
            alias: { recorder: path.join(here, "recorder") },
            treeShaking: false,
            ignoreAnnotations: true,
            logLevel: "silent",
          }).pipe(
            Effect.map((result) => ({ code: result.outputFiles![0].text })),
            Effect.catch((error) =>
              Effect.succeed({ error: failureText(error).replace(/:$/, "") }),
            ),
          );
          return "error" in compiled
            ? "SyntaxError: " + compiled.error
            : yield* runModule(compiled.code);
        });
      const resolutionSuite = (directory: string) =>
        Effect.gen(function* () {
          const result = yield* build({
            entryPoints: [path.join(directory, "main.mjs")],
            absWorkingDir: directory,
            bundle: true,
            write: false,
            format: "esm",
            platform: "neutral",
            mainFields: ["module", "main"],
            conditions: ["phasis", "module", "production"],
            resolveExtensions: [".jsx", ".js", ".mjs", ".cjs", ".json"],
            define: { "process.env.NODE_ENV": '"production"' },
            jsx: "automatic",
            loader: { ".css": "empty" },
            plugins: [assets],
            logLevel: "silent",
          });
          return yield* runModule(result.outputFiles![0].text);
        });
      const renderSuite = (fixture: string, html: boolean) =>
        Effect.gen(function* () {
          const output = path.join(here, ".out", ...(html ? ["html"] : []));
          yield* fs.makeDirectory(output, { recursive: true });
          const entry =
            "import * as mod from " +
            JSON.stringify(fixture) +
            ";\n" +
            "import { run } from " +
            JSON.stringify(
              path.join(jsxDirectory, html ? "html" : "react", "_driver.mjs"),
            ) +
            ";\n" +
            "console.log(JSON.stringify(await run(mod), null, 2));\n";
          const file = path.join(
            output,
            path.basename(fixture, ".jsx") + ".mjs",
          );
          yield* build({
            stdin: {
              contents: entry,
              resolveDir: here,
              sourcefile: "entry.mjs",
            },
            outfile: file,
            bundle: true,
            format: "esm",
            platform: "node",
            jsx: "automatic",
            ...(html
              ? {
                  jsxImportSource: "phasis",
                  alias: { phasis: path.join(here, "phasis-shim") },
                }
              : { external: ["react", "react/*", "react-dom", "react-dom/*"] }),
            logLevel: "silent",
          });
          return yield* run(file, true);
        });
      const parseSuite = (fixture: string) =>
        Effect.gen(function* () {
          const code = yield* fs.readFileString(
            path.join(root, "tests/Popular/babel-parser/babel-parser.js"),
          );
          const context = vm.createContext({});
          yield* attempt(() => vm.runInContext(code, context));
          const parser = context.BabelParser as {
            parse: (source: string, options: object) => { program: Ast };
          };
          const source = yield* fs.readFileString(fixture);
          return yield* attempt(() =>
            summarize(
              parser.parse(source, {
                sourceType: "module",
                plugins: ["jsx", "estree"],
              }).program,
            ).join("\n"),
          ).pipe(
            Effect.catch((error) =>
              Effect.succeed(
                "SyntaxError: " + error.message.replace(/ \(\d+:\d+\)$/, ""),
              ),
            ),
          );
        });
      const projectSuite = () =>
        Effect.gen(function* () {
          const directory = path.join(here, "project");
          const entry =
            "import { createElement } from 'react';\n" +
            "import { prerender } from 'react-dom/static';\n" +
            "import Page from " +
            JSON.stringify(path.join(directory, "Page.jsx")) +
            ";\n" +
            "import props from " +
            JSON.stringify(path.join(directory, "props.json")) +
            ";\n" +
            "import comments from " +
            JSON.stringify(path.join(directory, "comments.json")) +
            ";\n" +
            "globalThis.fetch = async () => new Response(JSON.stringify(comments), { headers: { 'content-type': 'application/json' } });\n" +
            "const { prelude } = await prerender(createElement(Page, props));\n" +
            "process.stdout.write(await new Response(prelude).text());\n";
          const file = path.join(here, ".out/project.mjs");
          yield* fs.makeDirectory(path.dirname(file), { recursive: true });
          yield* build({
            stdin: {
              contents: entry,
              resolveDir: directory,
              sourcefile: "entry.mjs",
            },
            outfile: file,
            absWorkingDir: directory,
            bundle: true,
            format: "esm",
            platform: "neutral",
            mainFields: ["module", "main"],
            conditions: ["module", "production"],
            resolveExtensions: [".jsx", ".js", ".mjs", ".cjs", ".json"],
            define: { "process.env.NODE_ENV": '"production"' },
            jsx: "automatic",
            external: ["react", "react/*", "react-dom", "react-dom/*"],
            loader: { ".css": "empty" },
            plugins: [assets],
            logLevel: "silent",
          });
          return yield* run(file, true);
        });
      const suites = {
        parse: parseSuite,
        lower: lowerSuite,
        resolution: resolutionSuite,
        smoke: (fixture: string) => run(fixture, true),
        react: (fixture: string) => renderSuite(fixture, false),
        html: (fixture: string) => renderSuite(fixture, true),
      };
      if (input.suite === "project") {
        if (!input.dryRun)
          yield* fs.writeFileString(
            path.join(here, "project/expected.html"),
            (yield* projectSuite()) + "\n",
          );
        return yield* emit(
          input,
          { suite: "project", status: input.dryRun ? "planned" : "written" },
          JSON.stringify,
        );
      }
      if (!(input.suite in suites))
        return yield* fail("Unknown JSX oracle suite: " + input.suite, 2);
      const suite = input.suite as keyof typeof suites;
      const directory = path.join(
        jsxDirectory,
        suite === "smoke" ? "oracle/smoke" : suite,
      );
      const extension = suite === "smoke" ? ".mjs" : ".jsx";
      const fixtures =
        input.files.length > 0
          ? input.files.map((file) => path.resolve(file))
          : (yield* fs.readDirectory(directory))
              .filter(
                (file) =>
                  suite === "resolution" ||
                  file.endsWith(extension) ||
                  (suite === "react" && file.endsWith(".tsx")),
              )
              .sort()
              .map((file) => path.join(directory, file));
      let written = 0;
      for (const fixture of fixtures) {
        if (
          suite === "resolution" &&
          !(yield* fs.stat(fixture)).type.includes("Directory")
        )
          continue;
        if (!input.dryRun) {
          const output = yield* suites[suite](fixture);
          const target =
            suite === "resolution"
              ? fixture + ".expected.txt"
              : fixture.slice(0, -extension.length) + ".expected.txt";
          yield* fs.writeFileString(
            target,
            output.endsWith("\n") ? output : output + "\n",
          );
        }
        written++;
      }
      yield* emit(
        input,
        {
          suite,
          fixtures: written,
          status: input.dryRun ? "planned" : "written",
        },
        JSON.stringify,
      );
    }),
});
if (import.meta.main) main(command);
