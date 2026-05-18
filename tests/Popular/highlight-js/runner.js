// highlight.js — syntax-highlight a handful of code snippets across
// 7 registered languages. We log the highlighted HTML so any drift in
// regex/tokenizer behavior surfaces byte-for-byte.
const hljs = Hljs.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

function hi(lang, code) {
  return hljs.highlight(code, { language: lang }).value;
}

log("js.fn", hi("javascript", "function add(a, b) { return a + b; }"));
log("js.class", hi("javascript", "class Box { constructor(x){ this.x = x; } }"));
log("js.template", hi("javascript", "const s = `hello ${name}`;"));
log("ts.iface", hi("typescript", "interface User { id: number; name: string; }"));
log("ts.generic", hi("typescript", "function id<T>(x: T): T { return x; }"));
log("json", hi("json", '{"a":1,"b":[true,null,"x"]}'));
log("html", hi("html", "<div class=\"x\"><span>hi</span></div>"));
log("css", hi("css", ".x { color: #f00; padding: 4px; }"));
log("bash", hi("bash", "for f in *.js; do echo \"$f\"; done"));
log("py.def", hi("python", "def add(a, b):\n    return a + b"));
log("py.class", hi("python", "class Box:\n    def __init__(self, x):\n        self.x = x"));

// Auto-detection
const auto = hljs.highlightAuto("const x = 42;");
log("auto.lang", auto.language);

// Listed languages
log("langs", hljs.listLanguages().sort());

console.log(out.join("\n"));
