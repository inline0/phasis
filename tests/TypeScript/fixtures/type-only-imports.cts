import type NeverLoaded from './missing-type-only-module.ts';
export type Shape = { value: number };
console.log('commonjs', typeof module, typeof NeverLoaded);
