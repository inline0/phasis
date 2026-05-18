const mdt = MdTable.markdownTable;
const out = [];

out.push(mdt([
  ["Name", "Score"],
  ["Alice", "95"],
  ["Bob", "82"],
  ["Carol", "100"],
]));

out.push("---");

out.push(mdt([
  ["A", "B", "C"],
  ["x", "y", "z"],
  ["1", "2", "3"],
], { align: ["l", "c", "r"] }));

out.push("---");

out.push(mdt([
  ["Long header column", "Short"],
  ["1", "a"],
  ["a really really long cell", "ok"],
]));

console.log(out.join("\n"));
