export default function App() {
  return (
    <div className="c" tabIndex={-1} htmlFor="x" acceptCharset="utf-8" httpEquiv="refresh" role="main" dir="ltr">
      <input type="checkbox" checked readOnly disabled autoFocus multiple={false} required={0} />
      <button type="button" hidden={false} disabled={null} onClick={() => {}}>b</button>
      <div aria-hidden={true} aria-label="lbl" data-flag={false} data-on={true} data-n={7} draggable={true} spellCheck={false} contentEditable="true" />
      <video autoPlay controls loop playsInline src="/v.mp4" />
      <table><tbody><tr><td colSpan={2} rowSpan={0} /></tr></tbody></table>
      <ol start={0} reversed />
      <select size={0} />
      <textarea cols={10} rows="abc" />
      <a download href="/f">dl</a>
      <a download="file.txt" href="/f">dl2</a>
      <img src="" alt="" />
      <a href="">empty</a>
      <div unknownAttr="u" fooBar={1} xyz={true} ref={{ current: null }} suppressHydrationWarning />
      <svg viewBox="0 0 10 10" width={10} height="10" strokeWidth={2} fillOpacity={0.5} xlinkHref="#a" xmlLang="en" xmlnsXlink="http://www.w3.org/1999/xlink" />
      <meta itemProp="name" content="x" />
    </div>
  );
}
