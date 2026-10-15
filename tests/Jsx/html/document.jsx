/** @jsxImportSource phasis */
export default function Email({ name, items }) {
  return (
    <html lang="en">
      <head><meta charset="utf-8" /><title>Hi {name}</title><style>{'td { padding: 0 }'}</style></head>
      <body>
        <table class="newsletter" cellPadding={0} cellSpacing={0} border={0}>
          <tbody>
            <tr><td>Hello {name}</td></tr>
            {items.map((item, i) => <tr key={i}><td align="left" bgcolor="#fff">{item}</td></tr>)}
          </tbody>
        </table>
      </body>
    </html>
  );
}
export const props = { name: 'Ada', items: ['One', 'Two & Three'] };
