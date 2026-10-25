import * as Argument from "effect/cli/Argument";
import * as Effect from "effect/Effect";
import { emit, fail, main, script } from "./lib/cli.ts";
import { compare, locate } from "./lib/scenarios.ts";

export const command = script("compare", {
  description: "Compare captured scenario output byte for byte.",
  flags: { scenario: Argument.String("scenario") },
  run: (input) =>
    Effect.gen(function* () {
      const result = yield* compare(
        yield* locate(input.scenario),
        !input.dryRun,
      );
      yield* emit(input, result, (value) => JSON.stringify(value));
      if (result.status === "fail")
        return yield* fail("Scenario outputs differ");
    }),
});
if (import.meta.main) main(command);
