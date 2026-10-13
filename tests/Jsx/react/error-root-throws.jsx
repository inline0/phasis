function Boom() { throw new TypeError('root boom'); }
export default function App() { return <div><Boom /></div>; }
