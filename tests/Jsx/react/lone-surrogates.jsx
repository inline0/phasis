export const hydrate = false;

const text = 'a😊b𠀀c';

export default function LoneSurrogates() {
  return (
    <div title={text.slice(0, 2)} data-low={text.slice(2, 4)}>
      <p>{text.slice(0, 2)}</p>
      <p>{text.slice(2, 5)}</p>
      <p>{'\uD800'}{'\uDC00'}</p>
      <p>{'\uDC00\uD800'}</p>
      <p>{'x\uD83D'}{'\uDE0Ay'}</p>
      <span>{text.split('').join('|')}</span>
    </div>
  );
}
