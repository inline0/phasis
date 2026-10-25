import assert from "node:assert/strict";
import { join } from "node:path";
import { test } from "node:test";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import * as Command from "../../../scripts/lib/command.ts";
import { readJson } from "../../../scripts/lib/files.ts";
import {
  runtimeLayer,
  type RuntimeServices,
} from "../../../scripts/lib/runtime.ts";
import { localOnly } from "../tooling.ts";

const here = import.meta.dirname;
const run = <A, E>(effect: Effect.Effect<A, E, RuntimeServices>) =>
  Effect.runPromise(
    effect.pipe(
      Effect.provide(runtimeLayer({ dryRun: false, logFormat: "text" })),
    ),
  );
interface Report {
  complete: boolean;
  timeoutScale: number;
  pass: number;
  fail: number;
  caseManifest: Array<{ id: string; title: string }>;
  loadErrors: unknown[];
  asynchronousErrors: unknown[];
  tests: Array<{
    id: string;
    originalTimeout: number;
    effectiveTimeout: number;
    error?: { code: string };
  }>;
}
const control = (
  body: string,
  titles: string[],
  scale?: number,
  timeout = 30,
) =>
  run(
    Effect.scoped(
      Effect.gen(function* () {
        yield* localOnly();
        const fs = yield* FileSystem.FileSystem;
        const temporary = yield* fs.makeTempDirectoryScoped({
          prefix: "phasis-timeout-control-",
        });
        const source = join(temporary, "control.cjs");
        const output = join(temporary, "result.json");
        yield* fs.writeFileString(source, body);
        const expected = titles.map((title, index) => ({
          id: "control.js:" + (index + 1),
          title,
        }));
        const options = {
          timeout,
          expectedTests: titles.length,
          expectedCases: expected,
          fileNames: { [source]: "control.js" },
          ...(scale === undefined ? {} : { timeoutScale: scale }),
        };
        const program =
          "globalThis.__expressFinished=s=>require('node:fs').writeFileSync(" +
          JSON.stringify(output) +
          ",s);require(" +
          JSON.stringify(join(here, "runner.cjs")) +
          ")(" +
          JSON.stringify([source]) +
          "," +
          JSON.stringify(options) +
          ");";
        const result = yield* Command.run({
          command: process.execPath,
          args: ["-e", program],
          cwd: here,
          timeout: "5 seconds",
        });
        assert.equal(result.exitCode, 0, result.stderr);
        const report = yield* readJson<Report>(output);
        assert.equal(report.complete, true);
        assert.deepEqual(report.caseManifest, expected);
        assert.deepEqual(report.loadErrors, []);
        assert.deepEqual(report.asynchronousErrors, []);
        return report;
      }),
    ),
  );

