// lunr — full-text search engine. UMD self-registers `lunr` globally.
// Builds an in-memory inverted index with tokenization, stemming, and
// inverse-document-frequency scoring.
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const docs = [
  {
    id: "1",
    title: "Phasis",
    body: "A pure-PHP JavaScript engine implementing the ECMAScript spec",
    tags: "php javascript engine",
  },
  {
    id: "2",
    title: "Acorn parser",
    body: "A small, fast, JavaScript-based JavaScript parser",
    tags: "javascript parsing ast",
  },
  {
    id: "3",
    title: "Lodash utilities",
    body: "Modern JavaScript utility library for working with arrays and objects",
    tags: "javascript utility lodash",
  },
  {
    id: "4",
    title: "Mustache templating",
    body: "Logic-less templates with a tiny rendering engine",
    tags: "templating",
  },
];

const idx = lunr(function () {
  this.ref("id");
  this.field("title", { boost: 10 });
  this.field("body");
  this.field("tags");
  docs.forEach((doc) => this.add(doc));
});

function search(q) {
  return idx.search(q).map((r) => ({ ref: r.ref, score: Math.round(r.score * 1000) / 1000 }));
}

log("simple.javascript", search("javascript"));
log("simple.engine", search("engine"));
log("simple.parser", search("parser"));

// Phrase / required
log("required", search("+javascript +utility"));

// Wildcard
log("wildcard", search("temp*"));

// Boost via field weight (title field gets *10)
log("titleBoost", search("Phasis Lodash"));

// Empty result
log("nomatch", search("kotlin"));

// Quoted phrases not supported in basic lunr; use multi-term instead
log("multi", search("modern javascript utility"));

console.log(out.join("\n"));
