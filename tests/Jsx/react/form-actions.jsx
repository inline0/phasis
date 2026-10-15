export default function App() {
  return (
    <div>
      <form action={() => {}}><input name="x" /></form>
      <form action={async () => {}}><button formAction={() => {}}>b</button></form>
    </div>
  );
}
