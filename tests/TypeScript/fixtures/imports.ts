import type Missing from './never-executed.ts';
import type { Another } from './also-never-executed.ts';
export type { More } from './third-never-executed.ts'
import { value, type Shape, type as renamed } from '../modules/values.ts';
import { type Shape as Again } from '../modules/values.ts';
export { value, type Shape } from '../modules/values.ts';
console.log(value, renamed, globalThis.typeScriptSideEffects);
export type Alias = Shape;
import type AliasOnly = require('never-loaded-alias');
import type { Qualified } from 'never-loaded-with' with { 'resolution-mode': 'import' };
export type { Other } from 'never-loaded-reexport' with { 'resolution-mode': 'import' };
type ImportedType = import('never-loaded-query', { with: { 'resolution-mode': 'import' } }).Value;
type Callback = (this: { value: number }, input: string) => string;
console.log(typeof AliasOnly);
