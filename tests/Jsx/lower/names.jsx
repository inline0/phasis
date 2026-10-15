const Foo = { Bar: { Baz: function Baz() {} } };
function _x() {}
function $y() {}
<my-el />;
<Foo.Bar.Baz />;
<_x />;
<$y />;
<svg:rect />;
<Ünï />;
function Ünï() {}
const o = { Comp: function Comp() {}, render() { return <this.Comp />; } };
o.render();
