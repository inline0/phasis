export default function App() {
  return (
    <form method="post" action="/submit" encType="multipart/form-data" target="_self">
      <input name="a" value="1" />
      <input name="b" defaultValue="2" />
      <input type="checkbox" defaultChecked />
      <input type="radio" checked={false} />
      <textarea defaultValue={'\nline'} />
      <textarea>{'child text'}</textarea>
      <select value="b" onChange={() => {}}>
        <option value="a">A</option>
        <option value="b">B</option>
        <option>b</option>
      </select>
      <select multiple defaultValue={['x', 'z']}>
        <option value="x">X</option>
        <option value="y">Y</option>
        <option value="z" selected>Z</option>
      </select>
      <select>
        <option selected>plain</option>
        <optgroup label="g"><option value={1}>{'one'}</option></optgroup>
      </select>
      <select value="2"><option>{1}{2}</option><option>{2}</option></select>
      <button name="go" value="1" formMethod="get">Go</button>
      <pre>{'\nleading'}</pre>
      <pre dangerouslySetInnerHTML={{ __html: '\nraw' }} />
    </form>
  );
}
