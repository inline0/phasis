const stylis = StylisLib.default;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));
const css = `
  .container {
    color: red;
    &:hover { color: blue; }
    .nested { font-size: 14px; }
  }
`;
const compiled = stylis.serialize(stylis.compile(css), stylis.stringify);
log("nested", compiled);
const css2 = `
  @media (min-width: 600px) {
    .responsive { width: 50%; }
  }
`;
log("media", stylis.serialize(stylis.compile(css2), stylis.stringify));
console.log(out.join("\n"));
