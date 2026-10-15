// Shapes that precompile turns into templates: nested static HTML with
// holes at different tree positions, text next to holes, attribute holes,
// event handlers, a suspending hole, and useId inside holes.
import { useId, Suspense, use } from 'react';
function Id({ label }) { const id = useId(); return <i data-id={id}>{label}</i>; }
function Twice() { return [<Id key="a" label="x" />, <Id key="b" label="y" />]; }
const late = new Promise((r) => setTimeout(() => r('late value'), 2));
function Late() { return <em>{use(late)}</em>; }
export default function App({ name, n, href, cls }) {
  return (
    <div className="root">
      <h1>Hello {name}!</h1>
      <p>{n} items and {n * 2} halves</p>
      <p>text<Id label="inline" />more</p>
      <section className={cls} data-n={n} onClick={() => {}} hidden={n > 10} id="s">
        <ul>
          <li><Id label="one" /></li>
          <li>two <b>bold {name}</b> <Twice /></li>
          <li>{null}{false}tail</li>
        </ul>
        <a href={href}>empty href</a>
        <a href="">static empty href</a>
        <br />
        <span>{'a'}{'b'}</span>
      </section>
      <div>
        <Suspense fallback={<p>loading <Id label="fallback" /></p>}>
          <p>before <Late /> after</p>
        </Suspense>
      </div>
      <footer><small>© {2026}</small></footer>
    </div>
  );
}
export const props = { name: 'Ada', n: 3, href: '', cls: 'box' };
