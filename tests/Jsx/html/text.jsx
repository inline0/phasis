/** @jsxImportSource phasis */
export default function App() {
  return (
    <div>
      <p>{'a < b & "c" > \'d\''}</p>
      <p>{0}{-0}{1e21}{0.1 + 0.2}{NaN}{12345678901234567890n}</p>
      <p>{null}{undefined}{true}{false}{''}text</p>
      <p>{'a'}{'b'} {'c'}</p>
      {[1, [2, [3, null, false]], 'x']}
      <>frag {'ment'}</>
    </div>
  );
}
