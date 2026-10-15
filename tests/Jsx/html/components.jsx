/** @jsxImportSource phasis */
import { Fragment, createElement } from 'phasis';
function Card({ title, children }) {
  return <section><h2>{title}</h2>{children}</section>;
}
function List({ items }) {
  return <ul>{items.map((item) => <li key={item}>{item}</li>)}</ul>;
}
function Frag() {
  return <><b>one</b><i>two</i></>;
}
function Nothing() { return null; }
function Text() { return 'just text & more'; }
function Num() { return 42; }
export default function App({ title }) {
  return (
    <main>
      <Card title={title}><List items={['a', 'b', 'c']} /></Card>
      <Frag />
      <Fragment key="k"><p>keyed fragment</p></Fragment>
      <Nothing />
      <Text />
      <Num />
      {createElement('p', { className: 'ce' }, 'created', ' ', 'element')}
    </main>
  );
}
export const props = { title: 'Hello <World>' };
