/** @jsxImportSource phasis */
export default function App() {
  return (
    <form>
      <textarea value={'a & <b>'} />
      <textarea defaultValue="dv">ignored child</textarea>
      <select value="b">
        <option value="a">A</option>
        <option value="b">B</option>
        <option value="b" selected={false}>B2</option>
      </select>
      <select value={2}>
        <option value="2">loose equality</option>
        <option value={2}>number</option>
      </select>
      <select>
        <option>none</option>
      </select>
    </form>
  );
}
