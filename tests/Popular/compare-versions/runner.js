const { compare, compareVersions, satisfies, validate } = CompareVersions;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("compare.lt", compareVersions("1.2.3", "1.2.4"));
log("compare.eq", compareVersions("1.2.3", "1.2.3"));
log("compare.gt", compareVersions("2.0.0", "1.9.9"));
log("compare.pre", compareVersions("1.0.0-alpha", "1.0.0"));

log("compare.fn.lt", compare("1.0.0", "2.0.0", "<"));
log("compare.fn.eq", compare("1.0.0", "1.0.0", "="));
log("compare.fn.ge", compare("2.0.0", "1.0.0", ">="));

log("validate.ok", validate("1.2.3"));
log("validate.bad", validate("not.a.version"));

log("satisfies.tilde", satisfies("1.2.5", "~1.2.0"));
log("satisfies.caret", satisfies("1.2.5", "^1.2.0"));
log("satisfies.range", satisfies("1.5.0", ">=1.0.0 <2.0.0"));

console.log(out.join("\n"));
