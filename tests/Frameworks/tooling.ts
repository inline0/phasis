import { createHash, randomUUID } from "node:crypto";
import { dirname, join, relative, resolve } from "node:path";
import * as tar from "tar";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { fail, insist } from "../../scripts/lib/cli.ts";
import * as Command from "../../scripts/lib/command.ts";

export const repository = resolve(import.meta.dirname, "../..");
export const localOnly = () =>
  process.env.CI
    ? fail(
        "Framework compatibility must never run in CI. See tests/Frameworks/README.md.",
      )
    : Effect.void;
export const sha256 = (bytes: Uint8Array | string) =>
  createHash("sha256").update(bytes).digest("hex");
export const runId = () =>
  new Date()
    .toISOString()
    .replace(/[-:]/g, "")
    .replace(/\.\d{3}Z$/, "Z") +
  "-" +
  randomUUID().slice(0, 8);
export const timeoutScale = (source: string): number => {
  const text = source.trim();
  const value = Number(text);
  if (
    !/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/.test(text) ||
    !Number.isFinite(value) ||
    value <= 0 ||
    value > 100
  ) {
    throw new Error(
      "timeout scale must be a finite positive number at most 100",
    );
  }
  return value;
};
export const phpMemoryLimit = (source: string): string => {
  const match = /^([1-9][0-9]*)([KMG]?)$/i.exec(source);
  if (match === null)
    throw new Error("Use a positive byte count or an integer with K, M or G");
  const suffix = match[2].toUpperCase();
  const multiplier = { "": 1n, K: 1024n, M: 1048576n, G: 1073741824n }[
    suffix as "" | "K" | "M" | "G"
  ];
  if (
    match[1].length > 19 ||
    BigInt(match[1]) * multiplier > 9223372036854775807n
  ) {
    throw new Error("The memory limit exceeds the platform integer range");
  }
  return match[1] + suffix;
};
export const treeHash = (
  directory: string,
  hashEachFile = false,
  extensions?: ReadonlyArray<string>,
) =>
  Effect.gen(function* () {
    const fs = yield* FileSystem.FileSystem;
    const hash = createHash("sha256");
    const files = (yield* fs.readDirectory(directory, {
      recursive: true,
    })).sort();
    for (const name of files) {
      const path = join(directory, name);
      if (
        (yield* fs.stat(path)).type !== "File" ||
        (extensions &&
          !extensions.some((extension) => name.endsWith(extension)))
      )
        continue;
      hash.update(name.split("\\").join("/") + "\0");
      const bytes = yield* fs.readFile(path);
      hash.update(
        hashEachFile ? createHash("sha256").update(bytes).digest() : bytes,
      );
    }
    return hash.digest("hex");
  });
export const filesHash = (directory: string, names: ReadonlyArray<string>) =>
  Effect.gen(function* () {
    const fs = yield* FileSystem.FileSystem;
    const hash = createHash("sha256");
    for (const name of names)
      hash
        .update(name + "\0")
        .update(yield* fs.readFile(resolve(directory, name)));
    return hash.digest("hex");
  });
export const extractArchive = (bytes: Uint8Array, directory: string) =>
  Effect.tryPromise(
    () =>
      new Promise<void>((done, reject) => {
        tar
          .x({ cwd: directory, strip: 1, preservePaths: false, strict: true })
          .on("error", reject)
          .on("close", done)
          .end(Buffer.from(bytes));
      }),
  );
export const execute = (
  argv: ReadonlyArray<string>,
  seconds: number,
  project = "main",
  logFile?: string,
  scale = 1,
) =>
  Effect.scoped(
    Effect.gen(function* () {
      const fs = yield* FileSystem.FileSystem;
      const log =
        logFile ??
        join(
          yield* fs.makeTempDirectoryScoped({ prefix: "phasis-worker-" }),
          "worker.log",
        );
      yield* fs.makeDirectory(dirname(log), { recursive: true });
      yield* fs.writeFileString(log, "");
      const outcome = yield* Command.run({
        command: argv[0],
        args: argv.slice(1),
        cwd: repository,
        timeout: seconds * 1000,
        check: false,
        killAfter: "2 seconds",
        env: {
          XDEBUG_MODE: "off",
          PHASIS_HONO_PROJECT: project,
          PHASIS_FRAMEWORK_TIMEOUT_SCALE: String(scale),
          NAME: project === "node" ? "Node" : process.env.NAME,
          NO_COLOR: undefined,
        },
        onLine: (line) =>
          fs
            .writeFileString(log, line + "\n", { flag: "a" })
            .pipe(Effect.orDie),
      }).pipe(
        Effect.map((result) => ({ status: result.exitCode, timedOut: false })),
        Effect.catch((error) =>
          Effect.gen(function* () {
            const timedOut = error._tag === "CallTimeout";
            yield* fs.writeFileString(
              log,
              timedOut ? "\nPROCESS TIMEOUT\n" : error.message + "\n",
              { flag: "a" },
            );
            return { status: timedOut ? 124 : 127, timedOut };
          }),
        ),
      );
      return { ...outcome, output: yield* fs.readFileString(log) };
    }),
  );
export const inside = (directory: string, name: string) =>
  Effect.gen(function* () {
    const path = resolve(directory, name);
    yield* insist(
      path.startsWith(directory + "/"),
      "Path escapes the selected upstream project: " + name,
    );
    return path;
  });
export const relativeName = (directory: string, file: string) =>
  relative(directory, file).split("\\").join("/");
