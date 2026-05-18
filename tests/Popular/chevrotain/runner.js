const { Lexer, createToken, CstParser } = Chevrotain;

const Number_ = createToken({ name: "Number", pattern: /\d+/ });
const Plus = createToken({ name: "Plus", pattern: /\+/ });
const LParen = createToken({ name: "LParen", pattern: /\(/ });
const RParen = createToken({ name: "RParen", pattern: /\)/ });
const WS = createToken({ name: "WS", pattern: /\s+/, group: Lexer.SKIPPED });

const allTokens = [WS, Number_, Plus, LParen, RParen];
const lex = new Lexer(allTokens);

const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("lex.simple", lex.tokenize("1 + 2").tokens.map(t => t.image));
log("lex.parens", lex.tokenize("(1 + 2)").tokens.map(t => t.image));
log("lex.spaces", lex.tokenize("  1   +   2  ").tokens.map(t => t.image));
log("lex.error", lex.tokenize("1 @ 2").errors.length > 0);

console.log(out.join("\n"));
