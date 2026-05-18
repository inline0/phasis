const J = Jmespath;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const data = {
  users: [
    { id: 1, name: "alice", role: "admin", age: 30 },
    { id: 2, name: "bob",   role: "user",  age: 25 },
    { id: 3, name: "carol", role: "user",  age: 35 },
  ],
  config: { debug: true, mode: "production", levels: [1, 2, 3] },
};

log("simple", J.search(data, "users[0].name"));
log("pluck", J.search(data, "users[*].name"));
log("nested", J.search(data, "config.levels[1]"));
log("filter", J.search(data, "users[?role == 'admin'].name"));
log("filter.numeric", J.search(data, "users[?age > `28`].name"));
log("multiselect.list", J.search(data, "users[0].[id, name, role]"));
log("multiselect.hash", J.search(data, "users[0].{ident: id, who: name}"));
log("flatten", J.search(data, "users[*].name | [0]"));
log("length", J.search(data, "length(users)"));
log("sort", J.search(data, "users | sort_by(@, &age)[*].name"));
log("max_by", J.search(data, "max_by(users, &age).name"));
log("min_by", J.search(data, "min_by(users, &age).name"));
log("contains", J.search(data, "contains(users[*].name, 'bob')"));

console.log(out.join("\n"));
