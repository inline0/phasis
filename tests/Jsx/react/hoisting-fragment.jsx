export default function App() {
  return (
    <article>
      <title>Hoisted title</title>
      <meta name="description" content="desc" />
      <link rel="canonical" href="https://example.com/" />
      <link rel="stylesheet" href="/a.css" precedence="default" />
      <script async src="/s.js" />
      <style href="s1" precedence="low">{'p{color:red}'}</style>
      <p>text</p>
      <svg><title>svg title</title></svg>
    </article>
  );
}
