// sax — streaming SAX-style XML parser.
const sax = SaxBundle.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const parser = sax.parser(true);  // strict mode

const events = [];
parser.onopentag = (node) => events.push({ open: node.name, attrs: node.attributes });
parser.onclosetag = (name) => events.push({ close: name });
parser.ontext = (t) => { const tr = t.trim(); if (tr) events.push({ text: tr }); };
parser.oncomment = (c) => events.push({ comment: c.trim() });
parser.oncdata = (d) => events.push({ cdata: d });
parser.onprocessinginstruction = (pi) => events.push({ pi });

const xml = `<?xml version="1.0"?>
<library>
  <book id="b1">
    <title>Foundation</title>
    <author>Asimov</author>
  </book>
  <!-- a comment -->
  <book id="b2"><![CDATA[<raw>data</raw>]]></book>
</library>`;

parser.write(xml).close();

log("events.count", events.length);
log("events", events);

console.log(out.join("\n"));
