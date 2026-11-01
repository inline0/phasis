export const props = { "emoji": "smile 😊 x", "family": "👨‍👩‍👧‍👦", "flag": "🇩🇪🇯🇵", "cjk": "𠀀𩸽𪚲", "mixed": "é雪😀<&\"", "keycap": "1️⃣#️⃣", "skin": "👍🏽", "list": ["🎉", "🧪", "𝒳"] };

export default function UnicodeProps({ emoji, family, flag, cjk, mixed, keycap, skin, list }) {
  const head = emoji.slice(0, 6);
  return (
    <section title={emoji} data-family={family} aria-label={flag}>
      <p>{emoji}|{head}|{emoji.slice(6)}|{emoji.length}</p>
      <p>{family}{flag}{cjk}</p>
      <p data-mixed={mixed}>{mixed}</p>
      <p>{keycap}{skin}{Array.from(cjk).reverse().join('')}</p>
      <ul>{list.map((item) => <li key={item} title={item}>{item}{item.codePointAt(0).toString(16)}</li>)}</ul>
      <input defaultValue={family} placeholder={cjk} />
      <textarea defaultValue={emoji} />
      <p>{'lit 😊 \u{1F600} 😀'}</p>
    </section>
  );
}
