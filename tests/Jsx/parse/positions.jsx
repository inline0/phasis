function f() {
  if (a) return <A />;
  const b = c ? <B /> : <C />;
  const d = [<D key="1" />, <E key="2" />];
  return e && <F>{g || <G />}</F>;
}
const h = () => <H />;
const i = { j: <I /> };
export default <J />;