import assert from "node:assert/strict";
import { test } from "node:test";
import * as Effect from "effect/Effect";
import { runtimeLayer } from "../../../scripts/lib/runtime.ts";
import { execute, localOnly } from "../tooling.ts";

test("worker scale is explicit and never changes the process watchdog", async () => {
  await Effect.runPromise(
    Effect.gen(function* () {
      yield* localOnly();
      const argv = [
        process.execPath,
        "-e",
        "console.log(process.env.PHASIS_FRAMEWORK_TIMEOUT_SCALE)",
      ];
      assert.equal((yield* execute(argv, 5)).output.trim(), "1");
      assert.equal(
        (yield* execute(argv, 5, "main", undefined, 10)).output.trim(),
        "10",
      );
      const timed = yield* execute(
        [process.execPath, "-e", "setTimeout(()=>{},60000)"],
        0.05,
        "main",
        undefined,
        10,
      );
      assert.equal(timed.status, 124);
      assert.match(timed.output, /PROCESS TIMEOUT/);
    }).pipe(Effect.provide(runtimeLayer({ dryRun: false, logFormat: "text" }))),
  );
});
