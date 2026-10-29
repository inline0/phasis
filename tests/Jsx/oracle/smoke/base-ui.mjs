import { createElement as h } from "react";
import { renderToString } from "react-dom/server";
import { Accordion } from "@base-ui/react/accordion";
import { Tabs } from "@base-ui/react/tabs";
import { Button } from "@base-ui/react/button";
const faq = h(Accordion.Root, { className: "faq" }, ...["One", "Two"].map((title) => h(Accordion.Item, { key: title, value: title }, h(Accordion.Header, null, h(Accordion.Trigger, null, title)), h(Accordion.Panel, null, title + " answer"))));
const tabs = h(Tabs.Root, { defaultValue: "a" }, h(Tabs.List, null, h(Tabs.Tab, { value: "a" }, "A"), h(Tabs.Tab, { value: "b" }, "B"), h(Tabs.Indicator)), h(Tabs.Panel, { value: "a" }, "Panel A"), h(Tabs.Panel, { value: "b" }, "Panel B"));
console.log(renderToString(h("main", null, faq, tabs, h(Button, { className: "cta" }, "Buy"))));
