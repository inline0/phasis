const { distance, closest } = Lev;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("identical", distance("hello", "hello"));
log("one-edit", distance("kitten", "kittens"));
log("classic", distance("kitten", "sitting"));
log("empty.left", distance("", "abc"));
log("empty.right", distance("abc", ""));
log("both.empty", distance("", ""));
log("unicode", distance("café", "cafe"));
log("long", distance("the quick brown fox", "the slow brown cat"));
log("case-sensitive", distance("ABC", "abc"));

log("closest.exact", closest("blue", ["red", "blue", "green"]));
log("closest.typo", closest("bluw", ["red", "blue", "green"]));
log("closest.numbers", closest("five", ["four", "five", "six"]));
log("closest.array", closest("longg", ["short", "medium", "long"]));

console.log(out.join("\n"));
