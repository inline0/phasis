const sh = Showdown.default ?? Showdown;
const converter = new sh.Converter();
const out = [];

const samples = {
  heading: "# Hello\n## World",
  emphasis: "**bold** and *italic*",
  list: "- one\n- two\n  - nested\n- three",
  code: "```js\nconst x = 1;\n```",
  link: "[link](https://example.com)",
  inline_code: "`inline` code",
  blockquote: "> quoted",
  hr: "before\n\n---\n\nafter",
  combined: "# Title\n\nSome **bold** text with a [link](https://x.com).\n\n- item\n- another",
};

for (const [k, v] of Object.entries(samples)) {
  out.push("=== " + k + " ===");
  out.push(converter.makeHtml(v));
}

console.log(out.join("\n"));
