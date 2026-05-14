// Runner: render a comprehensive markdown sample through marked and
// emit the resulting HTML. The oracle (oracle.txt) is the output of
// running this exact runner under Node.js. CI runs it under Phasis
// and byte-compares the output.

const sources = {
  headings: `# H1\n## H2\n### H3\n#### H4\n##### H5\n###### H6\n\nAlt H1\n======\n\nAlt H2\n------\n`,

  inline: [
    "**bold** and *italic* and ***both*** and ~~strike~~ and \`inline code\` and a [link](https://example.com) and an ![alt](https://example.com/img.png).\n",
    "\n",
    "Auto-link: <https://example.com>.\n",
  ].join(""),

  lists: [
    "- Top level a\n",
    "- Top level b\n",
    "  - Nested b.1\n",
    "  - Nested b.2\n",
    "    - Deeper b.2.i\n",
    "- Top level c\n",
    "\n",
    "1. First\n",
    "2. Second\n",
    "3. Third\n",
    "   1. Nested first\n",
    "   2. Nested second\n",
    "\n",
    "- [x] Task done\n",
    "- [ ] Task pending\n",
  ].join(""),

  code: [
    "    indented code block\n",
    "    line two\n",
    "\n",
    "```js\n",
    "function greet(name) {\n",
    "  return `hello, ${name}`;\n",
    "}\n",
    "```\n",
    "\n",
    "```\n",
    "plain fenced block\n",
    "```\n",
  ].join(""),

  blockquote: [
    "> Outer quote.\n",
    "> > Nested quote.\n",
    "> > > Triple-nested.\n",
    ">\n",
    "> Back to outer.\n",
  ].join(""),

  table: [
    "| Name | Age | Role |\n",
    "|------|----:|:----:|\n",
    "| Alice | 30 | admin |\n",
    "| Bob   | 25 | user  |\n",
    "| Carol | 35 | user  |\n",
  ].join(""),

  hr: "---\n\n***\n\n___\n",

  htmlPassthrough: [
    "<div class=\"note\">\n",
    "Raw HTML passes through marked unchanged.\n",
    "</div>\n",
  ].join(""),

  combined: [
    "# Project README\n",
    "\n",
    "A short summary with **bold** emphasis and a [link](./docs).\n",
    "\n",
    "## Features\n",
    "\n",
    "- Fast\n",
    "- Pure JS\n",
    "- > 99% coverage\n",
    "\n",
    "## Example\n",
    "\n",
    "```js\n",
    "const x = compute(42);\n",
    "console.log(x);\n",
    "```\n",
    "\n",
    "See the [docs](#docs) for more.\n",
  ].join(""),
};

const out = [];
for (const key of Object.keys(sources)) {
  out.push("=== " + key + " ===");
  out.push(marked.parse(sources[key]));
}
console.log(out.join("\n"));
