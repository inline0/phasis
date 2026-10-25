// Regenerates src/BuiltIn/Jsx/React/Fizz/Scripts.php: React's inline
// instruction scripts ($RC, $RX, $RS, ...), copied byte for byte from the
// pinned react-dom server bundle.
//
//   node tests/Jsx/oracle/build-scripts.ts
import { fileURLToPath } from "node:url";
import vm from "node:vm";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { emit, main, script } from "../../../scripts/lib/cli.ts";

export const command = script("build-react-scripts", {
  description: "Copy the pinned React Fizz instruction scripts byte for byte.",
  flags: {},
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(input, { status: "planned" }, JSON.stringify);
      const fs = yield* FileSystem.FileSystem;
      const version = JSON.parse(
        yield* fs.readFileString(
          fileURLToPath(
            new URL("node_modules/react-dom/package.json", import.meta.url),
          ),
        ),
      ).version;
      const src = yield* fs.readFileString(
        fileURLToPath(
          new URL(
            "node_modules/react-dom/cjs/react-dom-server.node.production.js",
            import.meta.url,
          ),
        ),
      );
      const names = [
        "formReplayingRuntimeScript",
        "shellTimeRuntimeScript",
        "completeSegmentScript1Full",
        "completeBoundaryScriptFunctionOnly",
        "completeBoundaryWithStylesScript1FullPartial",
        "clientRenderScriptFunctionOnly",
        "clientRenderScript1Full",
      ];
      const out: Record<string, string> = {};
      const outFile = new URL(
        "../../../src/BuiltIn/Jsx/React/Fizz/Scripts.php",
        import.meta.url,
      );
      for (const name of names) {
        const m = new RegExp(
          name +
            " = stringToPrecomputedChunk\\(\\s*((?:'(?:[^'\\\\]|\\\\.)*')|(?:\"(?:[^\"\\\\]|\\\\.)*\"))\\s*\\)",
        ).exec(src);
        if (!m) throw new Error("not found " + name);
        out[name] = vm.runInNewContext(m[1]) as string;
      }
      const vt =
        /writeChunk\(\s*destination,\s*('\$RV=function\(B,g\)(?:[^'\\]|\\.)*')\s*\)/.exec(
          src,
        );
      if (vt === null) throw new Error("View transition script not found");
      out.viewTransitionScript = vm.runInNewContext(vt[1]) as string;
      const php = [
        "<?php",
        "",
        "/**",
        ` * React ${version} inline instruction scripts, copied byte for byte from`,
        " * react-dom-server.node.production.js.",
        " *",
        " * Copyright (c) Meta Platforms, Inc. and affiliates.",
        " *",
        " * This source code is licensed under the MIT license found in the",
        " * LICENSE file in the root directory of this source tree",
        " * (third-party/react/LICENSE in Phasis).",
        " */",
        "",
        "declare(strict_types=1);",
        "",
        "namespace Phasis\\BuiltIn\\Jsx\\React\\Fizz;",
        "",
        "final class Scripts",
        "{",
      ];
      const constName = (n: string) =>
        n.replace(/([A-Z0-9]+)/g, "_$1").toUpperCase();
      for (const [k, v] of Object.entries(out)) {
        php.push(`    // ${k}`);
        php.push(
          `    public const ${constName(k)} = ${JSON.stringify(v).replace(/\$/g, "\\$")};`,
        );
        php.push("");
      }
      php.pop();
      php.push("}", "");
      yield* fs.writeFileString(fileURLToPath(outFile), php.join("\n"));
      yield* emit(
        input,
        { version, scripts: Object.keys(out).length },
        JSON.stringify,
      );
    }),
});
if (import.meta.main) main(command);
