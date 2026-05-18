// jsonata — JSON query / transformation language (like JMESPath/jq).
const jsonata = JsonataBundle.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const data = {
  store: {
    book: [
      { category: "fiction", title: "Foundation", author: "Asimov", price: 12.99 },
      { category: "fiction", title: "Dune", author: "Herbert", price: 15.5 },
      { category: "tech", title: "Programming JS", author: "Alice", price: 24 },
      { category: "tech", title: "Pure PHP Engines", author: "Bob", price: 19.95 },
    ],
    bicycle: { color: "red", price: 199.99 },
  },
  user: { name: "Carol", roles: ["admin", "user"] },
};

async function run(expr) {
  const compiled = jsonata(expr);
  return await compiled.evaluate(data);
}

async function main() {
  log("user.name", await run("user.name"));
  log("user.roles[0]", await run("user.roles[0]"));
  log("book.titles", await run("store.book.title"));
  log("book.count", await run("$count(store.book)"));
  log("fiction.titles", await run("store.book[category='fiction'].title"));
  log("avg.price", await run("$average(store.book.price)"));
  log("sum.price", await run("$sum(store.book.price)"));
  log("max.book", await run("store.book[price=$max(store.book.price)]"));
  log("first.tech", await run("store.book[category='tech'][0].title"));
  log("authors.set", await run("$distinct(store.book.author)"));

  // String functions
  log("upper", await run("$uppercase(user.name)"));
  log("split", await run("$split('a,b,c,d', ',')"));
  log("substring", await run("$substring(user.name, 0, 3)"));

  // Numeric
  log("round", await run("$round(3.14159, 2)"));
  log("abs", await run("$abs(-42)"));

  console.log(out.join("\n"));
}

main();
