// cheerio — jQuery-style API on top of parse5/htmlparser2. Tests
// selector engine, traversal, and HTML serialization.
const cheerio = Cheerio.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const html = `
<html>
  <body>
    <h1 id="title" class="hero">Phasis</h1>
    <p class="intro">A <strong>pure-PHP</strong> JS engine.</p>
    <ul class="list">
      <li data-i="1">one</li>
      <li data-i="2" class="active">two</li>
      <li data-i="3">three</li>
    </ul>
    <a href="https://example.com/a">A</a>
    <a href="https://example.com/b">B</a>
  </body>
</html>`;

const $ = cheerio.load(html);

// Basic selectors
log("h1.text", $("h1").text());
log("h1.id", $("h1").attr("id"));
log("h1.class", $("h1").attr("class"));

// Class selector
log("intro.text", $(".intro").text());

// Children
log("li.count", $("li").length);
log("li.texts", $("li").map((_, el) => $(el).text()).get());

// Attribute selector
log("active.text", $("li.active").text());
log("active.data-i", $("li.active").attr("data-i"));

// :nth-child
log("nth.2", $("li:nth-child(2)").text());

// Traversal
log("first.next", $("li").first().next().text());
log("last.prev", $("li").last().prev().text());

// Mutation
$("li").addClass("item");
log("after.addClass", $("li").first().attr("class"));

$("li.active").removeClass("active").addClass("selected");
log("after.swap", $("li.selected").attr("data-i"));

// Append + text content
$("ul.list").append('<li data-i="4">four</li>');
log("count.after.append", $("li").length);

// Attribute extraction
log("hrefs", $("a").map((_, el) => $(el).attr("href")).get());

// Render back
const rendered = $.html("h1");
log("h1.html", rendered);

// Strip tags via .text on the parent
log("p.text", $("p").text());

console.log(out.join("\n"));
