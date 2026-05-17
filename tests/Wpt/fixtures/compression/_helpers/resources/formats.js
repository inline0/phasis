// Resolved formats list for the headless runner. Mirrors upstream
// minus "brotli" — PHP's stock zlib stack covers gzip / deflate /
// deflate-raw natively; ext-brotli is an optional extension we
// don't pull in.
const formats = [
  "deflate",
  "deflate-raw",
  "gzip",
];
