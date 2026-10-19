import { detectMimeType, detectContentType, isCompressibleMimeType, mimeTypeToContentType } from 'remix/mime';

export function run() {
  return { html: detectMimeType('index.html'), json: detectContentType('data.json'),
    text: mimeTypeToContentType('text/plain'), compressible: isCompressibleMimeType('application/json'),
    binary: isCompressibleMimeType('image/png') };
}
