// Runner: exercise Effect 4. The esbuild IIFE bundle exposes the package
// namespace as the global `effect` (its `Effect` export is the Effect
// module itself). Covers pipe, Effect.gen, typed errors, Exit and Cause,
// Option and Result, Schema decode/encode, services and layers, Ref,
// concurrency, fibers, Queue, Stream, Schedule, Match, structural
// equality, HashMap and Duration. Everything is synchronous through
// runSync except the final program, which runs through runPromise.
const { Effect, Exit, Cause, Option, Result, Schema, Data, Context, Layer, Ref, Stream, Schedule, Fiber, Queue, Match, Equal, Hash, HashMap, HashSet, Duration, Chunk, Array: A, pipe } = effect;
const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// 1. pipe and basic combinators
log("pipe", pipe(2, (n) => n + 3, (n) => n * 10));
log("map.flatMap", Effect.runSync(Effect.succeed(20).pipe(Effect.map((n) => n + 1), Effect.flatMap((n) => Effect.succeed(n * 2)))));

// 2. Effect.gen
const program = Effect.gen(function* () {
  const a = yield* Effect.succeed(4);
  const b = yield* Effect.sync(() => a * a);
  return a + b;
});
log("gen", Effect.runSync(program));

// 3. Typed errors and catchTag
class NotFound extends Data.TaggedError("NotFound") {}
class Invalid extends Data.TaggedError("Invalid") {}
const lookup = (id) => id === 1 ? Effect.succeed({ id, name: "ada" }) : id < 0 ? Effect.fail(new Invalid({ id })) : Effect.fail(new NotFound({ id }));
const safe = (id) => lookup(id).pipe(
  Effect.map((u) => u.name),
  Effect.catchTag("NotFound", (e) => Effect.succeed("missing " + e.id)),
);
log("catchTag.ok", Effect.runSync(safe(1)));
log("catchTag.caught", Effect.runSync(safe(7)));
const invalidExit = Effect.runSyncExit(safe(-2));
log("exit.isFailure", Exit.isFailure(invalidExit));
const err = Cause.squash(invalidExit.cause);
log("exit.error", { tag: err._tag, id: err.id, isError: err instanceof Error });
log("catch.all", Effect.runSync(lookup(-5).pipe(Effect.catch((e) => Effect.succeed("recovered " + e._tag)))));
log("try", Effect.runSync(Effect.try({ try: () => JSON.parse("{bad"), catch: () => "bad json" }).pipe(Effect.catch((e) => Effect.succeed(e)))));
log("orDie.defect", Exit.isFailure(Effect.runSyncExit(Effect.die("boom"))));

// 4. Option and Result
log("option.some", Option.getOrElse(Option.map(Option.some(5), (n) => n * 2), () => 0));
log("option.none", Option.getOrElse(Option.none(), () => "default"));
log("option.fromNullishOr", [Option.isSome(Option.fromNullishOr(0)), Option.isNone(Option.fromNullishOr(null))]);
const parsed = Result.try(() => JSON.parse('{"x":1}'));
log("result.success", Result.isSuccess(parsed) ? parsed.success : null);
const failed = Result.try({ try: () => JSON.parse("{"), catch: () => "parse error" });
log("result.failure", Result.isFailure(failed) ? failed.failure : null);
log("result.map", Result.getOrElse(Result.map(Result.succeed(3), (n) => n + 1), () => -1));

// 5. Schema
const User = Schema.Struct({ name: Schema.String, age: Schema.Number, tags: Schema.Array(Schema.String) });
log("schema.decode.ok", Schema.decodeUnknownSync(User)({ name: "ada", age: 36, tags: ["math"] }));
const badUser = Schema.decodeUnknownExit(User)({ name: "ada", age: "36" });
log("schema.decode.bad", Exit.isFailure(badUser));
try {
  Schema.decodeUnknownSync(User)({ name: 1, age: 2, tags: [] });
} catch (e) {
  log("schema.error", String(e.message).split("\n"));
}
const NumStr = Schema.NumberFromString;
log("schema.numberFromString", [Schema.decodeUnknownSync(NumStr)("42.5"), Schema.encodeSync(NumStr)(7)]);
class Point extends Schema.Class("Point")({ x: Schema.Number, y: Schema.Number }) {
  get length() { return Math.hypot(this.x, this.y); }
}
const p = Schema.decodeUnknownSync(Point)({ x: 3, y: 4 });
log("schema.class", [p instanceof Point, p.length, Schema.encodeSync(Point)(p)]);
log("schema.option", Schema.decodeUnknownOption(Schema.Number)("nope")._tag);

