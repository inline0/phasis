// Parentheses end an optional chain: in (a?.b).c a short-circuit of a?.b
// does not skip .c, which then reads from undefined. Errors print their
// type only.
const out = [];
const t = (label, f) => { try { const v = f(); out.push(label + " => " + (typeof v === "function" ? "function" : String(v))); } catch (e) { out.push(label + " => " + e.constructor.name); } };
const n = null;
const u = undefined;
const o = { b: { c: 1, f() { return this === o.b; } }, g() { return this === o; }, s: "str" };
t("(n?.b).c", () => (n?.b).c);
t("(n?.b)", () => (n?.b));
t("(n?.b.c).d", () => (n?.b.c).d);
t("(n?.b)?.c", () => (n?.b)?.c);
t("((n?.b)).c", () => ((n?.b)).c);
t("(n?.b)[0]", () => (n?.b)[0]);
t("(n?.b)()", () => (n?.b)());
t("(n?.())", () => (n?.()));
t("(n?.()).x", () => (n?.()).x);
t("(n?.b).c()", () => (n?.b).c());
t("(u?.b).c", () => (u?.b).c);
t("(o?.b).c", () => (o?.b).c);
t("(o?.b).f()", () => (o?.b).f());
t("(o?.g)()", () => (o?.g)());
t("(o?.b?.f)()", () => (o?.b?.f)());
t("(o.b?.c).toFixed(1)", () => (o.b?.c).toFixed(1));
t("delete (n?.b).c", () => delete (n?.b).c);
t("delete (n?.b)", () => delete (n?.b));
t("typeof (n?.b).c", () => typeof (n?.b).c);
t("(n?.b)`x`", () => (n?.b)`x`);
t("new (n?.b)()", () => new (n?.b)());
t("(n?.b) ?? 5", () => (n?.b) ?? 5);
t("n?.b.c (no parens)", () => n?.b.c);
t("n?.b() (no parens)", () => n?.b());
t("(n?.[0]).x", () => (n?.[0]).x);
t("(o?.s).length", () => (o?.s).length);
t("(o.missing?.x).y", () => (o.missing?.x).y);
t("x = (n?.b), x?.c", () => { const x = (n?.b); return x?.c; });
console.log(out.join("\n"));
const o2 = { b: {} };
(o2?.b).c = 5;
[(o2?.b).d, o2.b.e] = [6, 7];
({ x: (o2?.b).f } = { x: 8 });
console.log(JSON.stringify(o2));
let threw = 0;
try { (n?.b).c = 1; } catch (e) { threw += e instanceof TypeError; }
try { (n?.b).c++; } catch (e) { threw += e instanceof TypeError; }
console.log(threw);
for (const src of ["(a?.b) = 1", "[a?.b] = []", "[(a?.b)] = []", "({x: a?.b} = {})", "a?.b`x`", "(a?.b)++"]) {
  try { new Function(src); console.log(src, "parsed"); } catch (e) { console.log(src, e.constructor.name); }
}
console.log(typeof new Function("return (a?.b)`x`"));
