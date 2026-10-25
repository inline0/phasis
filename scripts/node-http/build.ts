import { createHash } from "node:crypto";
import { resolve } from "node:path";
import * as Flag from "effect/cli/Flag";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { download } from "../lib/build.ts";
import { emit, insist, main, script } from "../lib/cli.ts";
import * as Command from "../lib/command.ts";
import { attempt, readJson } from "../lib/files.ts";

const directory = import.meta.dirname;
const library = resolve(directory, "../../src/Node/Lib");
interface Upstream {
  readonly url: string;
  readonly integrity: string;
  readonly version: string;
}
export const command = script("build-http-parser", {
  description: "Reproduce the integrity-pinned HTTP parser and its license.",
  flags: { check: Flag.Boolean("check").pipe(Flag.withDefault(false)) },
  run: (input) =>
    Effect.gen(function* () {
      const fs = yield* FileSystem.FileSystem;
      const upstream = (yield* readJson<Record<string, Upstream>>(
        resolve(directory, "upstream.json"),
      ))["http-parser-js"];
      if (input.dryRun)
        return yield* emit(
          input,
          { status: "planned", version: upstream.version },
          JSON.stringify,
        );
      const bytes = yield* download(upstream.url);
      const integrity =
        "sha512-" + createHash("sha512").update(bytes).digest("base64");
      yield* insist(
        integrity === upstream.integrity,
        "HTTP parser archive integrity mismatch",
      );
      const extract = (file: string) =>
        Command.run({
          command: "tar",
          args: ["-xOzf", "-", "package/" + file],
          stdin: bytes,
          mutates: false,
          timeout: "30 seconds",
        });
      const metadata = (yield* extract("package.json")).stdout;
      const archivePackage = yield* attempt(
        () => JSON.parse(metadata) as { version: string },
      );
      yield* insist(
        archivePackage.version === upstream.version,
        "HTTP parser archive version mismatch",
      );
      for (const [source, target] of [
        ["http-parser.js", "http-parser.cjs"],
        ["LICENSE.md", "http-parser.LICENSE.md"],
      ]) {
        const contents = (yield* extract(source)).stdoutBytes;
        const destination = resolve(library, target);
        if (input.check) {
          yield* insist(
            Buffer.from(yield* fs.readFile(destination)).equals(contents),
            "HTTP parser asset differs: " + destination,
          );
        } else yield* fs.writeFile(destination, contents);
      }
      yield* emit(
        input,
        { version: upstream.version, status: "verified" },
        (value) => `Verified http-parser-js ${value.version} and its license`,
      );
    }),
});
if (import.meta.main) main(command);
