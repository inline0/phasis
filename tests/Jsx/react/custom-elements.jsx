export default function App() {
  return (
    <div>
      <my-element className="c" foo="bar" flag={true} off={false} num={3} obj={{ a: 1 }} fn={() => {}} style={{ color: 'blue' }} htmlFor="x" onClick={() => {}}>
        <span>child</span>
      </my-element>
      <x-y dangerouslySetInnerHTML={{ __html: '<b>raw</b>' }} />
      <font-face src="x" />
    </div>
  );
}
