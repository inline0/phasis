import { useId } from 'react';
function Field({ label }) {
  const id = useId();
  const id2 = useId();
  return <label htmlFor={id} data-second={id2}>{label}<input id={id} /></label>;
}
function Wrapper({ children }) { return <section>{children}</section>; }
function NoId({ children }) { return children; }
export default function App() {
  const top = useId();
  return (
    <div id={top}>
      <Field label="a" />
      <Field label="b" />
      <Wrapper><Field label="c" /></Wrapper>
      <NoId><NoId><Field label="d" /></NoId></NoId>
      {[1, 2, 3].map((i) => <Field key={i} label={'l' + i} />)}
      <>
        <Field label="frag" />
        {null}
        <Field label="frag2" />
      </>
    </div>
  );
}
