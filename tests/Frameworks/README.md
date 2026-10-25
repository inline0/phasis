# Local-only framework compatibility

**Never run these suites in CI.** This includes Express, Hono and the associated
HTTP/2 compatibility checks, through automatic triggers, manual dispatches,
release gates or workflows from old revisions.

The owner made this decision on 2026-10-25. The Framework Compatibility workflow
is removed from this repository and disabled in GitHub Actions. Do not restore
it, dispatch it, or add these runners to another workflow. A cancelled or absent
framework check does not block merging or releasing.

Keep the runners for deliberate local investigation. Preserve original
assertions, source identities, upstream skips, original/effective deadlines and
owned-process cleanup. A local timeout scale is a functional compatibility
check, not a performance-parity claim. The main README and Hono guide document
the local commands.

Engine unit tests, oracle regressions, JSX/hydration, WPT, popular packages,
test262 and dependency/quality checks retain their existing CI roles.
