const { lowerCase, localeLowerCase } = LowerCase;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("simple", lowerCase("HELLO"));
log("camelCase", lowerCase("camelCase"));
log("PascalCase", lowerCase("PascalCase"));
log("snake_case", lowerCase("snake_case"));
log("kebab-case", lowerCase("kebab-case"));
log("dot.case", lowerCase("dot.case"));
log("mixed", lowerCase("foo_BAR-baz qux"));
log("number", lowerCase("HELLO123"));

console.log(out.join("\n"));
