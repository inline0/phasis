// TypeScript-looking text inside data must never be erased.
type Text = string;
const literal: Text = "interface User<T> { value: number }; x as const";
const regex: RegExp = /(?<type>as|satisfies|interface):\s*<T>/g;
const template: string = `type X = ${literal}; as string!`;
console.log(literal, regex.test("as: <T>"), template);
// These are object keys and labels, not type syntax.
const names = { type: 1, interface: 2, as: 3, satisfies: 4, readonly: 5 };
const { type, as: renamed }: { type: number; as: number } = names;
console.log(type, renamed, names.interface, names.satisfies, names.readonly);
let hits: number = 0;
const object = { get value(): number { hits++; return 8; } };
console.log(object.value as number, hits);
const left = 2, middle = 3, right = 4;
console.log(left < middle, middle > left, left < middle && middle < right);
console.log((left as number) + middle * right, left + middle as number, (left < middle) as boolean);
console.log((object as {value: number}).value, hits);
console.log(8 >> 1, 8 >>> 1, 1 << 3, !false, !object!.value);
const conditional = (x: number): number => x ? 7 : 9;
console.log(conditional(1), conditional(0));
console.log(left < middle > +right, left < middle > -right);
console.log(left < middle > !right);
console.log(1 as number < 2);
type Nested<T> = T;
console.log(1 as Nested<Nested<number>>==1, 1 as Nested<Nested<number>>===1);
const genericValue: number = 8;
console.log(genericValue<number> / 2);
console.log(genericValue! / 2, genericValue!! / 2);
const optionalCall = (value: number): number => value + 1;
console.log(optionalCall?.<number>(41));
console.log(! /x/.test('x'), genericValue!
  / 2);
