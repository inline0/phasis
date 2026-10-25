import * as Argument from "effect/cli/Argument";
import * as Effect from "effect/Effect";
import { emit, fail, main, script } from "./lib/cli.ts";
import { capture, locate } from "./lib/scenarios.ts";

export const command = script("actual", {
  description: "Capture actual output for one original scenario.",
  flags: { scenario: Argument.String("scenario") },
  run: (input) =>
    Effect.gen(function* () {
      const directory = yield* locate(input.scenario);
      const status = yield* capture(directory, "actual", input.dryRun, false);
      yield* emit(input, { scenario: input.scenario, status }, (result) =>
        JSON.stringify(result),
      );
      if (status === "error")
        return yield* fail("Scenario process failed; output was retained");
    }),
});
if (import.meta.main) main(command);
