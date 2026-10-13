import { ViewTransition, Suspense } from 'react';
export default function App() {
  return (
    <div>
      <ViewTransition><p>auto named</p></ViewTransition>
      <ViewTransition name="hero" enter="slide" exit="fade" update="none"><img src="/h.png" /></ViewTransition>
      <ViewTransition default="x"><Suspense fallback="f"><b>c</b></Suspense></ViewTransition>
    </div>
  );
}
