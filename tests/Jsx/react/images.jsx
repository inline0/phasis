export default function App() {
  return (
    <div>
      {Array.from({ length: 12 }, (_, i) => <img key={i} src={'/img' + i + '.png'} />)}
      <img src="/lazy.png" loading="lazy" />
      <img src="/low.png" fetchPriority="low" />
      <img src="data:image/png;base64,xx" />
      <img srcSet="/a.png 1x, /b.png 2x" sizes="100vw" />
      <img src="/high.png" fetchPriority="high" />
      <picture><source srcSet="/p.webp" /><img src="/p.png" /></picture>
      <img src="/img0.png" />
    </div>
  );
}
