const P = Parsimmon;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Basic parsers
log("digit", P.digit.parse("5"));
log("digits", P.digits.parse("42"));
log("string", P.string("hello").parse("hello"));
log("regex", P.regexp(/[a-z]+/).parse("foo123"));

// Combinators
const word = P.regexp(/[a-z]+/);
log("seq", P.seq(word, P.string(":"), word).parse("foo:bar"));
log("alt", P.alt(P.string("yes"), P.string("no")).parse("yes"));
log("many", word.sepBy(P.string(",")).parse("a,b,c"));

// Map
const num = P.regexp(/\d+/).map(Number);
log("map", num.parse("42"));

// Lazy / recursive parser — arithmetic
const expr = P.lazy(() => P.alt(
  P.seq(num, P.string("+"), expr).map(([a, _, b]) => a + b),
  num,
));
log("recurse.1", expr.parse("1+2+3"));

console.log(out.join("\n"));
