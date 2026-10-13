// Attributes React's server and client treat differently, so React's own
// client reports a mismatch against React's own server HTML: `muted` is
// rendered but not reflected as a DOM property, and unsafe attribute names
// are dropped by the server and flagged by the development client.
export default function App() {
  return (
    <div>
      <video muted src="/v.mp4" />
      <div {...{ 'bad name': 1, '<x': 2, 'ok-name': 3 }} />
    </div>
  );
}
export const hydrate = false;
