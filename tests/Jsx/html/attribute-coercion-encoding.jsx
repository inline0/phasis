/** @jsxImportSource phasis */
import { renderToString } from 'phasis';
let calls = 0;
const object = { toString() { calls++; return '"<>&'; } };
const primitive = { valueOf() { return '"<probe>&'; }, toString() { throw new Error('wrong hint'); } };
const array = JSON.parse('{"title":["\\\"><x-probe>&"]}').title;
const nested = [['"<>&'], ['tail']];
const html = renderToString(<section>
  <div title={array}/>
  <div className={nested}/>
  <svg viewBox={nested}/>
  <div title={object}/>
  <div title={primitive}/>
</section>);
export default function Probe() {
  return <article data-coercions={calls}><pre>{html}</pre></article>;
}
