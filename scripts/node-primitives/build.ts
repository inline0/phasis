import * as Effect from "effect/Effect";
import * as FileSystem from "effect/FileSystem";
import { build } from "../lib/build.ts";
import { emit, main, script } from "../lib/cli.ts";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";

const directory = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const output = resolve(directory, "../../src/Node/Lib");
export const command = script("build-node-primitives", {
  description: "Rebuild pinned Node primitive bundles, manifests and licenses.",
  flags: {},
  run: (input) =>
    Effect.gen(function* () {
      if (input.dryRun)
        return yield* emit(
          input,
          { status: "planned", output },
          JSON.stringify,
        );
      const fs = yield* FileSystem.FileSystem;
      const readFile = (path: string, _encoding: string) =>
        Effect.runPromise(fs.readFileString(path));
      yield* fs.makeDirectory(output, { recursive: true });

      const entries = [
        "buffer",
        "events",
        "stream",
        "string_decoder",
        "stream-promises",
        "stream-consumers",
        "stream-web",
        "brotli-decoder",
        "brotli-encoder",
      ];
      const packages = new Set<string>();
      const outputs: Record<string, { bytes: number; requires: string[] }> = {};
      for (const name of entries) {
        const result = yield* build({
          absWorkingDir: directory,
          entryPoints: [`${name}-entry.cjs`],
          outfile: resolve(output, `${name}.cjs`),
          bundle: true,
          format: "cjs",
          platform: "node",
          target: "esnext",
          legalComments: "inline",
          metafile: true,
          external: [
            "buffer",
            "events",
            "stream",
            "string_decoder",
            "process",
            "async_hooks",
            "abort-controller",
            "fs",
            "path",
          ],
          plugins: [
            {
              name: "canonical-core-module-names",
              setup(builder) {
                builder.onResolve(
                  { filter: /^(?:process|string_decoder)\/$/ },
                  ({ path }) => ({ path: path.slice(0, -1), external: true }),
                );
                builder.onLoad(
                  { filter: /node_modules\/buffer\/index\.js$/ },
                  async ({ path }) => {
                    let source = await readFile(path, "utf8");
                    for (const [loop, replacement] of [
                      [
                        "return utf8ToBytes(string).length",
                        "return new TextEncoder().encode(string).length",
                      ],
                      [
                        "return mustMatch ? -1 : utf8ToBytes(string).length",
                        "return mustMatch ? -1 : new TextEncoder().encode(string).length",
                      ],
                      [
                        "return blitBuffer(utf8ToBytes(string, buf.length - offset), buf, offset, length)",
                        "return new TextEncoder().encodeInto(string, new Uint8Array(buf.buffer, buf.byteOffset + offset, length)).written",
                      ],
                      [
                        "for (i = start; i < end; ++i) {\n      this[i] = val\n    }",
                        "Uint8Array.prototype.fill.call(this, val, start, end)",
                      ],
                      [
                        "for (i = 0; i < end - start; ++i) {\n      this[i + start] = bytes[i % len]\n    }",
                        `const count = end - start
    const seed = Math.min(len, count)
    Uint8Array.prototype.set.call(this, bytes.subarray(0, seed), start)
    for (let filled = seed; filled < count; filled *= 2) {
      Uint8Array.prototype.copyWithin.call(this, start + filled, start, start + Math.min(filled, count - filled))
    }`,
                      ],
                    ]) {
                      if (!source.includes(loop))
                        throw new Error("Unexpected pinned Buffer fill loop");
                      source = source.replace(loop, replacement);
                    }
                    return { contents: source, loader: "js" };
                  },
                );
                builder.onLoad(
                  { filter: /brotli\/dec\/dictionary-data\.js$/ },
                  async ({ path }) => {
                    const source = await readFile(path, "utf8");
                    const data = Buffer.from(require(path).dictionary).toString(
                      "base64",
                    );
                    return {
                      contents: `${source.slice(0, source.indexOf("exports.dictionary"))}\n// Equivalent dictionary bytes avoid parsing 122,784 numeric literals at runtime.\nexports.dictionary = Uint8Array.fromBase64(${JSON.stringify(data)});\nexports.init = function () { return exports.dictionary; };`,
                      loader: "js",
                    };
                  },
                );
                builder.onLoad(
                  { filter: /brotli\/build\/encode\.js$/ },
                  async ({ path }) => {
                    let source = await readFile(path, "utf8");
                    const compressed = require(
                      resolve(directory, "node_modules/brotli/build/mem.js"),
                    );
                    const memory = require(
                      resolve(directory, "node_modules/brotli/decompress.js"),
                    )(Buffer.from(compressed, "base64"));
                    const prefix =
                      'var b={},aa=require("../decompress"),ba=require("base64-js");b.readBinary=function(){var a=ba.toByteArray(require("../build/mem.js"));return aa(a)};';
                    if (!source.startsWith(prefix))
                      throw new Error(
                        "Unexpected pinned Brotli encoder prelude",
                      );
                    // Predecode immutable compiler memory during the reproducible build, not each engine bootstrap.
                    source =
                      `var b={TOTAL_MEMORY:67108864};b.readBinary=function(){return Uint8Array.fromBase64(${JSON.stringify(Buffer.from(memory).toString("base64"))})};` +
                      source.slice(prefix.length);
                    source = source.replace(
                      'process.on("uncaughtException",function(a){if(!(a instanceof y))throw a;});',
                      "",
                    );
                    for (const [loop, fill] of [
                      [
                        "i=0;while(1){if((i|0)==131072)break;c[d+4+(i<<2)>>2]=e;i=i+1|0}",
                        "c.fill(e,d+4>>2,(d+4>>2)+131072);i=131072;",
                      ],
                      [
                        "n=0;while(1){if((n|0)==131072)break;c[ua+4+(n<<2)>>2]=f;n=n+1|0}",
                        "c.fill(f,ua+4>>2,(ua+4>>2)+131072);n=131072;",
                      ],
                    ]) {
                      if (!source.includes(loop))
                        throw new Error(
                          "Unexpected pinned Brotli hash-table initialization",
                        );
                      source = source.replace(loop, fill);
                    }
                    // These C memory primitives map directly to typed-array operations and avoid multi-million-step JS byte loops.
                    for (const [name, replacement] of [
                      [
                        "ac",
                        "function ac(b,d,e){b=b|0;d=d|0;e=e|0;a.fill(d&255,b,b+e);return b|0}",
                      ],
                      [
                        "dc",
                        "function dc(b,d,e){b=b|0;d=d|0;e=e|0;a.copyWithin(b,d,d+e);return b|0}",
                      ],
                      [
                        "fc",
                        "function fc(b,c,d){b=b|0;c=c|0;d=d|0;a.copyWithin(b,c,c+d);return b|0}",
                      ],
                    ]) {
                      const start = source.indexOf(`function ${name}(`);
                      const end = source.indexOf("function ", start + 9);
                      if (start < 0 || end < 0)
                        throw new Error(
                          `Unexpected pinned Brotli memory primitive: ${name}`,
                        );
                      source =
                        source.slice(0, start) +
                        replacement +
                        source.slice(end);
                    }
                    return { contents: source, loader: "js" };
                  },
                );
              },
            },
          ],
          banner: {
            js: "// Generated by scripts/node-primitives/build.ts. See primitives-LICENSES.txt.",
          },
        });
        for (const input of Object.keys(result.metafile!.inputs)) {
          const match = /^node_modules\/((?:@[^/]+\/)?[^/]+)\//.exec(input);
          if (match) packages.add(match[1]);
        }
        const metadata = Object.values(result.metafile!.outputs)[0];
        outputs[name] = {
          bytes: metadata.bytes,
          requires: [
            ...new Set(
              metadata.imports
                .filter((entry) => entry.external)
                .map((entry) => entry.path),
            ),
          ].sort(),
        };
      }
      const lock = JSON.parse(
        yield* fs.readFileString(resolve(directory, "package-lock.json")),
      );
      const upstream: Record<string, { version: string; integrity: string }> =
        {};
      const licenses: string[] = [];
      for (const name of [...packages].sort()) {
        const metadata = JSON.parse(
          yield* fs.readFileString(
            resolve(directory, "node_modules", name, "package.json"),
          ),
        );
        upstream[name] = {
          version: metadata.version,
          integrity: lock.packages[`node_modules/${name}`].integrity,
        };
        let license;
        for (const file of ["LICENSE", "LICENSE.md", "LICENSE-MIT.txt"]) {
          const candidate = resolve(directory, "node_modules", name, file);
          if (yield* fs.exists(candidate)) {
            license = yield* fs.readFileString(candidate);
            break;
          }
        }
        if (!license && name === "brotli")
          license = yield* fs.readFileString(
            resolve(directory, "brotli-LICENSES.txt"),
          );
        if (!license) throw new Error(`Missing upstream license: ${name}`);
        licenses.push(`${name} ${metadata.version}\n${license.trim()}\n`);
      }
      yield* fs.writeFileString(
        resolve(output, "primitives-manifest.json"),
        `${JSON.stringify({ upstream, outputs }, null, 2)}\n`,
      );
      yield* fs.writeFileString(
        resolve(output, "primitives-LICENSES.txt"),
        licenses.join("\n"),
      );
      yield* emit(
        input,
        { entries: entries.length, packages: packages.size },
        (value) =>
          `Built ${value.entries} Node primitive modules from ${value.packages} pinned packages.`,
      );
    }),
});
if (import.meta.main) main(command);