// 6. Services and layers
class Greeter extends Context.Service()("Greeter") {}
class Config extends Context.Service()("Config") {}
const ConfigLive = Layer.succeed(Config, { greeting: "Hello" });
const GreeterLive = Layer.effect(Greeter, Effect.gen(function* () {
  const config = yield* Config;
  return { greet: (name) => Effect.succeed(config.greeting + ", " + name + "!") };
})).pipe(Layer.provide(ConfigLive));
const greet = Effect.gen(function* () {
  const greeter = yield* Greeter;
  return yield* greeter.greet("Grace");
});
log("layer", Effect.runSync(Effect.provide(greet, GreeterLive)));
log("provideService", Effect.runSync(Effect.provideService(greet, Greeter, { greet: (n) => Effect.succeed("Hi " + n) })));

// 7. Ref, all, forEach
log("ref", Effect.runSync(Effect.gen(function* () {
  const ref = yield* Ref.make(0);
  yield* Effect.forEach([1, 2, 3, 4], (n) => Ref.update(ref, (x) => x + n));
  return yield* Ref.get(ref);
})));
log("all.tuple", Effect.runSync(Effect.all([Effect.succeed(1), Effect.succeed("two"), Effect.succeed(true)])));
log("all.struct", Effect.runSync(Effect.all({ a: Effect.succeed(1), b: Effect.succeed(2) })));
log("forEach.concurrent", Effect.runSync(Effect.forEach([3, 1, 2], (n) => Effect.succeed(n * n), { concurrency: "unbounded" })));

// 8. Match, Equal, Hash, HashMap, HashSet, Chunk, Array, Duration
const describe = Match.type().pipe(
  Match.when({ kind: "circle" }, (s) => "circle r=" + s.r),
  Match.when({ kind: "square" }, (s) => "square s=" + s.s),
  Match.orElse(() => "unknown"),
);
log("match", [describe({ kind: "circle", r: 2 }), describe({ kind: "square", s: 3 }), describe({ kind: "tri" })]);
class Pair extends Data.Class {}
log("equal.data", [Equal.equals(new Pair({ x: 1, y: "a" }), new Pair({ x: 1, y: "a" })), Equal.equals(new Pair({ x: 1, y: "a" }), new Pair({ x: 2, y: "a" })), Hash.hash("abc") === Hash.hash("abc")]);
const hm = HashMap.make(["a", 1], ["b", 2]).pipe(HashMap.set("c", 3), HashMap.remove("a"));
log("hashmap", [HashMap.size(hm), Option.getOrElse(HashMap.get(hm, "c"), () => 0), [...HashMap.keys(hm)].sort()]);
log("hashset", [...HashSet.make(3, 1, 3, 2)].sort());
log("chunk", Chunk.toReadonlyArray(Chunk.map(Chunk.make(1, 2, 3), (n) => n * 3)));
log("array", [A.groupBy([1, 2, 3, 4, 5], (n) => (n % 2 ? "odd" : "even")), A.dedupe([1, 1, 2, 3, 3])]);
log("duration", [Duration.toMillis(Duration.seconds(2)), Duration.format(Duration.millis(90061001)), String(Duration.minutes(1))]);

// 9. Async program: sleep, fibers, Queue, Stream, Schedule retry
let attempts = 0;
const flaky = Effect.suspend(() => {
  attempts++;
  return attempts < 3 ? Effect.fail("not yet") : Effect.succeed("ok after " + attempts);
});
const asyncProgram = Effect.gen(function* () {
  const fiber = yield* Effect.forkChild(Effect.sleep("20 millis").pipe(Effect.as("slow")));
  const fast = yield* Effect.sleep("5 millis").pipe(Effect.as("fast"));
  const slow = yield* Fiber.join(fiber);
  const queue = yield* Queue.unbounded();
  yield* Queue.offerAll(queue, [1, 2, 3]);
  const taken = yield* Queue.takeN(queue, 2);
  const streamed = yield* Stream.fromIterable([1, 2, 3, 4, 5, 6]).pipe(
    Stream.filter((n) => n % 2 === 0),
    Stream.map((n) => n * 10),
    Stream.runCollect,
  );
  const retried = yield* flaky.pipe(Effect.retry(Schedule.recurs(5)));
  const raced = yield* Effect.race(Effect.sleep("30 millis").pipe(Effect.as("tortoise")), Effect.sleep("10 millis").pipe(Effect.as("hare")));
  const timedOut = yield* Effect.sleep("50 millis").pipe(Effect.timeoutOption("10 millis"));
  return { fast, slow, taken: [...taken], streamed: [...streamed], retried, raced, timedOut: Option.isNone(timedOut) };
});
Effect.runPromise(asyncProgram).then(
  (result) => {
    log("async", result);
    console.log(out.join("\n"));
  },
  (error) => {
    log("async.error", String(error));
    console.log(out.join("\n"));
  },
);
