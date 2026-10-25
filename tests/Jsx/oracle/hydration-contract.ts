import assert from "node:assert/strict";
import path from "node:path";
import * as Flag from "effect/cli/Flag";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { build } from "../../../scripts/lib/build.ts";
import { emit, main, script } from "../../../scripts/lib/cli.ts";
import * as Command from "../../../scripts/lib/command.ts";
import { attempt } from "../../../scripts/lib/files.ts";

const here = import.meta.dirname;
const repo = path.resolve(here, "../../..");
interface Manifest {
  readonly name: string;
  readonly require: Record<string, string>;
  readonly autoload: object;
  readonly bin: string[];
}
export const command = script("hydration-contract", {
  description:
    "Qualify real hydration and typed files in an isolated Composer consumer.",
  flags: { source: Flag.Boolean("source").pipe(Flag.withDefault(false)) },
  run: (input) =>
    Effect.scoped(
      Effect.gen(function* () {
        if (input.dryRun)
          return yield* emit(
            input,
            { status: "planned", source: input.source },
            JSON.stringify,
          );
        const fs = yield* FileSystem.FileSystem;
        const temp = yield* fs.makeTempDirectoryScoped({
          prefix: "phasis-hydration-",
        });
        const execute = (command: string, args: string[], cwd = temp) =>
          Command.run({ command, args, cwd, env: { XDEBUG_MODE: "off" } });
        let checks = 0;
        if (input.source) {
          yield* fs.makeDirectory(path.join(temp, "vendor/phasis"), {
            recursive: true,
          });
          yield* fs.symlink(repo, path.join(temp, "vendor/phasis/phasis"));
          yield* fs.writeFileString(
            path.join(temp, "vendor/autoload.php"),
            "<?php require " +
              JSON.stringify(path.join(repo, "vendor/autoload.php")) +
              ";",
          );
        } else {
          const archive = path.join(temp, "phasis.zip");
          yield* execute(
            "git",
            ["archive", "--format=zip", "--output=" + archive, "HEAD"],
            repo,
          );
          const captured = yield* execute(
            "git",
            ["show", "HEAD:composer.json"],
            repo,
          );
          const manifest = yield* attempt(
            () => JSON.parse(captured.stdout) as Manifest,
          );
          yield* fs.writeFileString(
            path.join(temp, "composer.json"),
            JSON.stringify({
              name: "phasis/hydration-consumer",
              repositories: [
                {
                  type: "package",
                  package: {
                    name: manifest.name,
                    version: "0.10.0",
                    require: manifest.require,
                    autoload: manifest.autoload,
                    bin: manifest.bin,
                    dist: { type: "zip", url: "file://" + archive },
                  },
                },
              ],
              require: { "phasis/phasis": "0.10.0" },
            }),
          );
          yield* execute("composer", [
            "install",
            "--no-dev",
            "--no-plugins",
            "--no-scripts",
            "--no-interaction",
            "--quiet",
          ]);
          for (const file of [
            "browser/hydrate.mjs",
            "browser/hydrate.d.mts",
            "browser/README.md",
          ]) {
            assert.ok(
              yield* fs.exists(path.join(temp, "vendor/phasis/phasis", file)),
            );
          }
          assert.equal(
            yield* fs.exists(path.join(temp, "vendor/phasis/phasis/tests")),
            false,
          );
          const cli = yield* execute("php", [
            path.join(temp, "vendor/bin/phasis"),
            "-e",
            "console.log(1 + 1)",
          ]);
          assert.equal(cli.stdout.trim(), "2");
          checks += 5;
          for (const file of [
            "package.json",
            "pnpm-lock.yaml",
            "pnpm-workspace.yaml",
            "tsconfig.scripts.json",
            ".nvmrc",
          ]) {
            assert.equal(
              yield* fs.exists(path.join(temp, "vendor/phasis/phasis", file)),
              false,
              "Host tooling must not ship: " + file,
            );
            checks++;
          }
        }
        yield* fs.symlink(
          path.join(here, "node_modules"),
          path.join(temp, "node_modules"),
        );
        const typed = yield* execute("php", [
          path.join(repo, "tests/TypeScript/archive-consumer.php"),
          temp,
        ]);
        assert.equal(typed.stdout.trim(), "TypeScript runtime archive: PASS");
        if (!input.source) {
          const cli = yield* execute("php", [
            path.join(temp, "vendor/bin/phasis"),
            path.join(temp, "TypedEntry.ts"),
          ]);
          assert.equal(cli.stdout.trim(), "42");
        }
        checks++;
        const types = path.join(temp, "types.mts");
        yield* fs.writeFileString(
          types,
          `
        import { hydrate, HydrationError } from './vendor/phasis/phasis/browser/hydrate.mjs';
        import type { Root } from 'react-dom/client';
        const root: Root = hydrate(document.createElement('div'), {}, {
            entryId: 'contract', buildId: 'build', export: 'Named',
            onError(error) { const code: HydrationError['code'] = error.code; },
            onRecoverableError(error, info) { const stack: string | undefined = info.componentStack; },
        });
        root.unmount();
        // @ts-expect-error Build identity is mandatory.
        hydrate(document.createElement('div'), {}, { entryId: 'contract' });
        // @ts-expect-error Document hydration is outside the element-root contract.
        hydrate(document, {}, { entryId: 'contract', buildId: 'build' });
    `,
        );
        yield* execute(process.execPath, [
          path.join(here, "node_modules/typescript/bin/tsc"),
          "--strict",
          "--noEmit",
          "--module",
          "NodeNext",
          "--target",
          "ES2022",
          "--preserveSymlinks",
          types,
        ]);
        checks++;
        yield* fs.copy(
          path.join(repo, "tests/Jsx/hydration"),
          path.join(temp, "components"),
        );
        const rendered = yield* execute("php", [
          path.join(repo, "tests/Jsx/hydration-run.php"),
          temp,
        ]);
        const html = path.join(temp, "rendered.html");
        yield* fs.writeFileString(html, rendered.stdout);
        const entry = path.join(temp, "entry.mjs");
        yield* fs.writeFileString(
          entry,
          "import { hydrate, HydrationError } from './vendor/phasis/phasis/browser/hydrate.mjs';\n" +
            "import * as components from './components/Root.jsx';\nwindow.contract = { hydrate, HydrationError, components };\n",
        );
        const modes: string[] = [];
        for (const mode of ["production", "development"]) {
          const bundle = yield* build({
            entryPoints: [entry],
            bundle: true,
            write: false,
            platform: "browser",
            format: "iife",
            jsx: "automatic",
            nodePaths: [path.join(here, "node_modules")],
            define: { "process.env.NODE_ENV": JSON.stringify(mode) },
            logLevel: "silent",
            metafile: true,
          });
          const reactInputs = Object.keys(bundle.metafile!.inputs).filter(
            (file) =>
              /react\/cjs\/react\.(production|development)\.js$/.test(file),
          );
          assert.equal(
            reactInputs.length,
            1,
            "The helper and components must share one React copy.",
          );
          const file = path.join(temp, "browser-" + mode + ".js");
          yield* fs.writeFileString(file, bundle.outputFiles![0].text);
          const result = yield* execute(process.execPath, [
            path.join(here, "probes/contract.mjs"),
            html,
            file,
            String(checks),
          ]);
          const report = yield* attempt(
            () => JSON.parse(result.stdout) as { checks: number },
          );
          checks = report.checks;
          modes.push(mode);
        }
        yield* emit(
          input,
          { checks, modes, source: input.source },
          (value) =>
            `hydration contract: ${value.checks} checks passed (${value.source ? "source checkout" : "Composer archive consumer"})`,
        );
      }),
    ),
});
if (import.meta.main) main(command);
