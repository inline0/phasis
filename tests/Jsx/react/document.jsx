export default function App({ title }) {
  return (
    <html lang="en">
      <head>
        <meta charSet="utf-8" />
        <meta name="viewport" content="width=device-width" />
        <title>{title}</title>
        <link rel="stylesheet" href="/main.css" precedence="default" />
        <link rel="stylesheet" href="/theme.css" precedence="high" />
        <link rel="icon" href="/favicon.ico" />
      </head>
      <body className="page">
        <h1>{title}</h1>
        <img src="/hero.jpg" alt="hero" />
        <script async src="/app.js" />
        <style href="inline-css" precedence="default">{'body{margin:0}'}</style>
        <p>Body text</p>
      </body>
    </html>
  );
}
export const props = { title: 'My Page' };
