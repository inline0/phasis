import { html, isSafeHtml } from 'remix/html-template';

export function run() {
  const name = '<script>"&</script>';
  const item = html`<strong>${name}</strong>`;
  const list = html`<ul>${[html`<li>one</li>`, html`<li>${name}</li>`]}</ul>`;
  return { value: String(item), nested: String(html`<main>${item}${list}</main>`), safe: isSafeHtml(item), unsafe: isSafeHtml(name) };
}
