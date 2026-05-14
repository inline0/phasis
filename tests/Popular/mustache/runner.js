// Runner: render several templates with mustache.js and emit the
// rendered strings concatenated. Exercises sections, inverted
// sections, partials, lambdas, escaping, and dotted lookup.

const data = {
  name: "Phasis",
  description: "Pure PHP JavaScript engine",
  features: [
    { title: "Pure PHP", body: "No Node, no FFI" },
    { title: "Spec-compliant", body: "99.97% test262" },
    { title: "Embeddable", body: "Drop into any PHP app" },
  ],
  empty: [],
  meta: { repo: { owner: "inline0", name: "phasis" } },
  raw: "<script>alert(1)</script>",
  upper: function () {
    return function (text, render) {
      return render(text).toUpperCase();
    };
  },
};

const partials = {
  feature: "- **{{title}}**: {{body}}",
};

const templates = [
  // Simple substitution + escaping
  "Project: {{name}}\nDescription: {{description}}\n",

  // Triple-mustache (unescaped)
  "Escaped: {{raw}}\nRaw: {{{raw}}}\n",

  // Section over array
  "Features:\n{{#features}}- {{title}}: {{body}}\n{{/features}}",

  // Inverted section
  "{{#empty}}has items{{/empty}}{{^empty}}empty{{/empty}}\n",

  // Dotted lookup
  "Repo: {{meta.repo.owner}}/{{meta.repo.name}}\n",

  // Partial
  "{{#features}}{{> feature}}\n{{/features}}",

  // Lambda
  "{{#upper}}phasis ftw{{/upper}}\n",
];

const out = templates
  .map((t, i) => `=== template ${i} ===\n${Mustache.render(t, data, partials)}`)
  .join("\n");

console.log(out);
