// Runner: exercise Handlebars's template compile + render path. Tests
// cover variable interpolation, conditional blocks, iteration, helpers
// (built-in + registered), partials, and HTML escaping. Each template
// compiles once and renders once; the concatenated output is the oracle.
//
// Handlebars attaches as the global `Handlebars` (UMD).

const out = [];

// 1. Plain interpolation + escaping
{
  const tpl = Handlebars.compile("Hi {{name}} — bio: {{bio}}");
  out.push("plain " + JSON.stringify(tpl({
    name: "Alice",
    bio: "<script>alert(1)</script>",
  })));
}

// 2. Triple-stache (no escape)
{
  const tpl = Handlebars.compile("{{{raw}}}");
  out.push("triple " + JSON.stringify(tpl({ raw: "<b>bold</b>" })));
}

// 3. Conditional blocks
{
  const tpl = Handlebars.compile(
    "{{#if available}}YES{{else}}NO{{/if}}",
  );
  out.push("if.true " + JSON.stringify(tpl({ available: true })));
  out.push("if.false " + JSON.stringify(tpl({ available: false })));
}

// 4. Iteration with {{#each}} + {{@index}} + {{@first}} / {{@last}}
{
  const tpl = Handlebars.compile(
    "{{#each items}}[{{@index}}{{#if @first}}f{{/if}}{{#if @last}}l{{/if}}={{this}}]{{/each}}",
  );
  out.push("each.idx " + JSON.stringify(tpl({ items: ["a", "b", "c"] })));
}

// 5. Iteration over objects (key/value)
{
  const tpl = Handlebars.compile(
    "{{#each settings}}{{@key}}={{this}};{{/each}}",
  );
  out.push("each.kv " + JSON.stringify(tpl({
    settings: { debug: true, mode: "dev", level: 3 },
  })));
}

// 6. Nested context with #with
{
  const tpl = Handlebars.compile(
    "{{#with user}}{{name}} ({{email}}){{/with}}",
  );
  out.push("with " + JSON.stringify(tpl({
    user: { name: "Bob", email: "bob@example.com" },
  })));
}

// 7. Built-in helpers: eq, lookup, log? (some are runtime-dispatched)
//    We use {{lookup}} which retrieves an array element by index.
{
  const tpl = Handlebars.compile(
    "{{lookup tags 0}} {{lookup tags 1}} {{lookup tags 2}}",
  );
  out.push("lookup " + JSON.stringify(tpl({ tags: ["x", "y", "z"] })));
}

// 8. Custom helper registration
Handlebars.registerHelper("shout", function (s) {
  return new Handlebars.SafeString(String(s).toUpperCase() + "!");
});
{
  const tpl = Handlebars.compile("{{shout name}}");
  out.push("helper " + JSON.stringify(tpl({ name: "hello" })));
}

// 9. Block helper that wraps inner content
Handlebars.registerHelper("bold", function (options) {
  return "<b>" + options.fn(this) + "</b>";
});
{
  const tpl = Handlebars.compile("{{#bold}}{{name}}{{/bold}}");
  out.push("block " + JSON.stringify(tpl({ name: "Eve" })));
}

// 10. Partials
Handlebars.registerPartial("greeting", "Hello, {{name}}!");
{
  const tpl = Handlebars.compile("{{> greeting}} You are {{age}}.");
  out.push("partial " + JSON.stringify(tpl({ name: "Carol", age: 30 })));
}

// 11. Path expressions (../ to walk up the context)
{
  const tpl = Handlebars.compile(
    "{{#each items}}{{../prefix}}{{this}};{{/each}}",
  );
  out.push("path " + JSON.stringify(tpl({
    prefix: "v=",
    items: [1, 2, 3],
  })));
}

// 12. Subexpressions
{
  const tpl = Handlebars.compile("{{shout (lookup names 0)}}");
  out.push("subexpr " + JSON.stringify(tpl({ names: ["alice", "bob"] })));
}

// 13. Compile-and-render reuse — same compiled fn, different context
{
  const tpl = Handlebars.compile("Item {{n}}: {{label}}");
  out.push("reuse.1 " + JSON.stringify(tpl({ n: 1, label: "first" })));
  out.push("reuse.2 " + JSON.stringify(tpl({ n: 2, label: "second" })));
}

console.log(out.join("\n"));
