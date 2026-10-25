declare const absent: number
console.log('after ambient', typeof absent);
declare function missing(value: string): void
console.log('after function', typeof missing);
type First = number
console.log('after alias');
interface Second { value: string };
console.log('after interface');
namespace Types { export type Nested = string; export interface Shape { name: string } }
console.log('after namespace', typeof Types);
declare namespace Library {
  export const missing: number;
  export function unavailable(value: string): void;
  export interface Shape { name: string }
}
declare module "type-only-library" { export const value: string; }
console.log('after ambient namespace', typeof Library);
declare enum Status { Ready, Done = 2 }
declare const enum Constant { Ready }
let effects = 0;
function sideEffect() { effects++; return 42; }
declare const initialized: number = sideEffect();
declare class AbsentClass { value = sideEffect(); }
console.log('ambient values', typeof Status, typeof Constant, typeof initialized, typeof AbsentClass, effects);
function identity(value: string): string;
function identity(value: number): number;
function identity(value: string | number): string | number { return value; }
console.log(identity('overload'), identity(42));
abstract class Base {
  abstract missing(): void;
  abstract field: string;
  present(): number { return 42; }
}
class Concrete extends Base { missing(): void {} }
console.log(new Concrete().present(), Object.keys(new Concrete()).join(','));
try { throw 'caught'; } catch (error: unknown) { console.log(error as string); }
