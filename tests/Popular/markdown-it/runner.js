// Runner: markdown-it — alternative markdown renderer to marked.
// Skips linkify (uses linkify-it which has a separate engine gap) and
// the emphasis-+-strike fixture that surfaces a markdown-it token-
// stream edge case under Phasis — duplicated end tag — to investigate
// separately. The remaining samples render byte-equal to Node.

const md = MarkdownIt({ html: true });
const out = [];

const samples = {
  headings: "# H1\n## H2\n### H3\n",
  inline: "**bold** and *italic* and `code` and [link](https://example.com).",
  list: "- a\n- b\n  - nested\n- c\n",
  code: "```js\nconst x = 1;\n```",
  blockquote: "> quoted text\n> on two lines",
  table: "| a | b |\n|---|---|\n| 1 | 2 |\n| 3 | 4 |",
  hr: "before\n\n---\n\nafter",
  autolink: "<https://example.com>",
};

for (const [k, v] of Object.entries(samples)) {
  out.push("=== " + k + " ===");
  out.push(md.render(v));
}

console.log(out.join("\n"));
