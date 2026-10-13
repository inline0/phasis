function Layout() {}
function Item() {}
const items = [{ id: 1, t: "a" }, { id: 2, t: "b" }];
const user = null;
<Layout title="Blog">
  <h1 className="title">Posts &amp; news</h1>
  {items.length === 0 ? <p>None</p> : (
    <ul>{items.map((i) => <Item key={i.id} {...i} />)}</ul>
  )}
  {user && <span>{user}</span>}
  {user ?? <span>guest</span>}
</Layout>;
