#!/usr/bin/env node
// Generate oracle.txt for a popular-package test by running the
// library + runner in a fresh vm context with `print` wired to
// stdout. Same execution model as Phasis: the library's UMD self-
// registers on globalThis, the runner pulls the export off
// globalThis, output goes through print().
//
// Usage: node gen-oracle.js <package-dir>

const fs = require("fs");
const vm = require("vm");
const path = require("path");

const dir = process.argv[2];
if (!dir) {
  console.error("usage: node gen-oracle.js <package-dir>");
  process.exit(1);
}

const libFile = fs.readdirSync(dir).find(
  (f) => f.endsWith(".js") && f !== "runner.js" && !f.startsWith("oracle"),
);
if (!libFile) {
  console.error(`no library .js file found in ${dir}`);
  process.exit(1);
}

const lib = fs.readFileSync(path.join(dir, libFile), "utf8");
const runner = fs.readFileSync(path.join(dir, "runner.js"), "utf8");

let buf = "";
const sandbox = {
  print: (s) => {
    buf += String(s) + "\n";
  },
  console: { log: (...a) => { buf += a.join(" ") + "\n"; } },
  // Phasis exposes these globally; mirror them into the Node sandbox
  // so runners that touch them (pako via Uint8Array+encoder, etc.)
  // don't fail at oracle-gen time with ReferenceError.
  TextEncoder, TextDecoder,
  Uint8Array, Uint16Array, Uint32Array, Int8Array, Int16Array, Int32Array,
  Float32Array, Float64Array, BigInt64Array, BigUint64Array, ArrayBuffer,
  Promise, Symbol, Map, Set, WeakMap, WeakSet,
  Error, TypeError, RangeError, SyntaxError, ReferenceError,
  // Web Platform basics that Phasis ships and many libraries
  // (jwt-decode, ulid, hash libs, etc.) reach for unconditionally.
  atob: typeof atob === "function" ? atob : undefined,
  btoa: typeof btoa === "function" ? btoa : undefined,
  URL: typeof URL === "function" ? URL : undefined,
  URLSearchParams: typeof URLSearchParams === "function" ? URLSearchParams : undefined,
  performance: typeof performance === "object" ? performance : undefined,
  globalThis: undefined, // gets set after createContext below
};
vm.createContext(sandbox);
sandbox.globalThis = sandbox;
vm.runInContext(lib + "\n;\n" + runner, sandbox, { filename: dir + "/bundle.js" });

process.stdout.write(buf);
