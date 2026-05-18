// Runner: Yup schema validation. Uses isValidSync (sync, deterministic)
// to capture success/failure. Async paths skipped.

const yup = Yup;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

const numSchema = yup.number().required();
log("num.ok", numSchema.isValidSync(42));
log("num.bad", numSchema.isValidSync("x"));
log("num.missing", numSchema.isValidSync(undefined));

const emailSchema = yup.string().email();
log("email.ok", emailSchema.isValidSync("a@b.com"));
log("email.bad", emailSchema.isValidSync("not-an-email"));

const lenSchema = yup.string().min(3).max(10);
log("len.ok", lenSchema.isValidSync("hello"));
log("len.short", lenSchema.isValidSync("hi"));
log("len.long", lenSchema.isValidSync("way too long indeed"));

const userSchema = yup.object({
  id: yup.number().integer().required(),
  name: yup.string().min(1).required(),
  age: yup.number().min(0),
});
log("obj.ok", userSchema.isValidSync({ id: 1, name: "Alice", age: 30 }));
log("obj.missing", userSchema.isValidSync({ id: 1 }));
log("obj.optional-omitted", userSchema.isValidSync({ id: 1, name: "B" }));

const arrSchema = yup.array().of(yup.string()).min(1);
log("arr.ok", arrSchema.isValidSync(["a"]));
log("arr.empty", arrSchema.isValidSync([]));

// Cast
const intCast = yup.number().integer();
log("cast.int", intCast.cast("42"));

// Default
const portSchema = yup.number().default(8080);
log("default", portSchema.cast(undefined));

console.log(out.join("\n"));
