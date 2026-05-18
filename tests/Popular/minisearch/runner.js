const ms = new MiniSearch({
  fields: ["title", "body"],
  storeFields: ["title", "category"],
});

ms.addAll([
  { id: 1, title: "Phasis", body: "Pure PHP JavaScript engine", category: "tool" },
  { id: 2, title: "Lodash", body: "Modular JS utility library", category: "tool" },
  { id: 3, title: "Marked", body: "A markdown parser written in JS", category: "parser" },
  { id: 4, title: "Acorn", body: "Small JavaScript parser", category: "parser" },
  { id: 5, title: "Mustache", body: "Logic-less template engine", category: "template" },
]);

const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("exact", ms.search("Phasis").map(r => r.id).sort());
log("fuzzy", ms.search("javascrpt", { fuzzy: 0.3 }).map(r => r.id).sort());
log("prefix", ms.search("mark", { prefix: true }).map(r => r.id).sort());
log("body", ms.search("parser").map(r => r.id).sort());
log("combined", ms.search("library tool").map(r => r.id).sort());

// auto-suggest
const sugg = ms.autoSuggest("ma", { prefix: true });
log("suggest.count", sugg.length);
log("suggest.first", sugg.length > 0 ? sugg[0].suggestion : null);

// hasField / docCount
log("docCount", ms.documentCount);

console.log(out.join("\n"));
