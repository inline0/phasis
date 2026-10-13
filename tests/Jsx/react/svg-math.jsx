export default function App() {
  return (
    <div>
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="icon">
        <title>Icon title</title>
        <path d="M12 2 L2 7" />
        <circle cx="12" cy="12" r="3" />
        <foreignObject><div>html inside</div></foreignObject>
        <use xlinkHref="#sym" />
      </svg>
      <math><mi>x</mi><mo>=</mo><mn>2</mn></math>
    </div>
  );
}
