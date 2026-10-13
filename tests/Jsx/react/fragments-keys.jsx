import { Fragment } from 'react';
export default function App() {
  const items = ['a', 'b', 'c'];
  return (
    <ul>
      <>
        <li>first</li>
        {items.map((x) => <li key={x}>{x}</li>)}
      </>
      {items.map((x) => (
        <Fragment key={x}><li>{x}1</li><li>{x}2</li></Fragment>
      ))}
      {[[1, 2], [3, [4, 5]]]}
      {new Set(['s1', 's2'])}
      {(function* () { yield 'g1'; yield <b key="k">g2</b>; })()}
    </ul>
  );
}
