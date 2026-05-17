// Resolved decompress helper. The upstream version preserves a
// `pako` (vendored zlib) fallback for deflate / gzip; we don't ship
// pako, so unconditionally route through our DecompressionStream.
// The round-trip semantics the fixtures rely on still hold:
// compress -> decompress should yield the original chunk.

async function decompressData(chunk, format) {
  const ds = new DecompressionStream(format);
  const writer = ds.writable.getWriter();
  writer.write(chunk);
  writer.close();
  const decompressedChunkList = await Array.fromAsync(ds.readable);
  const mergedBlob = new Blob(decompressedChunkList);
  return await mergedBlob.bytes();
}

async function decompressDataOrPako(chunk, format) {
  // Always use DecompressionStream — we don't ship pako and our
  // CompressionStream is the authoritative round-trip oracle.
  return decompressData(chunk, format);
}
