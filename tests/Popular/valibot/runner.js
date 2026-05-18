// Runner: Valibot — modular schema validation. Uses safeParse to
// capture success/failure across primitives, refinements, objects,
// arrays, unions, transforms.

const v = Valibot;
const out = [];
const log = (k, val) => out.push(k + " " + JSON.stringify(val));

const numSchema = v.number();
log("num.ok", v.safeParse(numSchema, 42).success);
log("num.bad", v.safeParse(numSchema, "x").success);

const emailSchema = v.pipe(v.string(), v.email());
log("email.ok", v.safeParse(emailSchema, "a@b.com").success);
log("email.bad", v.safeParse(emailSchema, "not-an-email").success);

const userSchema = v.object({
  id: v.number(),
  name: v.pipe(v.string(), v.minLength(1)),
  age: v.optional(v.number()),
});
log("obj.ok", v.safeParse(userSchema, { id: 1, name: "Alice", age: 30 }).success);
log("obj.optional", v.safeParse(userSchema, { id: 1, name: "Bob" }).success);
log("obj.missing", v.safeParse(userSchema, { id: 1 }).success);
log("obj.empty-name", v.safeParse(userSchema, { id: 1, name: "" }).success);

const arrSchema = v.pipe(v.array(v.string()), v.minLength(1));
log("arr.ok", v.safeParse(arrSchema, ["a"]).success);
log("arr.empty", v.safeParse(arrSchema, []).success);

const unionSchema = v.union([v.string(), v.number()]);
log("union.str", v.safeParse(unionSchema, "x").success);
log("union.num", v.safeParse(unionSchema, 42).success);
log("union.bool", v.safeParse(unionSchema, true).success);

const transformSchema = v.pipe(v.string(), v.transform(s => s.toUpperCase()));
log("transform", v.parse(transformSchema, "hello"));

const enumSchema = v.picklist(["red", "green", "blue"]);
log("enum.ok", v.safeParse(enumSchema, "red").success);
log("enum.bad", v.safeParse(enumSchema, "purple").success);

console.log(out.join("\n"));
