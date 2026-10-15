import { createElement as h } from "react";
import { renderToString } from "react-dom/server";
import Markdown from "react-markdown";
const text = "# Title\n\nSome *emphasis*, **strong** and `code`.\n\n- one\n- two\n\n[link](https://example.com \"T\") & <b>raw</b>\n\n> quote";
console.log(renderToString(h(Markdown, null, text)));
console.log(renderToString(h(Markdown, { components: { h1: (props) => h("h2", { className: "t" }, props.children) } }, "# Mapped")));
