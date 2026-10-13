import { createElement as h } from "react";
import { renderToString } from "react-dom/server";
import { BeakerIcon } from "@heroicons/react/24/solid";
import { ArrowRightIcon } from "@heroicons/react/24/outline";
import { CheckIcon } from "@heroicons/react/20/solid";
console.log(renderToString(h("div", null, h(BeakerIcon, { className: "size-6 text-blue-500" }), h(ArrowRightIcon, { className: "size-4", title: "Next" }), h(CheckIcon, { "aria-hidden": "true" }))));
