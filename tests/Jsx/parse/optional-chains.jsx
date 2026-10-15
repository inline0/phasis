// ESTree ChainExpression boundaries: a chain ends at its outermost link,
// or where parentheses close it.
a?.b;
a?.b.c.d;
a?.b();
a.b?.();
a?.[0]?.c;
(a?.b).c;
(a?.b)();
(a?.b.c)?.d;
((a?.b)).c;
(a?.b).c = 1;
x = (a?.b)`t`;
<C value={a?.b} onClick={() => (a?.b).c()} />;
