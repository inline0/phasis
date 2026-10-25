import type { ReactNode } from 'react';
export interface Named { name: string }
type Props<T extends Named> = { user: T; children?: ReactNode };
export function Title<T extends Named>({ user, children }: Props<T>) {
  return <h2 title="type T = string">{user.name}{children}</h2>;
}
