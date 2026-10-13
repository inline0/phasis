import { createPortal } from 'react-dom';
export default function App() { return <div>{createPortal(<p>x</p>, { nodeType: 1 })}</div>; }
