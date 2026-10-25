import { resolve } from "node:path";
import { main, script } from "./lib/cli.ts";
import { runChecks } from "./lib/suites.ts";

export const checks = [
  { label: "Tooling types", command: "pnpm", args: ["typecheck"] },
  { label: "Tooling tests", command: "pnpm", args: ["test:scripts"] },
  { label: "Documentation", command: "pnpm", args: ["check:docs"] },
  {
    label: "PHPStan",
    command: "php",
    args: ["-d", "memory_limit=4G", "vendor/bin/phpstan", "analyse"],
  },
  {
    label: "PHPCS",
    command: "php",
    args: [
      "-d",
      "memory_limit=512M",
      "vendor/bin/phpcs",
      "--warning-severity=0",
    ],
  },
  {
    label: "PHPUnit",
    command: "php",
    args: ["-d", "memory_limit=512M", "vendor/bin/phpunit"],
  },
  {
    label: "Oracle regression",
    command: process.execPath,
    args: ["scripts/test-regression.ts", "--fast"],
  },
];
export const command = script("verify-all", {
  description: "Run every local quality gate, collecting all failures.",
  flags: {},
  run: (input) => runChecks(checks, resolve(import.meta.dirname, ".."), input),
});
if (import.meta.main) main(command);
