import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { resolve } from "node:path";
import * as Effect from "effect/Effect";
import * as Fiber from "effect/Fiber";
import * as FileSystem from "effect/FileSystem";
import * as Queue from "effect/Queue";
import { emit, fail, main, script } from "../../../scripts/lib/cli.ts";
import * as Command from "../../../scripts/lib/command.ts";
import { attempt } from "../../../scripts/lib/files.ts";
import { localOnly } from "../tooling.ts";

const directory = import.meta.dirname;
interface PeerResult {
  readonly id: number;
  readonly [key: string]: unknown;
}
interface Packet {
  readonly ready?: number;
  readonly results?: PeerResult[];
}
type Message = { packet: Packet } | { done: true };
export const command = script("http2-interop", {
  description:
    "Verify original native/PHP HTTP2 peer interoperability locally.",
  flags: {},
  run: (input) =>
    Effect.scoped(
      Effect.gen(function* () {
        if (input.dryRun)
          return yield* emit(
            input,
            { status: "planned", pairs: 4 },
            JSON.stringify,
          );
        yield* localOnly();
        const fs = yield* FileSystem.FileSystem;
        const materialDirectory = yield* fs.makeTempDirectoryScoped({
          prefix: "phasis-http2-interop-",
        });
        const materialOutput = yield* Command.run({
          command: "php",
          args: [resolve(directory, "certificate.php")],
          env: { XDEBUG_MODE: "off" },
        });
        const material = yield* attempt(
          () =>
            JSON.parse(materialOutput.stdout) as { key: string; cert: string },
        );
        yield* fs.writeFileString(
          resolve(materialDirectory, "key.pem"),
          material.key,
          { mode: 0o600 },
        );
        yield* fs.writeFileString(
          resolve(materialDirectory, "cert.pem"),
          material.cert,
          { mode: 0o600 },
        );
        const launch = (
          runtime: string,
          file: string,
          args: string[],
          secure: boolean,
        ) =>
          Effect.gen(function* () {
            const queue = yield* Queue.unbounded<Message>();
            const child = yield* Effect.forkScoped(
              Command.run({
                command: runtime === "node" ? process.execPath : "php",
                args:
                  runtime === "node"
                    ? [resolve(directory, file), ...args]
                    : [
                        "-d",
                        "memory_limit=1G",
                        resolve(directory, "run-php.php"),
                        resolve(directory, file),
                        ...args,
                      ],
                env: {
                  XDEBUG_MODE: "off",
                  ...(secure
                    ? {
                        PHASIS_HTTP2_TLS_KEY: resolve(
                          materialDirectory,
                          "key.pem",
                        ),
                        PHASIS_HTTP2_TLS_CERT: resolve(
                          materialDirectory,
                          "cert.pem",
                        ),
                      }
                    : {}),
                },
                onLine: (line, stream) =>
                  stream === "stderr"
                    ? Effect.void
                    : attempt(() => JSON.parse(line) as Packet).pipe(
                        Effect.flatMap((packet) =>
                          Queue.offer(queue, { packet }),
                        ),
                        Effect.asVoid,
                        Effect.catch(() => Effect.void),
                      ),
              }).pipe(Effect.ensuring(Queue.offer(queue, { done: true }))),
            );
            return {
              next: Queue.take(queue).pipe(
                Effect.flatMap((message) =>
                  "packet" in message
                    ? Effect.succeed(message.packet)
                    : fail(runtime + " exited before producing a report"),
                ),
              ),
              completion: Fiber.join(child),
            };
          });
        const reports: object[] = [];
        for (const [serverRuntime, clientRuntime, secure] of [
          ["node", "phasis", false],
          ["phasis", "node", false],
          ["node", "phasis", true],
          ["phasis", "node", true],
        ] as const) {
          const server = yield* launch(serverRuntime, "server.cjs", [], secure);
          const { ready } = yield* server.next;
          assert.equal(typeof ready, "number");
          const client = yield* launch(
            clientRuntime,
            "client.cjs",
            [String(ready)],
            secure,
          );
          const { results } = yield* client.next;
          assert.ok(results);
          assert.equal(results.length, 3);
          for (const result of results)
            assert.deepEqual(result, {
              id: result.id,
              status: 201,
              path: "/peer/" + result.id,
              header: "shared header value across streams",
              trailer: "yes",
              bytes: 70000 + result.id,
              sha256: createHash("sha256")
                .update(Buffer.alloc(70000 + result.id, 65 + result.id))
                .digest("hex"),
            });
          yield* client.completion;
          yield* server.completion;
          reports.push({
            server: serverRuntime,
            client: clientRuntime,
            tls: secure,
            streams: results.length,
            passed: true,
          });
        }
        yield* emit(input, reports, (rows) =>
          rows.map((row) => JSON.stringify(row)).join("\n"),
        );
      }),
    ).pipe(Effect.timeout("60 seconds")),
});
if (import.meta.main) main(command);
