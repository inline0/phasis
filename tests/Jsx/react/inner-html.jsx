export default function App() {
  return (
    <div>
      <div dangerouslySetInnerHTML={{ __html: '<b>bold</b> & <i>it</i>' }} />
      <span dangerouslySetInnerHTML={{ __html: null }} />
      <script dangerouslySetInnerHTML={{ __html: 'var a = 1;' }} />
      <style dangerouslySetInnerHTML={{ __html: 'a{color:red}' }} />
      <script>{'if (a < b) { console.log("ok"); }'}</script>
      <style>{'.a::after{content:"x"}'}</style>
      <noscript><img src="/n.png" /></noscript>
    </div>
  );
}
