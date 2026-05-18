const { dotCase } = DotCase;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("camelCase", dotCase("camelCase"));
log("PascalCase", dotCase("PascalCase"));
log("snake_case", dotCase("snake_case"));
log("kebab-case", dotCase("kebab-case"));
log("spaces", dotCase("hello world"));
log("mixed", dotCase("FooBAR baz_qux"));
log("number", dotCase("XML2Json"));

console.log(out.join("\n"));
