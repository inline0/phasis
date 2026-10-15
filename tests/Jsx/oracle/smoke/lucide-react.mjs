import { createElement as h } from "react";
import { renderToString } from "react-dom/server";
import { MessageCircle, Heart, ChevronRight } from "lucide-react";
console.log(renderToString(h("span", null, h(MessageCircle, { size: 16 }), " 3 comments", h(Heart, { color: "red", strokeWidth: 1.5, className: "ml-1" }), h(ChevronRight, { "aria-label": "next" }))));
