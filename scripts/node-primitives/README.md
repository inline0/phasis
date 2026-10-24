# Node compatibility foundations

The runtime assets under `src/Node/Lib` execute inside Phasis. Node and npm are
development tools only; no runtime JavaScript execution is delegated to Node.

Regenerate the pinned upstream implementations and their license manifest with:

```sh
npm ci --ignore-scripts --prefix scripts/node-primitives
node scripts/node-primitives/build.mjs
node scripts/node-primitives/generate-oracles.mjs
XDEBUG_MODE=off vendor/bin/phpunit tests/Unit/Node
```

The lockfile records exact tarball integrity. The builder uses real `buffer`,
`events`, `readable-stream`, `string_decoder`, and Brotli implementations. The
entry files add modern Node APIs and Web Stream adapters while retaining shared
builtin identity. The readable-stream source includes the actual stream state
machines, error propagation, async iteration, pipeline, and backpressure.
Buffer fills use typed-array fills and doubling copies, preserving overlapping
patterns while avoiding interpreted byte loops for large allocations.
UTF-8 sizing and writes use the realm's TextEncoder, including replacement of
isolated surrogates and writes that stop before an incomplete encoded character.

Brotli 1.3.3 supplies a synchronous pure-JavaScript encoder and decoder. Its
immutable dictionary and compiler memory are represented as base64 rather than
large numeric literals or decompressed on every load. The encoder uses a 64 MiB
heap within Phasis's buffer allocation limits. C memory operations and two large
table fills map to equivalent typed-array methods. The wrapper corrects the
upstream output-capacity argument for incompressible input. All changes are
deterministic and checked against the pinned source before replacement.

The filesystem and zlib JavaScript adapters use PHP host operations and own
their descriptors and codec contexts through `NodeRuntime::onClose`. Filesystem
callbacks run through the Node job queue; file streams use actual descriptors
and demand-driven reads. PHP exposes second-resolution file timestamps and no
portable birth timestamp, so the stat adapter cannot reproduce native subsecond
or birth-time precision on every host. Filesystem watching and platform-specific
descriptor ownership operations are not currently exported.

Gzip/deflate streams are incremental native PHP zlib codecs. The bundled Brotli
stream fallback buffers whole frames, and partial flush reports an explicit
unsupported-feature error. Default quality 11 compression is substantially
slower in Phasis than the native zlib codecs. Changing zlib compression parameters
after construction is unavailable when PHP provides no `deflateParams` binding.

The VM adapter uses separate Engine realms and live sandbox globals, preserving
the caller's intrinsic defaults, Promise hooks, and async context. V8 cache
serialization, per-context code-generation controls, and compileFunction context
extensions report explicit unsupported-feature errors.

Child processes use PHP `proc_open` with nonblocking pipes and a realm-owned I/O
driver. Sync spawn/exec, async spawn/exec, binary stdin/stdout/stderr, backpressure,
timeouts, signals, aborts and cleanup are covered against native Node. Requests
to launch `process.execPath` with a JavaScript file run a separate Phasis PHP
entry point. JavaScript evaluation never goes through Node. IPC/fork, detached
sessions, uid/gid changes, custom argv0 and inherited descriptors beyond standard
streams report explicit unsupported-feature errors. Runtime cleanup terminates
and reaps each child process it directly owns.

The fixtures compare individual observable results with Node 26, including raw
binary payloads, malformed input, listener mutation, stream backpressure/errors,
filesystem range reads, resource cleanup, compression interoperability, and
cross-realm identity. These probes are regression coverage, not a claim that the
complete upstream Node test suite passes.
