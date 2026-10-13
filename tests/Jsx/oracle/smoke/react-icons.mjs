import { createElement as h } from "react";
import { renderToString } from "react-dom/server";
import { FaGithub, FaRegStar } from "react-icons/fa";
import { MdOutlineEmail } from "react-icons/md";
console.log(renderToString(h("p", null, h(FaGithub, { size: "1.5em", title: "GitHub" }), h(FaRegStar, { className: "star" }), h(MdOutlineEmail, null))));
