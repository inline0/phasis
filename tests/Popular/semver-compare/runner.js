const cmp = SemverCompare;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("lt", cmp("1.2.3", "1.2.4"));
log("eq", cmp("1.2.3", "1.2.3"));
log("gt", cmp("2.0.0", "1.9.9"));
log("minor", cmp("1.10.0", "1.9.0"));
log("patch", cmp("1.2.10", "1.2.9"));
log("prerelease", cmp("1.0.0-alpha", "1.0.0"));

const versions = ["1.10.0", "1.2.0", "2.0.0", "1.2.10", "0.9.0"];
log("sort", versions.slice().sort(cmp));

console.log(out.join("\n"));
