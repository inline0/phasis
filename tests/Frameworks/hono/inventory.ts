import { resolve } from "node:path";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { emit, main, script } from "../../../scripts/lib/cli.ts";
import { readJson } from "../../../scripts/lib/files.ts";
import { localOnly, sha256 } from "../tooling.ts";

export const directory = import.meta.dirname;
export interface Metadata {
  readonly directory: string;
  readonly version: string;
  readonly commit: string;
  readonly source: string;
  readonly sha256: string;
}
export interface Project {
  readonly host: string;
  readonly files: string[];
  readonly runtimeFiles?: string[];
  readonly toolchainFiles?: string[];
}
export const inventory = () =>
  Effect.gen(function* () {
    const fs = yield* FileSystem.FileSystem;
    const metadata = yield* readJson<Metadata>(
      resolve(directory, "upstream.json"),
    );
    const upstream = resolve(directory, metadata.directory);
    const testFiles = (area: string) =>
      Effect.gen(function* () {
        const path = resolve(upstream, area);
        if (!(yield* fs.exists(path))) return [];
        return (yield* fs.readDirectory(path, { recursive: true }))
          .filter(
            (name) =>
              /(?:\.test\.|\.spec\.)/.test(name.split("/").at(-1)!) &&
              /\.(ts|tsx|js)$/.test(name) &&
              !name.split("/").includes("sandbox"),
          )
          .map((name) => area + "/" + name)
          .sort();
      });
    const source = yield* testFiles("src");
    const runtime = [
      ...source.filter((name) => !name.includes(".case.test.")),
      ...(yield* testFiles("scripts")),
    ];
    const toolchain = yield* testFiles("build");
    const dom = [
      ...(yield* testFiles("src/jsx/dom")),
      "src/jsx/hooks/dom.test.tsx",
    ];
    const projects: Record<string, Project> = {
      main: {
        host: "Node and web APIs, plus native TypeScript build checks",
        files: [...runtime, ...toolchain].sort(),
        runtimeFiles: runtime.sort(),
        toolchainFiles: toolchain.sort(),
      },
      "jsx-runtime-default": {
        host: "Node with jsdom",
        files: [...dom].sort(),
      },
      "jsx-runtime-dom": { host: "Node with jsdom", files: [...dom].sort() },
    };
    for (const host of [
      "node",
      "fastly",
      "workerd",
      "bun",
      "deno",
      "deno-jsx",
    ]) {
      projects[host] = {
        host,
        files: yield* testFiles("runtime-tests/" + host),
      };
    }
    const hashes: Record<string, string> = {};
    for (const file of [
      ...new Set(Object.values(projects).flatMap((project) => project.files)),
    ].sort()) {
      hashes[file] = sha256(yield* fs.readFile(resolve(upstream, file)));
    }
    return {
      upstream: metadata,
      projects,
      fixtures: source.filter((name) => name.includes(".case.test.")),
      sha256: hashes,
    };
  });
export const command = script("hono-inventory", {
  description: "Inventory every original upstream Hono test project, locally.",
  flags: {},
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(input, { status: "planned" }, JSON.stringify);
      yield* localOnly();
      yield* emit(input, yield* inventory(), JSON.stringify);
    }),
});
if (import.meta.main) main(command);
