const nearley = NearleyLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
// Build a tiny grammar by hand: matches simple addition like "1+2+3"
const grammar = {
  ParserRules: [
    { name: "main", symbols: ["num", "rest"], postprocess: (d) => d[0] + d[1] },
    { name: "rest", symbols: [] , postprocess: () => 0 },
    { name: "rest", symbols: [{literal:"+"}, "num", "rest"], postprocess: (d) => d[1] + d[2] },
    { name: "num", symbols: [/[0-9]/], postprocess: (d) => parseInt(d[0], 10) },
  ],
  ParserStart: "main",
};
const parser = new nearley.Parser(nearley.Grammar.fromCompiled(grammar));
parser.feed("1+2+3");
log("result", parser.results[0]);
log("count", parser.results.length);
console.log(out.join("\n"));
