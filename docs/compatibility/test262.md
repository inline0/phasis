---
title: "test262 coverage"
description: "Phasis passes the official ECMAScript conformance suite at 100% — every test, every category, no skips."
path: "compatibility/test262"
order: 100
section: "Compatibility"
meta_title: "test262 coverage"
meta_description: "Phasis passes the official ECMAScript conformance suite at 100% — every test, every category, no skips."
---

# test262 coverage

[test262](https://github.com/tc39/test262) is the official conformance test suite maintained by TC39 and the canonical way to compare JavaScript engines.

Phasis passes **the full suite at 100 %** across every category — language semantics, the standard library, `Intl`, `annexB` legacy web features, the `staging` directory of incubating tests, and the harness self-tests. No feature-flag bypasses, no host-gap blocklist entries, no per-shard exclusions. The full-suite snapshot is regenerated and committed to [`COMPAT.md`](https://github.com/inline0/phasis/blob/main/COMPAT.md) on every CI run.

For comparison, [test262.fyi](https://test262.fyi) tracks the same suite against every production JS engine:

| Engine | Compliance |
|---|---:|
| V8 (Chrome / Node) | 99.8 % |
| SpiderMonkey (Firefox) | 99.6 % |
| JavaScriptCore (Safari) | 99.4 % |
| QuickJS | ~97 % |
| Hermes (React Native) | ~95 % |
| **Phasis** | **100 %** |

Those engines each carry a small residual gap (newly-landed proposals, rare edge cases under construction). Phasis closes the gap by virtue of being a single-purpose conformance-first implementation.

## Running it locally

```bash
./vendor/bin/test262 --jobs 4
```

A full run on a recent Mac takes around 20 minutes; the CI matrix shards in parallel and finishes in single-digit minutes.

To run just one category while debugging:

```bash
./vendor/bin/test262 --category built-ins/Array
./vendor/bin/test262 --category language/expressions/yield --failures
```

See [the CLI reference](/docs/cli#bin-test262) for the full flag set.

## Why this matters

JavaScript has subtle semantics — `NaN !== NaN`, `-0 === 0`, `[1] + 1 === "11"`, the exact order of property enumeration, the timing of Promise microtasks. A library that runs JS user code needs every one of those right, every time. test262 is the bar for "right"; passing it at 100 % means a JS program that runs on V8 produces byte-identical output on Phasis (modulo browser-only APIs).
