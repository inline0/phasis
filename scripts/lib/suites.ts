import * as Cause from "effect/Cause";
import * as Console from "effect/Console";
import * as Effect from "effect/Effect";
import * as Exit from "effect/Exit";
import { emit, fail, type ScriptInput } from "./cli.ts";
import * as Command from "./command.ts";

export interface Check {
  readonly label: string;
  readonly command: string;
  readonly args: ReadonlyArray<string>;
}

export const runChecks = (
  checks: ReadonlyArray<Check>,
  cwd: string,
  input: ScriptInput,
) =>
  Effect.gen(function* () {
    const results: Array<{ label: string; status: string; exitCode: number }> =
      [];
    for (const check of checks) {
      if (!input.json) yield* Console.log(check.label);
      const exit = yield* Effect.exit(
        Command.run({
          ...check,
          cwd,
          inherit: !input.json,
          check: false,
        }),
      );
      if (Exit.isFailure(exit) && Cause.hasInterruptsOnly(exit.cause)) {
        return yield* Effect.failCause(exit.cause);
      }
      const exitCode = Exit.isSuccess(exit) ? exit.value.exitCode : 1;
      const status = input.dryRun
        ? "planned"
        : exitCode === 0
          ? "pass"
          : "fail";
      results.push({ label: check.label, status, exitCode });
      if (!input.json) {
        yield* Console.log(`${status.toUpperCase()}: ${check.label}`);
        if (Exit.isFailure(exit))
          yield* Console.error(Cause.pretty(exit.cause));
      }
    }
    yield* emit(input, results, () =>
      input.dryRun
        ? "Validation commands planned."
        : results.every((result) => result.exitCode === 0)
          ? "ALL CHECKS PASSED"
          : "SOME CHECKS FAILED",
    );
    if (results.some((result) => result.exitCode !== 0)) {
      return yield* fail("Some checks failed");
    }
  });
