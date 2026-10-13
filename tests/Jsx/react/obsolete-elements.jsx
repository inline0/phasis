// Elements the HTML parser drops or browsers no longer know: React's server
// renderer still serializes them, so the bytes are pinned, but there is no
// valid DOM for the client to hydrate.
export default function App() {
  return (
    <div>
      <col /><keygen /><menuitem label="m" />
      <td>cell outside a table</td>
    </div>
  );
}
export const hydrate = false;
