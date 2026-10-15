export default function App({ name, count }) {
  return (
    <div>
      <p>Hello {name}</p>
      <p>{count} items, {count * 2} halves {'and'} {null}{false}{undefined}{true} done</p>
      <span>{'a'}{'b'}{''}{'c'}</span>
      <span>{0}{-0}{1e21}{0.1 + 0.2}{12345678901234567890n}</span>
      {'root text'}{' more'}
    </div>
  );
}
export const props = { name: 'Dennis', count: 3 };
