import { defineConfig } from "onedocs/config";
import {
  Box,
  CheckCircle2,
  Cloud,
  Cpu,
  GitCompare,
  Globe,
  Layers,
  Package,
  ShieldCheck,
  Terminal,
} from "lucide-react";

const iconClass = "h-5 w-5 text-fd-primary";

export default defineConfig({
  title: "Phasis",
  description:
    "Pure PHP JavaScript engine. Full ES2024+ at 100% test262, plus the Web Platform Pack (URL, encoding, structuredClone) and Fetch Pack (fetch, Streams, AbortSignal, Blob, FormData) at 100% WPT.",
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
          "No exec, no FFI. Runs anywhere PHP 8.2+ runs.",
        icon: <Package className={iconClass} />,
      },
      {
        title: "100% test262",
        description:
          "All 50,506 official ECMAScript conformance tests pass.",
        icon: <CheckCircle2 className={iconClass} />,
      },
      {
        title: "Modern ECMAScript",
        description:
          "Classes, async/await, generators, Symbol, BigInt, Proxy, Temporal, Intl, TypedArray. Full ES2024+.",
        icon: <Layers className={iconClass} />,
      },
      {
        title: "Web Platform APIs",
        description:
          "URL, TextEncoder/Decoder, atob/btoa, structuredClone, performance, DOMException — built-in.",
        icon: <Globe className={iconClass} />,
      },
      {
        title: "fetch + Streams",
        description:
          "Real HTTP from JS via ext-curl. Full Fetch API with AbortSignal, Blob, FormData, and WHATWG Streams.",
        icon: <Cloud className={iconClass} />,
      },
      {
        title: "100% WPT",
        description:
          "Web Platform Tests for fetch, headers, blob, abort, streams, encoding — every imported subtest passes.",
        icon: <CheckCircle2 className={iconClass} />,
      },
      {
        title: "PHP ↔ JS Interop",
        description:
          "Share objects without serialization. Bind PHP closures as JS functions. Zero-copy bridging.",
        icon: <GitCompare className={iconClass} />,
      },
      {
        title: "Embeddable",
        description:
          "One Engine class: eval, execFile, setGlobal, call. Drop into any PHP app or CLI.",
        icon: <Box className={iconClass} />,
      },
      {
        title: "Sandboxable",
        description:
          "Resource limits for depth, loops, strings, output, wall clock. The host controls everything.",
        icon: <ShieldCheck className={iconClass} />,
      },
      {
        title: "CLI Toolkit",
        description:
          "bin/phasis runs scripts and ships a REPL. bin/test262 and bin/wpt measure conformance.",
        icon: <Terminal className={iconClass} />,
      },
      {
        title: "Oracle-Tested",
        description:
          "Every change verified against Node.js (V8). 73-shard CI matrix on every push.",
        icon: <Cpu className={iconClass} />,
      },
    ],
  },
});
