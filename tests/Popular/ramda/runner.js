// Ramda esbuild bundle exposes the namespace as `Ramda`.
const R = Ramda;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const data = [
  { id: 1, name: "alice", age: 30, role: "admin" },
  { id: 2, name: "bob",   age: 25, role: "user"  },
  { id: 3, name: "carol", age: 35, role: "user"  },
  { id: 4, name: "dan",   age: 28, role: "admin" },
  { id: 5, name: "eve",   age: 22, role: "user"  },
];

// Currying
const add3 = R.add(3);
log("curry.add3.5", add3(5));
log("curry.add3.10", add3(10));

// Pipe / compose
const oldest = R.pipe(R.sortBy(R.prop("age")), R.last, R.prop("name"));
log("pipe.oldest", oldest(data));

// Map / filter / reduce
log("map.sqr", R.map(x => x * x, [1, 2, 3, 4]));
log("filter.even", R.filter(x => x % 2 === 0, [1, 2, 3, 4, 5, 6]));
log("reduce.sum", R.reduce((a, b) => a + b, 0, [1, 2, 3, 4, 5]));
log("pluck.name", R.pluck("name", data));

// Predicates
log("any.admin", R.any(R.propEq("admin", "role"), data));
log("all.adult", R.all(u => u.age >= 22, data));

// Object helpers
log("pick", R.pick(["id", "name"], data[0]));
log("omit", R.omit(["age", "role"], data[0]));
log("path", R.path(["meta", "user"], { meta: { user: "alice" } }));

// Lenses
const nameLens = R.lensProp("name");
log("lens.view", R.view(nameLens, data[0]));
log("lens.over", R.over(nameLens, R.toUpper, data[0]));

// groupBy
log("groupBy.role", R.mapObjIndexed(R.pluck("name"), R.groupBy(R.prop("role"), data)));

// Strings
log("split", R.split(",", "a,b,c"));
log("toUpper", R.toUpper("ramda"));
log("trim", R.trim("  hi  "));

// Math
log("sum", R.sum([1, 2, 3, 4, 5]));
log("mean", R.mean([2, 4, 6, 8]));
log("multiply.curried", R.multiply(3)(4));

console.log(out.join("\n"));
