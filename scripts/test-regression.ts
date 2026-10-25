import { join } from "node:path";
import * as Flag from "effect/cli/Flag";
import * as Console from "effect/Console";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { emit, fail, main, script } from "./lib/cli.ts";
import { capture, compare, discover } from "./lib/scenarios.ts";

export const command = script("test-regression", {
  description:
    "Compare all original scenario outputs without changing their oracles.",
  flags: {
    category: Flag.String("category").pipe(Flag.withDefault("")),
    jobs: Flag.Int("jobs").pipe(Flag.withDefault(1)),
    fast: Flag.Boolean("fast").pipe(Flag.withDefault(false)),
  },
  run: (input) =>
    Effect.gen(function* () {
      if (input.jobs < 1) return yield* fail("--jobs must be positive", 2);
      const fs = yield* FileSystem.FileSystem;
      const directories = yield* discover(input.category);
      const results = yield* Effect.forEach(
        directories,
        (directory) =>
          Effect.gen(function* () {
            if (!(yield* fs.exists(join(directory, "oracle/output.txt")))) {
              return { scenario: directory, status: "skip" };
            }
            if (input.dryRun) return { scenario: directory, status: "planned" };
            yield* capture(directory, "actual", false);
            return yield* compare(directory, false);
          }),
        { concurrency: input.jobs },
      );
      const totals = {
        pass: results.filter((result) => result.status === "pass").length,
        fail: results.filter((result) => result.status === "fail").length,
        skip: results.filter((result) => result.status === "skip").length,
        planned: results.filter((result) => result.status === "planned").length,
        total: results.length,
      };
      if (!input.fast && !input.json) {
        for (const result of results)
          yield* Console.log(
            `${result.status.toUpperCase()}: ${result.scenario}`,
          );
      }
      yield* emit(input, totals, (value) =>
        input.dryRun
          ? `Planned ${value.planned} scenarios; ${value.skip} skipped.`
          : `Results: ${value.pass} passed, ${value.fail} failed, ${value.skip} skipped (total: ${value.total})`,
      );
      if (totals.fail > 0) return yield* fail("Scenario regression failed");
    }),
});
if (import.meta.main) main(command);
