import { createHash } from "node:crypto";
import { resolve } from "node:path";
import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { download } from "../lib/build.ts";
import { emit, insist, main, script } from "../lib/cli.ts";
import * as Command from "../lib/command.ts";
import { readJson } from "../lib/files.ts";

const here = import.meta.dirname;
const root = resolve(here, "../..");
const library = resolve(root, "src/Node/Lib");
interface Source {
  readonly url: string;
  readonly sha256: string;
}
interface Pins {
  readonly node: string;
  readonly sources: Record<string, Source>;
}
interface NpmLock {
  readonly packages: Record<string, { readonly version: string }>;
}
const sources = {
  path: "path.cjs",
  querystring: "querystring.cjs",
  url: "url.cjs",
  "internal/querystring": "portable/internal-querystring.cjs",
  "internal/constants": "portable/constants.cjs",
};
// Preserve Python's canonical JSON escaping in existing asset inventories.
const pythonJson = (value: unknown) =>
  JSON.stringify(value, null, 2).replace(
    /[\u007f-\uffff]/g,
    (character) =>
      "\\u" + character.charCodeAt(0).toString(16).padStart(4, "0"),
  );
const sha256 = (bytes: Uint8Array) =>
  createHash("sha256").update(bytes).digest("hex");
export const rewrite = (text: string, source: string, target: string) => {
  const prefix = target.includes("/") ? "./" : "./portable/";
  let rewritten = text.replaceAll(
    "} = primordials;",
    "} = require('" + prefix + "primordials.cjs');",
  );
  for (const [name, module] of [
    ["constants", "constants"],
    ["validators", "validators"],
    ["errors", "errors"],
    ["querystring", "internal-querystring"],
    ["util", "helpers"],
  ])
    rewritten = rewritten.replaceAll(
      "require('internal/" + name + "')",
      "require('" + prefix + module + ".cjs')",
    );
  rewritten = rewritten
    .replaceAll("require('internal/fs/glob')", "require('./portable/glob.cjs')")
    .replaceAll(
      "require('internal/url')",
      "require('./portable/whatwg-url.cjs')",
    )
    .replaceAll("internalBinding('url_pattern')", "globalThis")
    .replaceAll(
      "internalBinding('encoding_binding')",
      "{ toASCII: require('./portable/whatwg-url.cjs').domainToASCII }",
    )
    .replaceAll("internalBinding('util')", "require('./portable/helpers.cjs')")
    .replaceAll(
      "internalBinding('url')",
      "require('./portable/whatwg-url.cjs')",
    );
  if (source === "path") {
    const signature =
      "function normalizeString(path, allowAboveRoot, separator, isPathSeparator) {";
    if (rewritten.split(signature).length !== 2)
      throw new Error("Pinned Node path normalizer signature changed");
    rewritten = rewritten.replace(
      signature,
      signature +
        "\n  if (globalThis.__phasisNodeHost && __phasisNodeHost.path)\n    return __phasisNodeHost.path.normalizeString(path, allowAboveRoot, separator);",
    );
  }
  return rewritten;
};
export const command = script("build-portable-node", {
  description: "Rebuild portable Node modules from checksum-pinned sources.",
  flags: {},
  run: (input) =>
    Effect.gen(function* () {
      const fs = yield* FileSystem.FileSystem;
      const pins = yield* readJson<Pins>(resolve(here, "node-sources.json"));
      if (input.dryRun)
        return yield* emit(
          input,
          { status: "planned", node: pins.node },
          JSON.stringify,
        );
      yield* fs.makeDirectory(resolve(library, "portable"), {
        recursive: true,
      });
      const hashes: Record<string, Source> = {};
      const primordialNames = new Set<string>();
      const readPinned = (source: Source) =>
        Effect.gen(function* () {
          const bytes = yield* download(source.url);
          yield* insist(
            sha256(bytes) === source.sha256,
            "Node source checksum mismatch: " + source.url,
          );
          return bytes;
        });
      for (const [source, target] of Object.entries(sources)) {
        const bytes = yield* readPinned(pins.sources[source]);
        hashes[source] = pins.sources[source];
        const text = new TextDecoder().decode(bytes);
        for (const match of text.matchAll(
          /const \{([^}]+)\} = primordials;/g,
        )) {
          for (const name of match[1].match(/\b\w+\b/g) ?? [])
            primordialNames.add(name);
        }
        yield* fs.writeFileString(
          resolve(library, target),
          rewrite(text, source, target),
        );
      }
      yield* fs.writeFile(
        resolve(library, "portable/NODE_LICENSE.txt"),
        yield* readPinned(pins.sources.LICENSE),
      );
      hashes.LICENSE = pins.sources.LICENSE;
      const aliases: Record<string, string> = {
        ArrayIsArray: "Array.isArray",
        MathAbs: "Math.abs",
        NumberIsFinite: "Number.isFinite",
        ObjectKeys: "Object.keys",
        ObjectAssign: "Object.assign",
      };
      const entries = [...primordialNames].sort().map((name) => {
        const match = /^(\w+)Prototype(\w+)$/.exec(name);
        const value = match
          ? `Function.call.bind(${match[1]}.prototype.${match[2][0].toLowerCase() + match[2].slice(1)})`
          : (aliases[name] ?? name);
        return `  ${name}: ${value},`;
      });
      yield* fs.writeFileString(
        resolve(library, "portable/primordials.cjs"),
        "'use strict';\n\n// Capture intrinsics before user code can replace their prototype methods.\nmodule.exports = {\n" +
          entries.join("\n") +
          "\n};\n",
      );
      for (const [name, module] of [
        ["util-base", "util/util.js"],
        ["assert", "assert/build/assert.js"],
        ["minimatch", "minimatch"],
        ["punycode", "punycode/punycode.js"],
        ["tr46", "tr46"],
        ["hpack", "hpack.js"],
      ]) {
        const source = resolve(here, ".entry-" + name + ".cjs");
        yield* insist(
          !(yield* fs.exists(source)),
          "Build entry already exists: " + source,
        );
        const target = resolve(
          library,
          name === "assert" ? "assert.cjs" : "portable/" + name + ".cjs",
        );
        yield* Effect.acquireUseRelease(
          fs.writeFileString(
            source,
            "module.exports = require(" + JSON.stringify(module) + ");\n",
          ),
          () =>
            Command.run({
              command: resolve(here, "node_modules/esbuild/bin/esbuild"),
              args: [
                source,
                "--bundle",
                "--platform=node",
                "--format=cjs",
                "--target=es2022",
                "--legal-comments=inline",
                "--outfile=" + target,
                ...(name === "hpack" ? ["--alias:readable-stream=stream"] : []),
              ],
              cwd: root,
            }),
          () => fs.remove(source).pipe(Effect.orDie),
        );
      }
      const lockBytes = yield* fs.readFile(resolve(here, "package-lock.json"));
      const lock = yield* readJson<NpmLock>(resolve(here, "package-lock.json"));
      const licenses: Record<string, string> = {};
      for (const [name, details] of Object.entries(lock.packages)) {
        if (name === "") continue;
        const directory = resolve(here, name);
        let license: string | undefined;
        for (const candidate of [
          "LICENSE",
          "LICENSE.md",
          "LICENSE.txt",
          "license",
          "COPYING",
        ]) {
          const file = resolve(directory, candidate);
          if (yield* fs.exists(file)) {
            license = (yield* fs.readFileString(file)).replace(/\r\n?/g, "\n");
            break;
          }
        }
        if (
          license === undefined &&
          (yield* fs.exists(resolve(directory, "README.md")))
        ) {
          const readme = yield* fs.readFileString(
            resolve(directory, "README.md"),
          );
          const match = /^#+.*LICENSE.*$/im.exec(readme);
          if (match !== null)
            license = readme.slice(match.index).replace(/\r\n?/g, "\n");
        }
        if (license !== undefined)
          licenses[
            name.replace(/^node_modules\//, "") + "@" + details.version
          ] = license;
      }
      yield* fs.writeFileString(
        resolve(library, "portable/LICENSES.json"),
        pythonJson(licenses) + "\n",
      );
      yield* fs.copyFile(
        resolve(here, "http2-constants.json"),
        resolve(library, "portable/http2-constants.json"),
      );
      yield* fs.writeFileString(
        resolve(library, "portable/sources.json"),
        pythonJson({
          node: pins.node,
          sources: hashes,
          npmLockSha256: sha256(lockBytes),
        }) + "\n",
      );
      yield* emit(
        input,
        {
          status: "built",
          node: pins.node,
          modules: Object.keys(sources).length + 6,
        },
        JSON.stringify,
      );
    }),
});
if (import.meta.main) main(command);
