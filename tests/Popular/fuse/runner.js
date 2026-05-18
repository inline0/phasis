// Runner: exercise Fuse.js fuzzy search across a small library catalog.
// Covers exact / fuzzy / nested-key / weighted / threshold-controlled
// search, plus array-tag indexing. Babel-transpiled UMD that needs both
// `_classCallCheck`-style identity checks (fixed by the FunctionDeclaration
// env-sync commit) and proper hoisting of `var f` declared inside an
// `else if` body (which was clobbering the IIFE's `function f` spread
// helper — fixed by the forceHoistVarNames if-alternate recursion).
//
// Fuse.js attaches as the global `Fuse` (UMD).

const books = [
  {
    title: "The Pragmatic Programmer",
    author: { first: "Andy", last: "Hunt" },
    tags: ["software", "career"],
  },
  {
    title: "Code Complete",
    author: { first: "Steve", last: "McConnell" },
    tags: ["software", "craftsmanship"],
  },
  {
    title: "Clean Code",
    author: { first: "Robert", last: "Martin" },
    tags: ["software", "best-practices"],
  },
  {
    title: "Refactoring",
    author: { first: "Martin", last: "Fowler" },
    tags: ["software", "design"],
  },
  {
    title: "Design Patterns",
    author: { first: "Erich", last: "Gamma" },
    tags: ["software", "design", "patterns"],
  },
  {
    title: "The Mythical Man-Month",
    author: { first: "Fred", last: "Brooks" },
    tags: ["software", "management"],
  },
  {
    title: "Domain-Driven Design",
    author: { first: "Eric", last: "Evans" },
    tags: ["software", "design", "ddd"],
  },
];

const out = [];
const log = (label, value) => out.push(label + " " + JSON.stringify(value));

const f1 = new Fuse(books, { keys: ["title"], threshold: 0.3 });
log("exact.title", f1.search("Clean Code").map((r) => r.item.title));
log("fuzzy.title.typo", f1.search("Refacotring").map((r) => r.item.title));

const f2 = new Fuse(books, {
  keys: [
    { name: "title", weight: 0.7 },
    { name: "author.last", weight: 0.3 },
  ],
  threshold: 0.4,
});
log("weighted.martin", f2.search("Martin").map((r) => r.item.title));

const f3 = new Fuse(books, { keys: ["author.first"], threshold: 0.3 });
log("nested.first.steve", f3.search("Steve").map((r) => r.item.author.first));

const f4 = new Fuse(books, { keys: ["tags"], threshold: 0.2 });
log("tags.design", f4.search("design").map((r) => r.item.title));

const f7Tight = new Fuse(books, { keys: ["title"], threshold: 0.0 });
log("tight.0.exact", f7Tight.search("Code Complete").map((r) => r.item.title));
log("tight.0.fuzzy.empty", f7Tight.search("Code Cmplete").map((r) => r.item.title));

console.log(out.join("\n"));
