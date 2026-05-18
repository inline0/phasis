const s = Semver;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("valid.ok", s.valid("1.2.3"));
log("valid.pre", s.valid("1.2.3-alpha.1"));
log("valid.bad", s.valid("not.a.version"));
log("parse", { major: s.major("1.2.3"), minor: s.minor("1.2.3"), patch: s.patch("1.2.3") });
log("inc.major", s.inc("1.2.3", "major"));
log("inc.minor", s.inc("1.2.3", "minor"));
log("inc.patch", s.inc("1.2.3", "patch"));
log("inc.prerelease", s.inc("1.2.3", "prerelease", "alpha"));
log("compare.lt", s.compare("1.2.3", "1.2.4"));
log("compare.eq", s.compare("1.2.3", "1.2.3"));
log("compare.gt", s.compare("2.0.0", "1.9.9"));
log("satisfies.caret", s.satisfies("1.2.5", "^1.2.0"));
log("satisfies.tilde", s.satisfies("1.2.5", "~1.2.0"));
log("satisfies.range", s.satisfies("1.5.0", ">=1.0.0 <2.0.0"));
log("gt", s.gt("2.0.0", "1.9.9"));
log("lt", s.lt("1.9.9", "2.0.0"));
log("diff", s.diff("1.2.3", "2.0.0"));
log("coerce", s.coerce("v1.2").version);
log("clean", s.clean(" v1.2.3 "));
log("maxSat", s.maxSatisfying(["1.0.0", "1.5.0", "2.0.0", "1.2.5"], "^1.0.0"));

console.log(out.join("\n"));
