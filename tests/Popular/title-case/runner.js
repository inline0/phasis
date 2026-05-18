const { titleCase } = TitleCase;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("hello world", titleCase("hello world"));
log("the quick brown fox", titleCase("the quick brown fox"));
log("camelCase", titleCase("camelCase"));
log("snake_case", titleCase("snake_case"));
log("from a single word", titleCase("from"));

console.log(out.join("\n"));
