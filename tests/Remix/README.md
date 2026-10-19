# Remix compatibility

This fixture uses the original, unbundled npm release of `remix@3.0.0` from
[remix-run/remix](https://github.com/remix-run/remix/releases/tag/remix%403.0.0).
The lockfile pins its dependencies and the optional MySQL/PostgreSQL drivers,
so importing those adapters does not require a running database. Nothing is
added to Phasis's production dependencies. Upstream code is not patched,
transpiled or replaced with host API shims.

## Run

Use Node >=24.3.0 and the normal Composer development install:

```sh
cd tests/Remix
npm ci --ignore-scripts --no-audit --no-fund
npm run oracle:check
cd ../..
XDEBUG_MODE=off php bin/test-remix
XDEBUG_MODE=off php bin/test-remix --json > tests/Remix/report.json
```

The diagnostic command exits **1** while any probe fails or is blocked, **0**
only when all probes pass, and **2** for setup or fixture errors. Its JSON
contains every result, the package version/integrity, Node/PHP versions and
expected versus actual values.

## What is checked

- All **99 JavaScript runtime exports** from the published `exports` map are
  imported in separate processes. Their namespace names and value types must
  match Node. The three type-only exports and the package metadata export are
  inventoried separately; importing a types-only root is not a runtime API.
- **13 behavior probes** exercise route patterns, URL generation, route maps,
  header parsing/mutation, unsigned and signed cookies, schema coercion and
  validation errors, HTML escaping, MIME detection, HTML/redirect responses,
  request routing/404s, CORS/preflight, component server rendering and session
  persistence/flash data. The same `.mjs` files execute in Node and Phasis.
- Database adapter **imports** are checked. Database queries, external services,
  browser hydration, interactive DOM behavior, HMR orchestration and the full
  upstream test suite are not covered. A passing import does not mean every
  function in that module works.

Initial results: **81 pass, 30 fail, 1 blocked**. Imports: 74 pass, 24 fail,
1 blocked. Behavior probes: 7 pass and 6 fail. The remaining Node-oracle blocker
is `remix/node-hmr/runtime`, which requires its HMR host process. Phasis failures
include unavailable Node built-ins and mismatched routing, CORS, cookie, session
and component SSR behavior.

The initial results and known gaps are recorded in `baseline.json`. They do
**not** establish complete Remix support. Use the current JSON report or CI
artifact for the latest assessment.

## Regression gate and oracle maintenance

```sh
XDEBUG_MODE=off php bin/test-remix --check-baseline --json > tests/Remix/report.json
XDEBUG_MODE=off vendor/bin/phpunit tests/Popular/RemixCompatibilityTest.php
```

The baseline gate requires every previously passing probe to keep passing.
Known failures remain `fail` and unavailable Node oracles remain `blocked` in
the report, even when the regression gate exits 0. Improvements are accepted;
new regressions fail CI. The Popular Packages suite also includes this test
when the fixture is installed; without its npm dependencies it reports one
explicit skip. The dedicated `remix.yml` workflow always installs the fixture,
rechecks Node oracles and uploads the complete report and a job summary.

To update fixtures or package versions, regenerate `oracle.json` with
`npm run oracle`, inspect every changed result, rerun the diagnostic and review
`baseline.json` before updating its oracle hash and required passing probes.
All behavior probes must succeed in Node; a broken fixture is not classified
as a Phasis gap. `npm run oracle:check` detects package or fixture drift.

Phasis probes have a 256 MiB memory limit, a five-second execution budget and
a ten-second process timeout. The runner closes and reaps its current worker
on success, failure, timeout and CLI cancellation. Node oracle workers also
have a ten-second timeout and exit after each result. No test server is started.
