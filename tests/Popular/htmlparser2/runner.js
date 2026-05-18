// htmlparser2 — streaming HTML parser. Event-based callback API +
// DOM-style document model. Exercises a heavy regex / character-
// class pipeline plus per-tag event dispatch.
const { Parser, parseDocument, DomUtils } = HP2.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// 1. Event-based parse
const html = `<!DOCTYPE html>
<html>
<head><title>Phasis</title></head>
<body>
  <h1 class="hero">Hello</h1>
  <p data-id="42">Lorem <a href="/x">link</a> ipsum.</p>
  <!-- a comment -->
  <ul>
    <li>one</li>
    <li>two</li>
    <li>three</li>
  </ul>
  <script>var x = 1;</script>
</body>
</html>`;

const events = [];
const parser = new Parser({
  onopentag(name, attribs) {
    events.push("open:" + name + ":" + JSON.stringify(attribs));
  },
  ontext(text) {
    const trimmed = text.trim();
    if (trimmed) events.push("text:" + JSON.stringify(trimmed));
  },
  onclosetag(name) {
    events.push("close:" + name);
  },
  oncomment(data) {
    events.push("comment:" + data.trim());
  },
});
parser.write(html);
parser.end();
log("events.count", events.length);
log("events", events);

// 2. DOM-style parse
const dom = parseDocument(html);
log("dom.type", dom.type);
log("dom.children", dom.children.length);

// Find first <h1>
const h1 = DomUtils.findOne((el) => el.name === "h1", dom.children, true);
log("h1.attrs", h1 ? h1.attribs : null);
log("h1.text", h1 ? DomUtils.textContent(h1) : null);

// All <li> elements
const lis = DomUtils.findAll((el) => el.name === "li", dom.children);
log("li.count", lis.length);
log("li.texts", lis.map((el) => DomUtils.textContent(el).trim()));

// Anchor href
const a = DomUtils.findOne((el) => el.name === "a", dom.children, true);
log("a.href", a ? a.attribs.href : null);

// Element name → tag count (head, body, html, h1, p, a, ul, li, script, title)
const tagCounts = {};
DomUtils.findAll(() => true, dom.children).forEach((el) => {
  tagCounts[el.name] = (tagCounts[el.name] || 0) + 1;
});
log("tags", tagCounts);

// 3. Self-closing + void elements
const html2 = `<div><img src="a.png"/><br><input type="text" /></div>`;
const dom2 = parseDocument(html2);
const elNames = DomUtils.findAll(() => true, dom2.children).map((el) => el.name);
log("voids", elNames);

console.log(out.join("\n"));
