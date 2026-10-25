import assert from "node:assert/strict";
import { test } from "node:test";
import { phpMemoryLimit, timeoutScale } from "./tooling.ts";
import { selectedFiles, classify } from "./hono/run.ts";
import { summarizeRuntime } from "./express/run.ts";

test("timeout scales retain finite positive decimal syntax and original bounds", () => {
  for (const [value, expected] of [
    ["1", 1],
    ["10", 10],
    ["100", 100],
    ["0.5", 0.5],
    ["2e1", 20],
    [".25", 0.25],
  ] as const) {
    assert.equal(timeoutScale(value), expected);
  }
  for (const value of [
    "",
    "0",
    "-1",
    "NaN",
    "Infinity",
    "1e500",
    "1e-500",
    "1_0",
    "0x10",
    "101",
    "1e6",
  ]) {
    assert.throws(() => timeoutScale(value), Error, value);
  }
});
test("PHP worker memory stays bounded and preserves explicit units", () => {
  for (const [value, expected] of [
    ["512m", "512M"],
    ["2G", "2G"],
    ["1024K", "1024K"],
    ["4096", "4096"],
  ]) {
    assert.equal(phpMemoryLimit(value), expected);
  }
  for (const value of [
    "-1",
    "0",
    "4GB",
    "4.5G",
    "unlimited",
    "2G ",
    "9223372036854775807G",
    "9".repeat(5000),
  ]) {
    assert.throws(() => phpMemoryLimit(value), Error, value);
  }
});
test("selection cannot duplicate files or run native declaration fixtures in the runtime suite", () => {
  assert.deepEqual(selectedFiles(["a.test.ts"], ["build.test.ts"], []), [
    "a.test.ts",
  ]);
  assert.throws(() =>
    selectedFiles(["a.test.ts"], [], ["a.test.ts", "a.test.ts"]),
  );
  assert.throws(() =>
    selectedFiles(["a.test.ts"], ["build.test.ts"], ["build.test.ts"]),
  );
  assert.throws(() => selectedFiles(["a.test.ts"], [], ["unknown.test.ts"]));
});
test("Hono reports cannot hide blocked, empty or misconfigured execution", () => {
  const report = {
    timeoutScale: 1,
    results: [{ id: "1", name: "one", status: "pass" }],
    counts: { pass: 1, fail: 0 },
    suiteErrors: [],
  };
  assert.equal(classify(124, [], 1).status, "process-blocked");
  assert.equal(classify(0, [], 1).status, "process-blocked");
  assert.equal(
    classify(0, [{ ...report, results: [] }], 1).status,
    "empty-discovery",
  );
  assert.equal(
    classify(0, [report], 10).status,
    "timeout-configuration-mismatch",
  );
  assert.deepEqual(classify(0, [report], 1), { pass: 1, fail: 0 });
  assert.equal(
    classify(0, [{ ...report, suiteErrors: ["error"] }], 1)["suite-failures"],
    1,
  );
});
test("Express completion accounts for every original case and source identity", () => {
  const manifest = {
    tests: 1,
    files: [
      { path: "one.js", tests: 1, cases: [{ id: "one:1", title: "one" }] },
    ],
  };
  const result = {
    runtime: "node",
    file: "one.js",
    expectedTests: 1,
    exitCode: 0,
    timedOut: false,
    log: "one.log",
    elapsedSeconds: 0,
    caseIdentityMatches: true,
    unaccountedCases: [],
    timeoutScaleMatches: true,
    report: {
      complete: true,
      discovered: 1,
      pass: 1,
      fail: 0,
      pending: 0,
      loadErrors: [],
      asynchronousErrors: [],
    },
  };
  assert.equal(summarizeRuntime(manifest, [result], true).success, true);
  assert.equal(summarizeRuntime(manifest, [], true).success, false);
  assert.equal(summarizeRuntime(manifest, [result], false).success, false);
  for (const mutation of [
    { timedOut: true },
    { exitCode: 1 },
    { caseIdentityMatches: false },
    { unaccountedCases: ["one:1"] },
    { timeoutScaleMatches: false },
    { report: { ...result.report, complete: false } },
    { report: { ...result.report, pending: 1 } },
    { report: { ...result.report, loadErrors: ["error"] } },
    { report: { ...result.report, asynchronousErrors: ["error"] } },
  ])
    assert.equal(
      summarizeRuntime(manifest, [{ ...result, ...mutation }], true).success,
      false,
    );
});
