const { pascalCase } = PascalCase;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("camelCase", pascalCase("camelCase"));
log("snake_case", pascalCase("snake_case"));
log("kebab-case", pascalCase("kebab-case"));
log("space-sep", pascalCase("hello world"));
log("dot.case", pascalCase("dot.case"));
log("mixed", pascalCase("foo_BAR-baz qux"));
log("number", pascalCase("foo 1 bar 2"));

console.log(out.join("\n"));
