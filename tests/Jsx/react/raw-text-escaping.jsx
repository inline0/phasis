// React escapes `</script` and `</style` inside script and style children
// on the server, so the client sees different text: React itself cannot
// hydrate these without a mismatch.
export default function App() {
  return (
    <div>
      <script>{'if (a < b) { console.log("</script>", "<SCRIPT>"); }'}</script>
      <style>{'.a::after{content:"</style><STYLE>"}'}</style>
    </div>
  );
}
export const hydrate = false;
