import { createHtmlResponse } from 'remix/response/html';
import { redirect } from 'remix/response/redirect';

export async function run() {
  const response = createHtmlResponse('<h1>Hello</h1>', { status: 201, headers: { 'x-test': 'yes' } });
  const moved = redirect('/next', 303);
  return { status: response.status, type: response.headers.get('content-type'), body: await response.text(),
    header: response.headers.get('x-test'), redirect: moved.status, location: moved.headers.get('location') };
}
