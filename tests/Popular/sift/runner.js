const sift = SiftLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
const data = [
  { name: "A", value: 1, tags: ["x", "y"] },
  { name: "B", value: 2, tags: ["y", "z"] },
  { name: "C", value: 3, tags: ["x", "z"] },
];
log("gt", data.filter(sift({ value: { $gt: 1 } })).map(d => d.name));
log("in", data.filter(sift({ tags: { $in: ["x"] } })).map(d => d.name));
log("and", data.filter(sift({ $and: [{ value: { $gte: 1 } }, { tags: "z" }] })).map(d => d.name));
log("regex", data.filter(sift({ name: { $regex: /^[AB]$/ } })).map(d => d.name));
console.log(out.join("\n"));
