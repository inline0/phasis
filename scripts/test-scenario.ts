import * as Argument from "effect/cli/Argument";
import * as Effect from "effect/Effect";
import { emit, fail, main, script } from "./lib/cli.ts";
import { capture, compare, locate } from "./lib/scenarios.ts";

export const command = script("test-scenario", {
  description:
    "Capture an oracle and engine output, then compare one scenario.",
  flags: { scenario: Argument.String("scenario") },
  run: (input) =>
    Effect.gen(function* () {
      const directory = yield* locate(input.scenario);
      yield* capture(directory, "oracle", input.dryRun);
      yield* capture(directory, "actual", input.dryRun);
      const result = input.dryRun
        ? { scenario: input.scenario, status: "planned" }
        : yield* compare(directory, true);
      yield* emit(input, result, (value) => JSON.stringify(value));
      if (result.status === "fail")
        return yield* fail("Scenario outputs differ");
    }),
});
if (import.meta.main) main(command);