test("original async defaults and scaled deadlines", async () => {
  const body = "it('async', function(done) { setTimeout(done, 60); });";
  const normal = await control(body, ["async"], undefined);
  const scaled = await control(body, ["async"], 4);
  assert.deepEqual([normal.timeoutScale, normal.fail], [1, 1]);
  assert.deepEqual([scaled.timeoutScale, scaled.pass], [4, 1]);
  assert.equal(scaled.tests[0].id, "control.js:1");
  assert.deepEqual(
    [scaled.tests[0].originalTimeout, scaled.tests[0].effectiveTimeout],
    [30, 120],
  );
});
test("explicit async and synchronous deadlines still fail", async () => {
  for (const body of [
    "it('deadline', function(done) { this.timeout(10); setTimeout(done, 45); });",
    "it('deadline', function() { this.timeout('10ms'); const end=Date.now()+35; while(Date.now()<end) {} });",
  ]) {
    const report = await control(body, ["deadline"], 2);
    assert.equal(report.fail, 1);
    assert.equal(report.tests[0].error?.code, "ERR_MOCHA_TIMEOUT");
    assert.deepEqual(
      [report.tests[0].originalTimeout, report.tests[0].effectiveTimeout],
      [10, 20],
    );
  }
});
test("hooks scale once, preserve clock functions and retain disabled deadlines", async () => {
  const report = await control(
    "const seen=[];beforeEach(function(done){this.timeout(20);seen.push(this.timeout());setTimeout(done,30)});it('first',function(){});it('second',function(){require('node:assert').deepStrictEqual(seen,[60,60])})",
    ["first", "second"],
    3,
  );
  assert.equal(report.pass, 2);
  const failed = await control(
    "beforeEach(function(done){this.timeout(10);setTimeout(done,45)});it('body',function(){})",
    ["body"],
    2,
  );
  assert.equal(failed.fail, 1);
  assert.equal(failed.tests[0].error?.code, "ERR_MOCHA_TIMEOUT");
  assert.deepEqual(
    [failed.tests[0].originalTimeout, failed.tests[0].effectiveTimeout],
    [10, 20],
  );
  const clocks = await control(
    "const timer=setTimeout,clock=performance.now;it('clocks',function(){require('node:assert').strictEqual(setTimeout,timer);require('node:assert').strictEqual(performance.now,clock)})",
    ["clocks"],
    10,
  );
  assert.equal(clocks.pass, 1);
  const disabled = await control(
    "it('disabled',function(done){this.timeout(0);setTimeout(done,30)})",
    ["disabled"],
    2,
    5,
  );
  assert.equal(disabled.pass, 1);
  assert.deepEqual(
    [disabled.tests[0].originalTimeout, disabled.tests[0].effectiveTimeout],
    [0, 0],
  );
});
test("native and PHP CLIs retain environment precedence, identities and invalid-value rejection", async () => {
  await run(
    Effect.scoped(
      Effect.gen(function* () {
        yield* localOnly();
        const fs = yield* FileSystem.FileSystem;
        const manifest = yield* readJson<{
          files: Array<{
            path: string;
            cases: Array<{ id: string; title: string }>;
          }>;
        }>(join(here, "manifest.json"));
        const entry = manifest.files.find(
          (row) => row.path === "test/Route.js",
        )!;
        const title = entry.cases[0].title.replace(/[.*+?^$()|[\]\\]/g, "\\$&");
        const temporary = yield* fs.makeTempDirectoryScoped({
          prefix: "phasis-timeout-cli-",
        });
        for (const runtime of ["node", "php"]) {
          for (const [label, extra, environment, expected] of [
            ["environment", [], "2", 2],
            ["override", ["--timeout-scale", "3"], "NaN", 3],
          ] as const) {
            const output = join(temporary, runtime + "-" + label + ".json");
            const args =
              runtime === "node"
                ? [join(here, "run-node.cjs")]
                : ["-d", "memory_limit=1G", join(here, "run-phasis.php")];
            const result = yield* Command.run({
              command: runtime === "node" ? process.execPath : "php",
              args: [
                ...args,
                "--file=test/Route.js",
                "--grep=^" + title + "$",
                "--output=" + output,
                ...(runtime === "php" && extra.length
                  ? ["--timeout-scale=3"]
                  : extra),
              ],
              env: {
                XDEBUG_MODE: "off",
                PHASIS_FRAMEWORK_TIMEOUT_SCALE: environment,
              },
              timeout: runtime === "node" ? "5 seconds" : "20 seconds",
            });
            assert.equal(result.exitCode, 0, result.stderr);
            const report = yield* readJson<Report>(output);
            assert.equal(report.timeoutScale, expected);
            assert.deepEqual(report.caseManifest, entry.cases);
            if (runtime === "php")
              assert.equal(
                report.tests[0].effectiveTimeout,
                report.tests[0].originalTimeout * expected,
              );
          }
          for (const value of [
            "NaN",
            "Infinity",
            "0",
            "-1",
            "101",
            "not-a-number",
          ]) {
            const result = yield* Command.run({
              command: runtime === "node" ? process.execPath : "php",
              args: [
                join(
                  here,
                  runtime === "node" ? "run-node.cjs" : "run-phasis.php",
                ),
                "--timeout-scale=" + value,
              ],
              check: false,
              timeout: "5 seconds",
              env: { XDEBUG_MODE: "off" },
            });
            assert.notEqual(result.exitCode, 0, value);
          }
        }
        for (const value of [
          "NaN",
          "Infinity",
          "0",
          "-1",
          "101",
          "0x10",
          "",
          true,
        ]) {
          const program =
            "require(" +
            JSON.stringify(join(here, "runner.cjs")) +
            ")([],{timeoutScale:" +
            JSON.stringify(value) +
            "});";
          const result = yield* Command.run({
            command: process.execPath,
            args: ["-e", program],
            check: false,
            timeout: "5 seconds",
          });
          assert.notEqual(result.exitCode, 0, String(value));
        }
      }),
    ),
  );
});
