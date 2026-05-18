const { upperCase, localeUpperCase } = UpperCase;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("simple", upperCase("hello"));
log("camelCase", upperCase("camelCase"));
log("snake_case", upperCase("snake_case"));
log("kebab-case", upperCase("kebab-case"));
log("dot.case", upperCase("dot.case"));
log("mixed", upperCase("foo_BAR-baz qux"));

console.log(out.join("\n"));
