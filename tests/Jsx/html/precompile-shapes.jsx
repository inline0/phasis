/** @jsxImportSource phasis */
// Shapes that precompile turns into templates in the HTML profile.
import { use } from 'phasis';
function Badge({ label }) { return <b class="badge">{label}</b>; }
const late = new Promise((r) => setTimeout(() => r('late'), 2));
function Late() { return <em>{use(late)}</em>; }
function Wrap({ children }) { return <div class="wrap">{children}</div>; }
export default function App({ name, n, cls }) {
  return (
    <div className="root">
      <h1>Hello {name}!</h1>
      <p>{n} items <Badge label="new" /> and more</p>
      <section className={cls} data-n={n} tabIndex={n} onClick={() => {}} hidden={false} style={{ color: 'red', marginTop: n }}>
        <ul><li>one</li><li>two <span>{name}</span></li><li>{null}tail</li></ul>
        <hr /><br />
        <p htmlFor="x" readOnly>attrs</p>
      </section>
      <Wrap><p>wrapped <Late /> text</p></Wrap>
    </div>
  );
}
export const props = { name: 'Ada <3', n: 4, cls: 'box' };
