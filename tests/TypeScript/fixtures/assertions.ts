const original = { value: 42 };
const alias = <{ value: number }>original;
console.log(alias === original, <number>alias.value);
console.log((<number>alias.value) + 1);
