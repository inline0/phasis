// marky uses performance.now() — but timings vary so we check
// structural invariants only.
const m = Marky;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

m.mark("op");
for (let i = 0; i < 1000; i++) { /* spin */ }
m.stop("op");

const entries = m.getEntries();
log("count", entries.length);
log("hasName", entries.some(e => e.name === "op"));
log("hasDuration", entries[0] && typeof entries[0].duration === "number");
log("durationNonNeg", entries[0] && entries[0].duration >= 0);

m.mark("op2");
m.stop("op2");
log("count2", m.getEntries().length);

m.clear();
log("cleared", m.getEntries().length);

console.log(out.join("\n"));
