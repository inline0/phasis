import { defineConfig } from "onedocs/config";
import {
  Box,
  CheckCircle2,
  Cpu,
  GitCompare,
  Layers,
  Package,
  ShieldCheck,
  Terminal,
} from "lucide-react";

const iconClass = "h-5 w-5 text-fd-primary";

export default defineConfig({
  title: "Phasis",
  description:
    "Pure PHP JavaScript engine. Lexes, parses, and executes ECMAScript in pure PHP — no Node.js, no FFI, no extensions beyond mbstring.",
  logo: {
    light: "/logo-light.svg",
    dark: "/logo-dark.svg",
  },
  icon: { light: "/icon.png", dark: "/icon-dark.png" },
  nav: {
    github: "inline0/phasis",
  },
  footer: {
    links: [{ label: "Inline0.com", href: "https://inline0.com" }],
  },
  homepage: {
    features: [
      {
        title: "Pure PHP",
        description:
          "No exec(), no FFI, no extensions beyond ext-mbstring. Runs anywhere PHP 8.2+ runs, including hardened hosting and shared environments.",
        icon: <Package className={iconClass} />,
      },
      {
        title: "100% test262",
        description:
          "Every one of the 50,506 official ECMAScript conformance tests passes. Zero failures, zero skips across the full ES2024+ language surface and standard library.",
        icon: <CheckCircle2 className={iconClass} />,
      },
      {
        title: "Modern ECMAScript",
        description:
          "Arrow functions, classes, async/await, Promises, generators, Symbol, BigInt, Proxy, Reflect, Temporal, Intl, TypedArray — full ES2024+ surface.",
        icon: <Layers className={iconClass} />,
      },
      {
        title: "Direct PHP↔JS Interop",
        description:
          "Share PHP objects with JS without serialization. Bind PHP closures as JS functions; mutate PHP arrays from JS. Zero-copy bridging.",
        icon: <GitCompare className={iconClass} />,
      },
      {
        title: "Embeddable Engine",
        description:
          "One Engine class: eval(), execFile(), setGlobal(), call(). Drop a JS runtime into any PHP app, framework, or CLI tool.",
        icon: <Box className={iconClass} />,
      },
      {
        title: "Sandboxable",
        description:
          "Resource limits for call depth, loop iterations, string length, console output, and wall-clock execution. The host controls the runtime.",
        icon: <ShieldCheck className={iconClass} />,
      },
      {
        title: "CLI Toolkit",
        description:
          "bin/phasis runs files, evaluates expressions, and ships a REPL. bin/test262 measures spec compliance against the official suite.",
        icon: <Terminal className={iconClass} />,
      },
      {
        title: "Oracle-Tested",
        description:
          "Every change is verified against Node.js (V8). Compliance is measured continuously against the test262 suite in a 73-shard CI matrix.",
        icon: <Cpu className={iconClass} />,
      },
    ],
  },
});
