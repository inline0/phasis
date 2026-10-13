/** @jsxImportSource phasis */
export default function App() {
  return (
    <div className="c" htmlFor="f" tabIndex={2} readOnly maxLength={5} autoFocus contentEditable="true" id="" title={'x"<&>'}>
      <input disabled={true} checked={false} value={null} placeholder={undefined} onClick={() => {}} required="" />
      <label htmlFor="x" for="y">both</label>
      <div class="literal" className="ignored" />
      <div aria-hidden={true} aria-busy={false} data-flag={true} data-off={false} draggable={false} spellcheck={true} />
      <div accessKey="k" autoComplete="off" cellPadding={1} colSpan={2} crossOrigin="" dateTime="t" encType="e" formAction="/a" frameBorder="0" hrefLang="en" inputMode="text" minLength={1} noValidate playsInline popoverTarget="p" rowSpan={3} srcSet="a 1x" useMap="#m" itemProp="name" />
      <div {...{ 'a b': 1, 'c=d': 2, "e'f": 3, ok: 4 }} />
      <meta acceptCharset="utf-8" httpEquiv="refresh" />
      <input defaultValue="dv" defaultChecked />
      <option defaultSelected>o</option>
      <a href="javascript:alert(1)">kept as written</a>
      <div title={{ toString() { return 'obj'; } }} num={1.5} />
    </div>
  );
}
