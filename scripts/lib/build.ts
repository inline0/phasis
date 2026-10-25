import * as esbuild from "esbuild";
import type * as Duration from "effect/Duration";
import * as Effect from "effect/Effect";

// Dispose the compiler context on success, failure and interruption.
export const build = (options: esbuild.BuildOptions) =>
  Effect.acquireUseRelease(
    Effect.tryPromise(() => esbuild.context(options)),
    (context) => Effect.tryPromise(() => context.rebuild()),
    (context) => Effect.promise(() => context.dispose()),
  );

export const download = (url: string, timeout: Duration.Input = "30 seconds") =>
  Effect.tryPromise(async (signal) => {
    const response = await fetch(url, { signal });
    if (!response.ok) throw new Error(`${url}: HTTP ${response.status}`);
    return new Uint8Array(await response.arrayBuffer());
  }).pipe(Effect.timeout(timeout));
