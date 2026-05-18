const { snakeCase } = SnakeCase;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("camelCase", snakeCase("camelCase"));
log("PascalCase", snakeCase("PascalCase"));
log("kebab-case", snakeCase("kebab-case"));
log("space-sep", snakeCase("hello world"));
log("dot.case", snakeCase("dot.case"));
log("mixed", snakeCase("foo_BAR-baz qux"));
log("number", snakeCase("foo 1 bar 2"));

console.log(out.join("\n"));
