// Class fields of a class with an implicit constructor, reached as the
// super() of a subclass (Effect's Data.TaggedError has this shape).
const results = [];
// 1. field on a class with an implicit ctor, extending native Error directly
class A1 extends Error { tag = "a1"; }
results.push(["A1", new A1().tag]);
// 2. one more implicit level below it
class A2 extends A1 {}
results.push(["A2", new A2().tag]);
// 3. explicit-ctor base between Error and the field class
class E extends Error { constructor(a) { super(a?.message); if (a) Object.assign(this, a); } }
class B1 extends E { tag = "b1"; }
results.push(["B1", new B1({ id: 1 }).tag]);
class B2 extends B1 {}
results.push(["B2", new B2({ id: 1 }).tag, new B2({ id: 1 }).id]);
// 4. implicit class between Error and explicit base (YieldableError shape)
class Y extends Error {}
class E2 extends Y { constructor(a) { super(a?.message); } }
class C1 extends E2 { tag = "c1"; }
class C2 extends C1 {}
results.push(["C1", new C1({}).tag], ["C2", new C2({}).tag]);
// 5. class returned from a function (TaggedError factory shape), in an IIFE
const make = (tag) => { class Base extends E2 { _tag = tag; } Base.prototype.name = tag; return Base; };
class D extends make("D") {}
results.push(["D", new D({}).tag ?? new D({})._tag]);
// 6. no Error at the root
class P0 { constructor(a) {} }
class P1 extends P0 { tag = "p1"; }
class P2 extends P1 {}
results.push(["P2", new P2({}).tag]);
console.log(JSON.stringify(results));

const r = [];
const order = [];
class Root { constructor() { order.push("root"); } }
class L1 extends Root { a = order.push("L1.a"); #p = "L1#p"; #m() { return "L1#m"; } get p() { return this.#p + "/" + this.#m(); } }
class L2 extends L1 { b = order.push("L2.b"); }
class L3 extends L2 { constructor() { super(); order.push("L3.body"); } c = order.push("L3.c"); }
class L4 extends L3 {}
const x = new L4();
r.push(["order", order.join(",")], ["private", x.p], ["keys", Object.keys(x)]);
// private brand check and double-construction
class Brand extends Root { #x = 1; static has(o) { return #x in o; } }
class BrandSub extends Brand {}
r.push(["brand", Brand.has(new BrandSub()), Brand.has({})]);
// return override from the root
const shared = { shared: true };
class Ret { constructor() { return shared; } }
class RetMid extends Ret { f = "field"; }
class RetLeaf extends RetMid {}
const ro = new RetLeaf();
r.push(["override", ro === shared, ro.f]);
// builtin parents
class MyArr extends Array { extra = "x"; }
class MyArr2 extends MyArr {}
const arr = new MyArr2(); arr.push(1, 2);
r.push(["array", arr.length, arr.extra, Array.isArray(arr)]);
class MyMap extends Map { tag = "map"; }
class MyMap2 extends MyMap {}
r.push(["map", new MyMap2([[1, 2]]).tag, new MyMap2([[1, 2]]).get(1)]);
// this in initializer, arrow capture, new.target in field
class T0 { constructor(v) { this.v = v; } }
class T1 extends T0 { doubled = this.v * 2; getV = () => this.v; nt = new.target; }
class T2 extends T1 {}
const t = new T2(21);
r.push(["this", t.doubled, t.getV(), t.nt === undefined]);
// Reflect.construct with a different newTarget
class N0 {}
class N1 extends N0 { n1 = 1; }
class N2 extends N1 {}
class Other {}
const rc = Reflect.construct(N2, [], Other);
r.push(["reflect", rc.n1, Object.getPrototypeOf(rc) === Other.prototype]);
// static fields untouched, instance counts
let count = 0;
class S0 {}
class S1 extends S0 { k = ++count; static s = "static"; }
class S2 extends S1 {}
new S2(); new S2();
r.push(["count", count, S2.s]);
// private method install twice must not throw
class PM0 {}
class PM1 extends PM0 { #m() { return 1; } call() { return this.#m(); } }
class PM2 extends PM1 {}
class PM3 extends PM2 { constructor() { super(); } }
r.push(["pm", new PM2().call(), new PM3().call()]);
console.log(JSON.stringify(r));
