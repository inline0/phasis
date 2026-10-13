import { use } from 'react';
import { browser } from 'react-dom';
function ClientOnly() { use(browser(() => 'lazy reason')); return <p>client</p>; }
export default function App() { return <div><ClientOnly /></div>; }
