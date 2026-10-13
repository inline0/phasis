export default function App() {
  const s = `<script>alert("x") & 'y'</script>`;
  return (
    <div title={s} data-x={s}>
      {s}
      <a href={'/q?a=1&b="2"'}>link</a>
      <textarea value={s} readOnly />
      <input defaultValue={s} />
    </div>
  );
}
