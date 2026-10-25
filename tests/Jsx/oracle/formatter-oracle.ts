// Refresh native JavaScript/JSON/CSS formatter fixtures with the pinned Prettier.
// npm ci --prefix tests/Jsx/oracle
// node tests/Jsx/oracle/formatter-oracle.ts
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { emit, main, script } from "../../../scripts/lib/cli.ts";
import { readJson } from "../../../scripts/lib/files.ts";
import path from "node:path";
import { fileURLToPath } from "node:url";
import prettier from "prettier";

const fixtures = path.resolve(
  path.dirname(fileURLToPath(import.meta.url)),
  "../../Unit/Formatter/fixtures",
);
interface Case {
  name: string;
  source: string;
  type: string;
  options: prettier.Options;
  expected?: string;
}
export const command = script("formatter-oracle", {
  description:
    "Refresh formatter observations with the pinned Prettier package.",
  flags: {},
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(
          input,
          { status: "planned", directory: fixtures },
          JSON.stringify,
        );
      const fs = yield* FileSystem.FileSystem;
      const cases: Case[] = [];
      for (const file of (yield* fs.readDirectory(fixtures))
        .filter((name) => name.endsWith(".in.js"))
        .sort()) {
        const source = yield* fs.readFileString(path.join(fixtures, file));
        cases.push({
          name: file,
          source,
          type: /^\s*(import|export)\b/m.test(source) ? "module" : "script",
          options: { useTabs: true, singleQuote: true },
        });
      }
      for (const file of (yield* fs.readDirectory(path.join(fixtures, "css")))
        .filter((name) => name.endsWith(".in.css"))
        .sort()) {
        cases.push({
          name: `css/${file}`,
          source: yield* fs.readFileString(path.join(fixtures, "css", file)),
          type: "css",
          options: {},
        });
      }
      cases.push(
        ...(yield* readJson<Case[]>(
          path.join(fixtures, "prettier-input.json"),
        )),
      );
      for (const item of cases) {
        item.expected = yield* Effect.tryPromise(() =>
          prettier.format(item.source, {
            ...item.options,
            parser:
              item.type === "css"
                ? "css"
                : item.type === "json"
                  ? "json"
                  : "babel",
          }),
        );
      }
      yield* fs.writeFileString(
        path.join(fixtures, "prettier-expected.json"),
        JSON.stringify({ version: prettier.version, cases }, null, 2) + "\n",
      );
      yield* emit(
        input,
        { version: prettier.version, fixtures: cases.length },
        JSON.stringify,
      );
    }),
});
if (import.meta.main) main(command);
