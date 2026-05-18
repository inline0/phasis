// Runner: slugify a variety of strings with default + custom options.

const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("basic", Slugify("Hello World"));
log("punct", Slugify("Some Cool Title!"));
log("unicode", Slugify("café & résumé"));
log("german", Slugify("ÄÖÜß"));
log("emoji", Slugify("I love 🍕 pizza"));
log("multi-space", Slugify("a    b\t\tc"));
log("kebab", Slugify("CamelCaseName"));
log("explicit-lower", Slugify("MIXED Case Title", { lower: true }));
log("strict", Slugify("file_name@v2.txt", { strict: true }));
log("custom-replace", Slugify("hello world", { replacement: "_" }));
log("trim", Slugify("   leading & trailing   "));
log("empty", Slugify(""));

console.log(out.join("\n"));
