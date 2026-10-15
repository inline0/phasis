const text = '雪😀<&'.repeat(1800);
export default function LargeChunks() {
  return <article><span>{text}</span><span>{text}</span></article>;
}
