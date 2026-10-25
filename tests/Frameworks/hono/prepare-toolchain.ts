import { relative, resolve } from "node:path";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { emit, insist, main, script } from "../../../scripts/lib/cli.ts";
import { readJson } from "../../../scripts/lib/files.ts";
import { localOnly } from "../tooling.ts";
import type { Metadata } from "./inventory.ts";

export const command = script("prepare-hono-toolchain", {
  description:
    "Connect declaration fixtures to the exact pinned Hono distribution, locally.",
  flags: {},
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(input, { status: "planned" }, JSON.stringify);
      yield* localOnly();
      const fs = yield* FileSystem.FileSystem;
      const directory = import.meta.dirname;
      const metadata = yield* readJson<Metadata>(
        resolve(directory, "upstream.json"),
      );
      const upstream = resolve(directory, metadata.directory);
      const published = resolve(directory, "../node_modules/hono");
      const installed = yield* readJson<{ version: string }>(
        resolve(published, "package.json"),
      );
      yield* insist(
        installed.version === metadata.version,
        "Published Hono fixture version differs from upstream source",
      );
      const distribution = yield* fs.realPath(resolve(published, "dist"));
      const target = resolve(upstream, "dist");
      if (yield* fs.exists(target))
        yield* insist(
          (yield* fs.realPath(target)) === distribution,
          "Existing upstream dist is not the pinned published package",
        );
      else yield* fs.symlink(relative(upstream, distribution), target);
      yield* emit(
        input,
        { version: metadata.version, distribution },
        JSON.stringify,
      );
    }),
});
if (import.meta.main) main(command);
