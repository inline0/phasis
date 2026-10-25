import assert from "node:assert/strict";
import { join } from "node:path";
import { test } from "node:test";
import vm from "node:vm";
import { deflateSync, inflateSync } from "node:zlib";
import * as Effect from "effect/Effect";
import * as Exit from "effect/Exit";
import * as FileSystem from "effect/FileSystem";
import { validateDocumentation } from "./check-docs-content.ts";
import * as Command from "./lib/command.ts";
import { capture, discover, locate } from "./lib/scenarios.ts";
import { runtimeLayer } from "./lib/runtime.ts";
import { runChecks } from "./lib/suites.ts";
import { refresh } from "./gen-lunisolar-modern.ts";
import { attempt } from "./lib/files.ts";

test("verification runs every check and retains nonzero command status", async () => {
  const calls: string[] = [];
  const exit = await Effect.runPromiseExit(
    runChecks(
      [
        { label: "first", command: "one", args: [] },
        { label: "failure", command: "two", args: [] },
        { label: "last", command: "three", args: [] },
      ],
      "/a directory with spaces",
      { json: true, dryRun: false },
    ).pipe(
      Effect.provideService(Command.Command, {
        run: (call) =>
          Effect.sync(() => {
            calls.push(call.command);
            assert.equal(call.cwd, "/a directory with spaces");
            return Command.emptyResult(call.command === "two" ? 7 : 0);
          }),
      }),
    ),
  );
  assert(Exit.isFailure(exit));
  assert.deepEqual(calls, ["one", "two", "three"]);
});
test("scenario lookup is deterministic and never silently chooses a duplicate basename", async () => {
  await Effect.runPromise(
    Effect.scoped(
      Effect.gen(function* () {
        const fs = yield* FileSystem.FileSystem;
        const root = yield* fs.makeTempDirectoryScoped({
          prefix: "phasis scenario tooling ",
        });
        for (const name of ["first/shared", "second/shared"]) {
          const directory = join(root, "scenarios", name);
          yield* fs.makeDirectory(join(directory, "setup"), {
            recursive: true,
          });
          yield* fs.writeFileString(
            join(directory, "setup/test.js"),
            "console.log(1)",
          );
          yield* fs.writeFileString(
            join(directory, "scenario.json"),
            '{"mode":"exec"}',
          );
        }
        assert.equal((yield* discover("", root)).length, 2);
        assert.equal(
          yield* locate("first/shared", root),
          join(root, "scenarios/first/shared"),
        );
        assert(Exit.isFailure(yield* Effect.exit(locate("shared", root))));
        assert(
          Exit.isFailure(yield* Effect.exit(discover("../../outside", root))),
        );
        const selected = yield* locate("first/shared", root);
        const calls: Command.CommandCall[] = [];
        assert.equal(yield* capture(selected, "actual", true), "planned");
        assert.equal(yield* fs.exists(join(selected, "actual")), false);
        yield* capture(selected, "actual", false).pipe(
          Effect.provideService(Command.Command, {
            run: (call) =>
              Effect.sync(() => {
                calls.push(call);
                return { ...Command.emptyResult(), stdout: "snowman: ☃\n" };
              }),
          }),
        );
        assert.equal(
          yield* fs.readFileString(join(selected, "actual/output.txt")),
          "snowman: ☃\n",
        );
        assert.deepEqual(calls[0].args?.slice(-1), [
          join(selected, "setup/test.js"),
        ]);
      }),
    ).pipe(Effect.provide(runtimeLayer({ dryRun: false, logFormat: "text" }))),
  );
});
test("portable docs validate links outside docs and reject broken navigation", () => {
  const root = "/repository/docs";
  const snapshot = new Map([
    [join(root, "meta.json"), '{"pages":["index"]}'],
    [
      join(root, "index.md"),
      '---\ntitle: "Intro"\ndescription: "Guide"\npath: "index"\norder: 0\nmeta_title: "Intro"\nmeta_description: "Guide"\n---\n[Package](../package.json)\n',
    ],
  ]);
  assert.deepEqual(
    validateDocumentation(
      root,
      snapshot,
      1,
      1,
      new Set(["/repository/package.json"]),
    ).problems,
    [],
  );
  assert(
    validateDocumentation(root, snapshot, 1, 1).problems.some((problem) =>
      problem.includes("broken relative link"),
    ),
  );
  snapshot.set(join(root, "meta.json"), '{"pages":["missing"]}');
  assert(
    validateDocumentation(root, snapshot, 1, 1).problems.some((problem) =>
      problem.includes("unresolved navigation"),
    ),
  );
});
test("calendar refresh preserves existing compressed bytes when records did not change", () => {
  const bytes = Buffer.alloc(8);
  bytes.writeInt32LE(10957, 0);
  bytes.writeInt8(-1, 4);
  bytes.writeUInt16LE(1, 5);
  bytes.writeUInt8(12, 7);
  const original =
    "'start' => 2000, 'blob' => '" +
    deflateSync(bytes, { level: 1 }).toString("base64") +
    "'";
  const source =
    "PackedEastAsianTraditionalYearData::new(2000, [l, s, s, s, s, s, s, s, s, s, s, s], None, gregorian(2000, 1, 1))";
  assert.equal(refresh(original, source).text, original);
  const changed = refresh(original, source.replace("[l, s,", "[l, l,"));
  assert.equal(changed.records, 1);
  bytes.writeUInt16LE(3, 5);
  const encoded = /'blob' => '([^']+)'/.exec(changed.text)![1];
  assert.deepEqual(inflateSync(Buffer.from(encoded, "base64")), bytes);
  assert.throws(() => refresh(original, "no records"));
  assert.throws(() => refresh("'start' => 2000", source));
});

test("reference parser errors preserve messages across VM realms", async () => {
  const error = await Effect.runPromise(
    attempt(() =>
      vm.runInNewContext("throw new SyntaxError('original message')"),
    ).pipe(Effect.flip),
  );
  assert.equal(error.message, "original message");
});
