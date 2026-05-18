// prettier — opinionated JS formatter. Standalone browser build with
// babel + estree plugins. The format API is async and returns the
// formatted source. The bundle exposes:
//   PrettierBundle.default.prettier      — main namespace (.format)
//   PrettierBundle.default.parserBabel   — babel parser plugin
//   PrettierBundle.default.parserEstree  — estree printer plugin
const { prettier, parserBabel, parserEstree } = PrettierBundle.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const samples = [
  ["simple", "const x=1;const y=  2;\nfunction add(a,b){return a+b}"],
  ["arrow", "const f=(x,y)=>{return x*y};const g = x =>x+1;"],
  ["object", "const o={a:1,b:2,c:{d:3,e:[1,2,3,4,5]},f:function(){return 'hello'}};"],
  ["class", "class Foo{constructor(x){this.x=x}greet(){return 'hi '+this.x}}"],
  ["jsx-less", "const arr=[1,2,3,4,5].map(x=>x*2).filter(x=>x>4);"],
  ["template", "const s=`hello ${name} world ${count} items`;"],
  ["async", "async function f(){const x=await fetch('/api');return x.json()}"],
  ["destructure", "const {a,b,c=10,...rest}=obj;const[x,y,...tail]=arr;"],
  ["spread", "const merged={...a,...b,extra:1};const arr=[...a,...b,99];"],
  ["ternary", "const r=a>b?'big':a<b?'small':a===b?'equal':'unknown';"],
];

async function run() {
  for (const [label, code] of samples) {
    try {
      const formatted = await prettier.format(code, {
        parser: "babel",
        plugins: [parserBabel, parserEstree],
      });
      log(label, formatted);
    } catch (e) {
      log(label + ".err", e && e.message ? e.message : String(e));
    }
  }

  // Options exercise: explicit width, tabs, semicolons off.
  try {
    const tight = await prettier.format("const x=1;const y=2;function f(a,b){return a+b}", {
      parser: "babel",
      plugins: [parserBabel, parserEstree],
      printWidth: 40,
      tabWidth: 4,
      useTabs: false,
      semi: false,
      singleQuote: true,
    });
    log("options", tight);
  } catch (e) {
    log("options.err", e && e.message ? e.message : String(e));
  }

  console.log(out.join("\n"));
}

run();
