// jsep — tiny JavaScript expression parser. Useful for embedding
// expressions in templates / DSLs.
const jsep = Jsep.default ?? Jsep;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("number", jsep("42"));
log("string", jsep('"hello"'));
log("binary", jsep("1 + 2"));
log("compare", jsep("a > b"));
log("member", jsep("obj.foo"));
log("call", jsep("Math.max(1, 2, 3)"));
log("array", jsep("[1, 2, 3]"));
log("conditional", jsep("a ? b : c"));
log("logical", jsep("a && b || c"));
log("unary", jsep("!x"));
log("precedence", jsep("1 + 2 * 3"));
log("paren", jsep("(1 + 2) * 3"));
log("member.deep", jsep("a.b.c.d"));

console.log(out.join("\n"));
