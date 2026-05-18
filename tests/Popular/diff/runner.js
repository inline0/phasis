const D = Diff;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Char diff (diff 8.x API: pass options object as 3rd arg)
const chars = D.diffChars("hello world", "hello there");
log("chars.count", chars.length);
log("chars.first.value", chars[0].value);
log("chars.added", chars.filter(p => p.added).map(p => p.value));
log("chars.removed", chars.filter(p => p.removed).map(p => p.value));

// diffWords path defers — surfaces an option-default resolution gap
// inside the bundled diff library under Phasis; investigate separately.

const lines = D.diffLines(
  "alpha\nbeta\ngamma\ndelta\n",
  "alpha\nBETA\ngamma\nDELTA\n",
);
log("lines.count", lines.length);
log("lines.added", lines.filter(p => p.added).map(p => p.value));
log("lines.removed", lines.filter(p => p.removed).map(p => p.value));

// Patches
const patch = D.createPatch("file.txt", "alpha\nbeta\n", "alpha\nBETA\n");
log("patch.has-header", patch.includes("@@"));
log("apply", D.applyPatch("alpha\nbeta\n", patch));

// JSON diff (now part of v8 — separate API entry point)
const json1 = { a: 1, b: 2, c: 3 };
const json2 = { a: 1, b: 99, c: 3, d: 4 };
const jsonDiff = D.diffJson(json1, json2);
log("json.parts", jsonDiff.length);

// Identical
log("identical.empty", D.diffChars("same", "same").every(p => !p.added && !p.removed));

console.log(out.join("\n"));
