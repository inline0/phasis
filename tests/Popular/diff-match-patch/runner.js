const dmp = new DMP();
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// diff_main: returns array of [op, text] pairs. op = -1 (DELETE), 0 (EQUAL), 1 (INSERT)
const diffs = dmp.diff_main("hello world", "hello there world");
dmp.diff_cleanupSemantic(diffs);
log("diff", diffs);

// patch_make + patch_apply roundtrip
const patches = dmp.patch_make("hello world", "hello there world");
log("patch.toText.contains", dmp.patch_toText(patches).includes("@@"));
const [applied, results] = dmp.patch_apply(patches, "hello world");
log("patch.applied", applied);
log("patch.allOk", results.every(r => r === true));

// patch_apply on different starting string still attempts fuzzy match
const [fuzzy, fuzzyResults] = dmp.patch_apply(patches, "hello world!");
log("fuzzy.applied", fuzzy);

// match_main — locate substring
log("match", dmp.match_main("the quick brown fox", "quick", 0));

// diff_levenshtein
log("levenshtein.equal", dmp.diff_levenshtein(dmp.diff_main("same", "same")));
log("levenshtein.short", dmp.diff_levenshtein(dmp.diff_main("kitten", "sitting")));

// diff_prettyHtml (just check it's a string starting with span)
const html = dmp.diff_prettyHtml(dmp.diff_main("foo", "bar"));
log("prettyHtml.startsWithSpan", html.startsWith("<span") || html.startsWith("<del") || html.startsWith("<ins"));

console.log(out.join("\n"));
