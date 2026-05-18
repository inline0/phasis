// chai — assertion library. We exercise expect/should/assert APIs
// and observe error messages on assertion failures. Chai's error
// shape is part of its public contract.
const chai = Chai.default;
const { expect, assert } = chai;

const out = [];
const log = (k, v) => out.push(k + " " + JSON.stringify(v));

// Passing assertions return undefined; we run a bunch in a row
// just to be sure nothing throws on the happy path.
let passes = 0;
function pass(label, fn) {
  try { fn(); passes++; }
  catch (e) { log("FAIL " + label, e.message); }
}

pass("equal", () => expect(1 + 1).to.equal(2));
pass("eql", () => expect({ a: 1 }).to.eql({ a: 1 }));
pass("deep", () => expect([1, 2, 3]).to.deep.equal([1, 2, 3]));
pass("includes", () => expect([1, 2, 3]).to.include(2));
pass("length", () => expect("phasis").to.have.lengthOf(6));
pass("property", () => expect({ x: 1, y: 2 }).to.have.property("x", 1));
pass("nested", () => expect({ a: { b: { c: 5 } } }).to.have.nested.property("a.b.c", 5));
pass("instanceof", () => expect([]).to.be.an.instanceof(Array));
pass("type", () => expect("hi").to.be.a("string"));
pass("above", () => expect(10).to.be.above(5));
pass("below", () => expect(1).to.be.below(2));
pass("within", () => expect(5).to.be.within(1, 10));
pass("oneOf", () => expect(2).to.be.oneOf([1, 2, 3]));
pass("match", () => expect("phasis").to.match(/^pha/));
pass("startsWith", () => expect("phasis").to.have.string("asi"));
pass("truthy", () => expect(1).to.be.ok);
pass("null", () => expect(null).to.be.null);
pass("undefined", () => { let x; expect(x).to.be.undefined; });
pass("empty", () => expect([]).to.be.empty);
pass("members", () => expect([1, 2, 3]).to.include.members([1, 2]));
pass("keys", () => expect({ a: 1, b: 2 }).to.have.all.keys("a", "b"));
pass("assert.equal", () => assert.equal(2 + 2, 4));
pass("assert.deepEqual", () => assert.deepEqual({ x: 1 }, { x: 1 }));
pass("assert.isArray", () => assert.isArray([1, 2, 3]));
pass("assert.isFunction", () => assert.isFunction(() => 1));
log("passing", passes);

// Failing assertions: we capture the message shape (chai's
// AssertionError message is part of its public contract).
function fail(label, fn) {
  try { fn(); log(label, "NOTHROWN"); }
  catch (e) { log(label, e.message); }
}

fail("fail.equal", () => expect(1).to.equal(2));
fail("fail.deep", () => expect([1, 2]).to.deep.equal([1, 3]));
fail("fail.property", () => expect({ x: 1 }).to.have.property("y"));
fail("fail.above", () => expect(1).to.be.above(5));
fail("fail.empty", () => expect([1]).to.be.empty);
fail("fail.type", () => expect(1).to.be.a("string"));

// chai.use() plugin contract — register a simple helper, use it.
function helperPlugin(_chai, utils) {
  utils.addMethod(_chai.Assertion.prototype, "halfOf", function (target) {
    const obj = utils.flag(this, "object");
    new _chai.Assertion(obj * 2).to.equal(target);
  });
}
chai.use(helperPlugin);
pass("plugin", () => expect(5).to.halfOf(10));
fail("plugin.fail", () => expect(5).to.halfOf(11));

console.log(out.join("\n"));
