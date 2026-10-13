import { Activity, StrictMode, Profiler } from 'react';
export default function App() {
  return (
    <StrictMode>
      <Profiler id="p" onRender={() => {}}>
        <div>
          <Activity mode="visible"><p>shown</p></Activity>
          <Activity mode="hidden"><p>hidden</p></Activity>
          <Activity><p>default</p></Activity>
          text
        </div>
      </Profiler>
    </StrictMode>
  );
}
