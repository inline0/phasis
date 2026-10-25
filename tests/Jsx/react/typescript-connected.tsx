import { createContext, useContext, useState } from 'react';
import { Title, type Named } from '../typescript/Title';
import type { Missing } from './type-only-module-that-does-not-exist';
type Props = { greeting: string };
type Identity = <T>(value: T) => T;
const Greeting = createContext<string>('default');
const identity: Identity = <T,>(value: T): T => value;
function Child({ greeting }: Props) {
  const context = useContext<string>(Greeting);
  const [user] = useState<Named>({ name: 'Ada' });
  return <section data-greeting={greeting satisfies string}>
    <Title<Named> user={user}><em>{identity(context)}</em></Title>
    <p>(parentheses): type User = string</p>
    <p>{'as const <T> interface User'}</p>
  </section>;
}
export const props: Props = { greeting: 'Hello' };
export default function App({ greeting }: Props) {
  return <Greeting value={greeting}><Child greeting={greeting} /></Greeting>;
}
