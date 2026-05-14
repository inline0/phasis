// Runner: exercise a broad slice of lodash's API and emit each result
// on its own line. The oracle (oracle.txt) is the output of running
// this exact runner under Node.js. CI runs it under Phasis and
// byte-compares the output.

const data = [
  { id: 1, name: "alice", age: 30, role: "admin", tags: ["a", "b"] },
  { id: 2, name: "bob", age: 25, role: "user", tags: ["b", "c"] },
  { id: 3, name: "carol", age: 35, role: "user", tags: ["a", "c"] },
  { id: 4, name: "dan", age: 28, role: "admin", tags: ["d"] },
  { id: 5, name: "eve", age: 22, role: "user", tags: ["a", "d"] },
];

const out = [];
const log = (label, value) => out.push(label + " " + JSON.stringify(value));

// Array helpers
log("map.name", _.map(data, "name"));
log("filter.role", _.map(_.filter(data, { role: "admin" }), "name"));
log("reject.role", _.map(_.reject(data, { role: "admin" }), "name"));
log("reduce.sumAge", _.reduce(data, (acc, x) => acc + x.age, 0));
log("chunk", _.chunk([1, 2, 3, 4, 5, 6, 7], 3));
log("flatten", _.flatten([1, [2, [3, [4]], 5]]));
log("flattenDeep", _.flattenDeep([1, [2, [3, [4]], 5]]));
log("uniq", _.uniq([1, 2, 2, 3, 1, 4, 3]));
log("uniqBy.length", _.uniqBy(["a", "bb", "ccc", "dd", "e"], (s) => s.length));
log("zip", _.zip([1, 2, 3], ["a", "b", "c"], [true, false, true]));
log("unzip", _.unzip([[1, "a", true], [2, "b", false], [3, "c", true]]));
log("difference", _.difference([1, 2, 3, 4, 5], [2, 4]));
log("intersection", _.intersection([1, 2, 3, 4], [2, 3, 5], [3, 4]));
log("union", _.union([1, 2, 3], [2, 3, 4], [3, 4, 5]));
log("compact", _.compact([0, 1, false, 2, "", 3, null, undefined, NaN]));
log("drop", _.drop([1, 2, 3, 4, 5], 2));
log("take", _.take([1, 2, 3, 4, 5], 2));

// Collection helpers
log("sortBy.age", _.map(_.sortBy(data, "age"), "name"));
log("orderBy.role,age", _.map(_.orderBy(data, ["role", "age"], ["asc", "desc"]), "name"));
log("groupBy.role", _.mapValues(_.groupBy(data, "role"), (g) => _.map(g, "name")));
log("keyBy.id", _.mapValues(_.keyBy(data, "id"), "name"));
log("countBy.role", _.countBy(data, "role"));
log("partition.adult", (() => {
  const [a, b] = _.partition(data, (x) => x.age >= 28);
  return { adult: _.map(a, "name"), young: _.map(b, "name") };
})());

// Object helpers
log("pick", _.pick(data[0], ["id", "name"]));
log("omit", _.omit(data[0], ["tags", "role"]));
log("get.nested", _.get({ a: { b: { c: 42 } } }, "a.b.c"));
log("get.missing", _.get({ a: { b: { c: 42 } } }, "a.b.x", "default"));
log("set.nested", (() => {
  const o = { a: { b: 1 } };
  _.set(o, "a.c.d", 99);
  return o;
})());
log("merge.deep", _.merge({}, { a: { b: 1, c: 2 } }, { a: { c: 3, d: 4 } }));
log("defaults", _.defaults({ a: 1 }, { a: 9, b: 2, c: 3 }));
log("invert", _.invert({ a: 1, b: 2, c: 3 }));

// Lang helpers
log("isEqual.deep", _.isEqual({ a: [1, { b: 2 }] }, { a: [1, { b: 2 }] }));
log("isEqual.notEqual", _.isEqual({ a: 1 }, { a: 2 }));
log("cloneDeep", (() => {
  const orig = { a: { b: { c: [1, 2, 3] } } };
  const copy = _.cloneDeep(orig);
  copy.a.b.c.push(4);
  return { origLen: orig.a.b.c.length, copyLen: copy.a.b.c.length };
})());
log("isMatch", _.isMatch({ a: 1, b: 2, c: 3 }, { b: 2 }));
log("isPlainObject", [_.isPlainObject({}), _.isPlainObject([]), _.isPlainObject(null)]);

// String helpers
log("camelCase", _.camelCase("Hello world-foo_bar"));
log("kebabCase", _.kebabCase("helloWorldFooBar"));
log("snakeCase", _.snakeCase("helloWorldFooBar"));
log("startCase", _.startCase("hello world"));
log("upperFirst", _.upperFirst("hello"));
log("words", _.words("Hello world-foo_bar baz"));
log("escape", _.escape("<script>alert('xss')</script>"));
log("repeat", _.repeat("ab", 4));
log("pad", _.pad("hi", 8, "-"));
log("truncate", _.truncate("The quick brown fox jumps", { length: 16 }));

// Math helpers
log("sum", _.sum([1, 2, 3, 4, 5]));
log("sumBy.age", _.sumBy(data, "age"));
log("max", _.max([3, 1, 4, 1, 5, 9, 2, 6]));
log("maxBy.age", _.maxBy(data, "age").name);
log("min", _.min([3, 1, 4, 1, 5, 9, 2, 6]));
log("mean", _.mean([1, 2, 3, 4, 5]));
log("meanBy.age", _.meanBy(data, "age"));
log("range", _.range(0, 10, 2));
log("times", _.times(5, (i) => i * i));

// Function helpers (synchronous-safe only)
log("memoize", (() => {
  let calls = 0;
  const square = _.memoize((n) => {
    calls++;
    return n * n;
  });
  square(4); square(4); square(5); square(4);
  return { result4: square(4), result5: square(5), calls };
})());
log("once", (() => {
  let calls = 0;
  const init = _.once(() => ++calls);
  init(); init(); init();
  return calls;
})());

// Chain
log("chain.compute", _
  .chain(data)
  .filter({ role: "user" })
  .sortBy("age")
  .map((x) => x.name + ":" + x.age)
  .value());

console.log(out.join("\n"));
