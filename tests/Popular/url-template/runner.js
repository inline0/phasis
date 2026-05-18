const { parseTemplate } = UrlTemplate;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

log("simple", parseTemplate("/users/{id}").expand({ id: 42 }));
log("multi", parseTemplate("/users/{id}/posts/{post}").expand({ id: 1, post: 99 }));
log("query", parseTemplate("/search{?q,limit}").expand({ q: "phasis", limit: 10 }));
log("hash", parseTemplate("/page{#section}").expand({ section: "intro" }));
log("path", parseTemplate("/files{/path*}").expand({ path: ["a", "b", "c"] }));
log("missing.optional", parseTemplate("/x/{y}").expand({}));
log("encode", parseTemplate("/{q}").expand({ q: "hello world" }));
log("reserved", parseTemplate("/{+q}").expand({ q: "hello/world" }));

console.log(out.join("\n"));
