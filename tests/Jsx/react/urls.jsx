export default function App() {
  return (
    <div>
      <a href="javascript:alert(1)">a</a>
      <a href={'  JAVA\nSCRIPT:void(0)'}>b</a>
      <form action="javascript:x()"><button formAction="javascript:y()">s</button></form>
      <iframe src="javascript:z()" />
      <object data="javascript:w()" />
      <a href="https://example.com/?q=javascript:">ok</a>
      <svg><a xlinkHref="javascript:v()">x</a></svg>
    </div>
  );
}
