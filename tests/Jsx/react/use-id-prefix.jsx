import { useId } from 'react';
export default function App() { const id = useId(); return <p id={id}>x</p>; }
export const options = { identifierPrefix: 'app-' };
