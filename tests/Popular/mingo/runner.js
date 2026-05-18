const { Query, Aggregator } = MingoLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
const data = [
  { _id: 1, name: "Alice", age: 30, role: "admin" },
  { _id: 2, name: "Bob", age: 25, role: "user" },
  { _id: 3, name: "Carol", age: 35, role: "admin" },
  { _id: 4, name: "Dave", age: 28, role: "user" },
];
const adults = new Query({ age: { $gte: 30 } });
log("adults", data.filter(d => adults.test(d)).map(d => d.name));
const admins = new Query({ role: "admin" });
log("admins", data.filter(d => admins.test(d)).map(d => d.name));
const complex = new Query({ $and: [{ age: { $gte: 25 } }, { role: "user" }] });
log("complex", data.filter(d => complex.test(d)).map(d => d.name));
const agg = new Aggregator([
  { $match: { age: { $gte: 25 } } },
  { $group: { _id: "$role", count: { $sum: 1 }, totalAge: { $sum: "$age" } } },
  { $sort: { _id: 1 } },
]);
log("agg", agg.run(data));
console.log(out.join("\n"));
