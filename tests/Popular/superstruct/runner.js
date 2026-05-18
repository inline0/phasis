const s = Superstruct;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const num = s.number();
log("num.ok", s.is(42, num));
log("num.bad", s.is("x", num));

const User = s.object({
  id: s.number(),
  name: s.string(),
  age: s.optional(s.number()),
});

log("obj.ok", s.is({ id: 1, name: "Alice", age: 30 }, User));
log("obj.optional", s.is({ id: 1, name: "Bob" }, User));
log("obj.missing", s.is({ id: 1 }, User));
log("obj.extra-no-mask", s.is({ id: 1, name: "C", extra: 1 }, User));

// Lists
const Tags = s.array(s.string());
log("arr.ok", s.is(["a", "b"], Tags));
log("arr.bad", s.is(["a", 2], Tags));

// Refinement
const Positive = s.refine(s.number(), "positive", n => n > 0);
log("refine.ok", s.is(5, Positive));
log("refine.bad", s.is(-1, Positive));

// Enums
const Color = s.enums(["red", "green", "blue"]);
log("enum.ok", s.is("red", Color));
log("enum.bad", s.is("purple", Color));

// validate (returns [error, value])
const [err, val] = s.validate({ id: 1, name: "Alice" }, User);
log("validate", { err: err === undefined, val });

const [err2] = s.validate({ id: "x" }, User);
log("validate.error", err2 ? err2.message.length > 0 : false);

// type
const point = s.type({ x: s.number(), y: s.number() });
log("type.ok", s.is({ x: 1, y: 2, z: 3 }, point)); // extra prop OK with type

console.log(out.join("\n"));
