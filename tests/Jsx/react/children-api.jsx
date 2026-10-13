import { Children, cloneElement, isValidElement, createElement } from 'react';
function List({ children }) {
  return (
    <ul data-count={Children.count(children)}>
      {Children.map(children, (c, i) => isValidElement(c) ? cloneElement(c, { 'data-i': i, key: 'k' + i }) : <li>{c}</li>)}
      {Children.toArray(children).map((c) => (isValidElement(c) ? <li key={c.key}>{c.key}</li> : null))}
    </ul>
  );
}
function Only({ children }) { return Children.only(children); }
export default function App() {
  const nested = [<li key="x">x</li>, [<li key="y">y</li>, 'text', null, false]];
  return (
    <div>
      <List>{nested}<li>plain</li>{'str'}{5}</List>
      <Only><span>only</span></Only>
      {cloneElement(<a href="/a">a</a>, { href: '/b' }, 'new', 'children')}
      {createElement('p', { key: 'z', ref: null }, ...['1', '2'])}
    </div>
  );
}
