const p = Pluralize;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const words = ["apple", "child", "person", "octopus", "criterion", "data", "sheep", "mouse", "foot", "tooth", "leaf", "potato"];

for (const w of words) {
  log(w + ".plural", p.plural(w));
  log(w + ".singular", p.singular(p.plural(w)));
}

// pluralize() with count
log("1.apple", p("apple", 1));
log("2.apple", p("apple", 2));
log("0.apple", p("apple", 0));
log("1.apple.inclusive", p("apple", 1, true));
log("5.child.inclusive", p("child", 5, true));

console.log(out.join("\n"));
