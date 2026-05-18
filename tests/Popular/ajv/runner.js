// ajv — JSON Schema validator. Compiles schemas to validators via
// `new Function(...)` at runtime; exercises Phasis's Function-
// constructor path heavily.
const Ajv = AjvBundle.default;
const ajv = new Ajv({ allErrors: true });
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// 1. Simple object schema
const userSchema = {
  type: "object",
  properties: {
    id: { type: "integer", minimum: 1 },
    name: { type: "string", minLength: 1 },
    email: { type: "string", pattern: "^[^@]+@[^@]+$" },
    roles: { type: "array", items: { type: "string" }, minItems: 1 },
  },
  required: ["id", "name"],
  additionalProperties: false,
};

const validateUser = ajv.compile(userSchema);

log("valid.ok", validateUser({ id: 1, name: "Alice", roles: ["admin"] }));
log("valid.missing", validateUser({ name: "Alice" }));
log("valid.missing.errors", (validateUser.errors || []).map(e => e.keyword + ":" + e.instancePath + ":" + e.message));

log("valid.extra", validateUser({ id: 1, name: "Alice", extra: 99 }));
log("valid.extra.errors", (validateUser.errors || []).map(e => e.keyword + ":" + e.message));

log("valid.types", validateUser({ id: "wrong", name: 123 }));
log("valid.types.errors", (validateUser.errors || []).map(e => e.keyword + ":" + e.instancePath));

// 2. Nested object with anyOf
const eventSchema = {
  type: "object",
  properties: {
    type: { enum: ["click", "hover", "submit"] },
    payload: {
      anyOf: [
        { type: "object", properties: { x: { type: "number" }, y: { type: "number" } }, required: ["x", "y"] },
        { type: "string" },
      ],
    },
  },
  required: ["type", "payload"],
};
const validateEvent = ajv.compile(eventSchema);
log("event.coord", validateEvent({ type: "click", payload: { x: 1, y: 2 } }));
log("event.string", validateEvent({ type: "hover", payload: "tooltip" }));
log("event.bad.enum", validateEvent({ type: "scroll", payload: "x" }));
log("event.bad.payload", validateEvent({ type: "click", payload: { x: 1 } }));

// 3. Array of items with min/max
const tagsSchema = {
  type: "array",
  items: { type: "string", pattern: "^[a-z]+$" },
  minItems: 1,
  maxItems: 3,
  uniqueItems: true,
};
const validateTags = ajv.compile(tagsSchema);
log("tags.ok", validateTags(["a", "b", "c"]));
log("tags.empty", validateTags([]));
log("tags.dup", validateTags(["a", "a"]));
log("tags.pattern", validateTags(["A"]));

// 4. ref / definitions
const refSchema = {
  $defs: {
    addr: {
      type: "object",
      properties: { street: { type: "string" }, zip: { type: "string", pattern: "^[0-9]{5}$" } },
      required: ["zip"],
    },
  },
  type: "object",
  properties: {
    home: { $ref: "#/$defs/addr" },
    work: { $ref: "#/$defs/addr" },
  },
};
const validateRef = ajv.compile(refSchema);
log("ref.ok", validateRef({ home: { zip: "12345" }, work: { street: "x", zip: "67890" } }));
log("ref.bad", validateRef({ home: { zip: "abc" } }));

console.log(out.join("\n"));
