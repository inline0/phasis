import { dirname, join, resolve } from "node:path";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { download } from "../../scripts/lib/build.ts";
import { emit, insist, script } from "../../scripts/lib/cli.ts";
import { readJson } from "../../scripts/lib/files.ts";
import { extractArchive, localOnly, sha256 } from "./tooling.ts";

interface Metadata {
  readonly archive?: string;
  readonly source?: string;
  readonly directory?: string;
  readonly version: string;
  readonly sha256: string;
  readonly revision?: string;
  readonly commit?: string;
}
export const setupCommand = (name: "hono" | "express") =>
  script("setup-" + name, {
    description:
      "Fetch and verify the exact original " +
      name +
      " source archive, locally.",
    flags: {},
    run: (input) =>
      Effect.scoped(
        Effect.gen(function* () {
          const directory = resolve(import.meta.dirname, name);
          const metadata = yield* readJson<Metadata>(
            join(directory, "upstream.json"),
          );
          if (input.dryRun)
            return yield* emit(
              input,
              { status: "planned", upstream: metadata },
              JSON.stringify,
            );
          yield* localOnly();
          const fs = yield* FileSystem.FileSystem;
          const target = resolve(directory, metadata.directory ?? "upstream");
          yield* fs.makeDirectory(dirname(target), { recursive: true });
          const staging = yield* fs.makeTempDirectoryScoped({
            directory: dirname(target),
            prefix: "." + name + "-upstream-",
          });
          const archive = yield* download(
            metadata.source ?? metadata.archive!,
            "60 seconds",
          );
          yield* insist(
            sha256(archive) === metadata.sha256,
            name + " archive checksum mismatch",
          );
          yield* extractArchive(archive, staging);
          const installed = yield* readJson<{ version: string }>(
            join(staging, "package.json"),
          );
          yield* insist(
            installed.version === metadata.version,
            name + " source version does not match the manifest",
          );
          if (name === "hono" && (yield* fs.exists(target))) {
            for (const file of yield* fs.readDirectory(staging, {
              recursive: true,
            })) {
              const source = join(staging, file);
              if ((yield* fs.stat(source)).type !== "File") continue;
              const destination = join(target, file);
              yield* insist(
                yield* fs.exists(destination),
                "Existing Hono source differs: " + destination,
              );
              yield* insist(
                Buffer.from(yield* fs.readFile(source)).equals(
                  yield* fs.readFile(destination),
                ),
                "Existing Hono source differs: " + destination,
              );
            }
            for (const area of ["src", "scripts", "build", "runtime-tests"]) {
              const path = join(target, area);
              if (!(yield* fs.exists(path))) continue;
              for (const file of yield* fs.readDirectory(path, {
                recursive: true,
              })) {
                if ((yield* fs.stat(join(path, file))).type === "File") {
                  yield* insist(
                    yield* fs.exists(join(staging, area, file)),
                    "Unexpected file in Hono source: " + join(path, file),
                  );
                }
              }
            }
          } else {
            yield* fs.remove(target, { recursive: true, force: true });
            yield* fs.copy(staging, target);
          }
          yield* emit(
            input,
            {
              upstream: name,
              version: metadata.version,
              revision: metadata.commit ?? metadata.revision,
            },
            JSON.stringify,
          );
        }),
      ),
  });
