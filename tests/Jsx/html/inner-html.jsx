/** @jsxImportSource phasis */
export default function App() {
  return (
    <div>
      <div dangerouslySetInnerHTML={{ __html: '<b>bold</b> & raw' }} />
      <div dangerouslySetInnerHTML={{ __html: '' }}>fallback child</div>
      <div dangerouslySetInnerHTML={null}>child</div>
      <script dangerouslySetInnerHTML={{ __html: 'var a = 1 < 2;' }} />
      <script>{'if (a < b) {}'}</script>
    </div>
  );
}
