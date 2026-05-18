// Runner: exercise Zod schema validation. Tests cover primitive
// schemas, refinements, objects, arrays, unions, transforms, default
// values, optional/nullable, error formatting (the SafeParseResult
// .success branch is the canary - failure paths return ZodError with
// structured `issues`). Each result is captured + serialised.
//
// Zod attaches as the global `Zod` (UMD), exposing `Zod.z` and many
// top-level helpers.

const z = Zod.z;
const out = [];
const log = (label, value) => out.push(label + " " + JSON.stringify(value));

// 1. Primitive schemas
const numSchema = z.number();
log("num.parse.ok", numSchema.safeParse(42).success);
log("num.parse.bad", numSchema.safeParse("x").success);

const strSchema = z.string();
log("str.parse.ok", strSchema.safeParse("hi").success);
log("str.parse.bad", strSchema.safeParse(42).success);

const boolSchema = z.boolean();
log("bool.parse.ok", boolSchema.safeParse(true).success);

// 2. String refinements
const emailSchema = z.string().email();
log("email.ok", emailSchema.safeParse("alice@example.com").success);
log("email.bad", emailSchema.safeParse("not-an-email").success);

const lenSchema = z.string().min(3).max(10);
log("len.ok", lenSchema.safeParse("hello").success);
log("len.short", lenSchema.safeParse("hi").success);
log("len.long", lenSchema.safeParse("this is way too long").success);

// 3. Number refinements
const intSchema = z.number().int().positive();
log("int.pos.ok", intSchema.safeParse(5).success);
log("int.pos.float", intSchema.safeParse(3.14).success);
log("int.pos.neg", intSchema.safeParse(-1).success);

// 4. Object schema with nested
const userSchema = z.object({
  id: z.number().int(),
  name: z.string().min(1),
  email: z.string().email(),
  age: z.number().optional(),
});
log("obj.ok", userSchema.safeParse({
  id: 1,
  name: "Alice",
  email: "alice@example.com",
  age: 30,
}).success);
log("obj.missing-required", userSchema.safeParse({
  id: 1,
  name: "Alice",
  // email missing
}).success);
log("obj.optional-omitted", userSchema.safeParse({
  id: 1,
  name: "Bob",
  email: "bob@example.com",
  // age omitted, should still parse
}).success);

// 5. Array schemas
const tagsSchema = z.array(z.string()).min(1);
log("arr.ok", tagsSchema.safeParse(["a", "b"]).success);
log("arr.empty", tagsSchema.safeParse([]).success);
log("arr.wrong-type", tagsSchema.safeParse(["a", 42]).success);

// 6. Union / discriminated union
const shapeSchema = z.discriminatedUnion("kind", [
  z.object({ kind: z.literal("circle"), radius: z.number() }),
  z.object({ kind: z.literal("square"), side: z.number() }),
]);
log("union.circle", shapeSchema.safeParse({ kind: "circle", radius: 3 }).success);
log("union.square", shapeSchema.safeParse({ kind: "square", side: 4 }).success);
log("union.unknown", shapeSchema.safeParse({ kind: "triangle" }).success);

// 7. Transforms
const upperSchema = z.string().transform((s) => s.toUpperCase());
log("transform", upperSchema.parse("hello"));

// 8. Default values
const portSchema = z.number().default(8080);
log("default.undef", portSchema.parse(undefined));
log("default.given", portSchema.parse(443));

// 9. Nullable + optional
const orNullSchema = z.string().nullable();
log("nullable.null", orNullSchema.safeParse(null).success);
log("nullable.str", orNullSchema.safeParse("x").success);

// 10. Error formatting — collect issue paths + codes
const r = userSchema.safeParse({ id: "x", name: "", email: "bad" });
log("err.success", r.success);
if (!r.success) {
  // Deterministic: sort by path-string so order doesn't depend on iteration
  const issues = r.error.issues
    .map((i) => ({ path: i.path.join("."), code: i.code }))
    .sort((a, b) => a.path.localeCompare(b.path));
  log("err.issues", issues);
}

// 11. Refine with custom predicate
const evenSchema = z.number().refine((n) => n % 2 === 0, { message: "must be even" });
log("refine.even.4", evenSchema.safeParse(4).success);
log("refine.even.5", evenSchema.safeParse(5).success);

// 12. Enum
const colorSchema = z.enum(["red", "green", "blue"]);
log("enum.ok", colorSchema.safeParse("red").success);
log("enum.bad", colorSchema.safeParse("purple").success);

console.log(out.join("\n"));
