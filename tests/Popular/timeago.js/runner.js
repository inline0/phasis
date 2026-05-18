const ta = TimeAgo;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Anchor "now" at a fixed timestamp so output is deterministic.
const NOW = new Date("2024-06-15T12:00:00Z").getTime();

const cases = [
  { label: "5s ago",     ms: NOW - 5 * 1000 },
  { label: "30s ago",    ms: NOW - 30 * 1000 },
  { label: "1m ago",     ms: NOW - 60 * 1000 },
  { label: "5m ago",     ms: NOW - 5 * 60 * 1000 },
  { label: "1h ago",     ms: NOW - 60 * 60 * 1000 },
  { label: "5h ago",     ms: NOW - 5 * 60 * 60 * 1000 },
  { label: "1d ago",     ms: NOW - 24 * 60 * 60 * 1000 },
  { label: "7d ago",     ms: NOW - 7 * 24 * 60 * 60 * 1000 },
  { label: "30d ago",    ms: NOW - 30 * 24 * 60 * 60 * 1000 },
  { label: "365d ago",   ms: NOW - 365 * 24 * 60 * 60 * 1000 },
  { label: "30s future", ms: NOW + 30 * 1000 },
  { label: "5m future",  ms: NOW + 5 * 60 * 1000 },
];

for (const c of cases) {
  log(c.label, ta.format(c.ms, "en", { relativeDate: NOW }));
}

console.log(out.join("\n"));
