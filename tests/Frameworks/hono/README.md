# Hono upstream compatibility

The ignored source tree in `upstream/hono-4.13.13` is the unchanged Hono v4.13.13
archive at commit `08a023cbfde55b434fb0fb30fae35d42e4bf20aa`. The repository,
commit archive URL, SHA-256 checksum, tag and revision are recorded in
`upstream.json`. Setup verifies the downloaded archive and rejects edited
existing source files.
`python3 tests/Frameworks/hono/inventory.py` lists every original project,
including Bun, Deno, Fastly and workerd suites, with source hashes.

Install the exact shared development dependencies with `npm ci --prefix
tests/Frameworks`, then run from the Phasis repository root:

```bash
python3 -B tests/Frameworks/hono/setup.py
python3 tests/Frameworks/hono/run.py
python3 tests/Frameworks/hono/run.py --project jsx-runtime-default
python3 tests/Frameworks/hono/run.py --project jsx-runtime-dom
python3 tests/Frameworks/hono/run.py --project node
python3 tests/Frameworks/hono/run-toolchain.py
python3 -B tests/Frameworks/hono/selftest.py
```

Pass upstream relative filenames to narrow a run, for example:

```bash
python3 tests/Frameworks/hono/run.py src/context.test.ts src/request.test.ts
```

`--runtime node` and `--runtime phasis` select a single runtime. The default
runs both and compares every discovered case's identity and outcome. Each
file runs in a separate process, sequentially, with a 1,800 second outer wall
limit (`--timeout` overrides it). This lets large files finish hundreds of
cases while retaining the original per-test and per-hook deadlines.
The PHP worker uses `XDEBUG_MODE=off`, a 4 GiB memory limit and a one million
iteration limit so the unchanged 100,000-entry parser and large-body stress
cases fit. Override memory with `--php-memory-limit 2G`; positive byte counts
and integer K/M/G suffixes are accepted. The largest jsdom file retains pending
Window initialization callbacks until a real host turn and measured a 2.83 GB
peak. The cap accommodates this peak without inserting extra host turns or
changing any test deadlines. The selected limit is recorded in the run manifest.
A timeout kills the process group, including compiler children.
The worker closes host resources after the suite completes, as Vitest does
when terminating its worker. This also cleans up servers that upstream
runtime tests intentionally leave open.

`build.mjs` uses esbuild only during test preparation. It transpiles the
original source and assertions to a strict CommonJS module graph, preserving
original `import.meta` and filename metadata as well as module
boundaries for module reset and spies. It makes transpiler-generated export
accessors configurable and preserves namespace identity to match Vitest's
mockable module host. It does not replace package implementation code or
rewrite assertions. Phasis executes all generated JavaScript in PHP.

`harness.mjs` uses the real pinned Vitest assertion and spy implementations,
Chai and Sinon fake timers. Its collector implements suites, parameterized
cases, asynchronous registration, hooks and explicit upstream skips.
Unsupported harness operations throw and are reported separately. Upstream
skip declarations remain skips, never passes. The test environment clears
`NO_COLOR` for both engines, matching the color tests' unstubbed assumption.
The Node runtime project sets `NAME=Node`, as its upstream configuration does.
Test and hook deadlines preserve Vitest 5's defaults of 5,000 ms and 10,000 ms,
explicit numeric/options values, suite inheritance and disabled deadlines.
Captured real timers and monotonic time keep deadlines effective under fake
timers and synchronous or microtask starvation. Failed suite setup is recorded
as a suite failure with blocked cases, separately from upstream skip declarations.
The self-test compares intentional async, synchronous, microtask, mock-timer
and hook deadline failures against the actual pinned Vitest runner. It also
compares `nextTick`, Promise, microtask and immediate ordering across hooks,
synchronous tests, asynchronous tests and an explicit host turn. Execution
starts after a microtask boundary, matching Vitest's asynchronous collection;
the runner does not insert extra host turns between tests.

Every invocation writes an immutable run directory under `results/runs/`.
Its manifest records the upstream revision, project, complete project
inventory, selected files, source SHA-256 values, runtime outcomes, elapsed
time per file, and runtime, harness and dependency hashes at the start and
end, plus case comparison failures. A run whose source or dependencies changed is exploratory evidence
and cannot establish a result for a single revision. Per-file logs and case reports belong to that same run.
`results/manifest.json` and the top-level per-file reports are convenience
copies; reports from different runs must not be combined into a completeness
claim. Empty discovery, collection errors, process failures and harness
limitations fail the command.

The separate toolchain runner prepares compile-time fixtures from the exactly
pinned published Hono package and runs the five unchanged declaration checks
with the pinned native TypeScript compiler. It links the ignored
`dist` build output to that package; it does not change upstream sources.
Its immutable manifest and native Vitest report remain separate from runtime
results. To prepare the declaration fixtures for a direct native Vitest run:

```bash
node tests/Frameworks/hono/prepare-toolchain.mjs
```

An independent native Vitest baseline runs the original source:

```bash
node tests/Frameworks/node_modules/vitest/vitest.mjs run \
  --config tests/Frameworks/hono/vitest.config.mjs \
  --reporter=json --outputFile=/tmp/hono-native.json
```

Set `PHASIS_HONO_PROJECT` to one of the project names above to select that
project in native Vitest. Compare an independent native report against a
single immutable runner manifest with:

```bash
python3 tests/Frameworks/hono/compare-native.py /tmp/hono-native.json \
  tests/Frameworks/hono/results/runs/RUN/manifest.json --runtime node
```

This compares names, multiplicities and outcomes for every selected file.
Missing reports and native collection failures are explicit failures.

The upstream main project contains 122 source test files and two build test
files. The latter spawn the TypeScript compiler against published declaration
artifacts; the runtime runner selects the 122 source files, while both build
files remain visible in the original main inventory and run through the
separate toolchain command. They are not runtime passes.
The two additional JSX projects each rerun nine DOM/hooks files with their
respective JSX import source. The Node runtime project tests the actual
`@hono/node-server` package over HTTP. Upstream also has Fastly, workerd, Bun
and Deno runtime-specific suites; these require their respective hosts and
are not represented by the Node project. Passing the main project alone is
not a claim that all upstream projects or all host environments pass.
