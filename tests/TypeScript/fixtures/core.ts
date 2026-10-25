type Primitive = string | number | boolean | null | undefined;
type Pair<T> = readonly [name: T, count?: number, ...rest: T[]];
type MapValues<T> = { readonly [K in keyof T as K]?: T[K] };
type Choice<T> = T extends infer R ? R : never;
type Factory = <T>(value: T) => T;
type Template<T extends string> = `hello ${T}`;
interface Named { name: string }
interface User<T = string> extends Named { value: T; optional?: number; transform<R>(x: R): R; }
declare const absent: number;
const typed: { name: string; value: number } = {name: 'Ada', value: 41};
function identity<T extends unknown = unknown>(value: T): T { return value; }
const arrow = <T,>(value: T): T => value;
const sum = (a: number, b?: number): number => a + (b ?? 0);
function collect(this: { kind: string }, ...values: number[]): string { return this.kind + values.join(','); }
let optional: string | undefined;
const data = typed satisfies { name: string };
const frozen = [1, 2, 3] as const;
const asserted = typed.value as number;
console.log(identity<number>(41) + 1, arrow<string>(typed.name), sum(40, 2));
console.log(collect.call({kind:'n:'}, 1, 2), typeof optional, typeof absent);
console.log(data === typed, frozen.join('-'), asserted, typed!.name);
console.log(identity.length, arrow.length, sum.length, collect.length);
function named(readonly: string): string { return readonly; }
console.log(named('ordinary-name'));
