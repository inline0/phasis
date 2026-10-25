type NumberPromise = Promise<number>;
async function one<T>(value: T): Promise<T> { return value; }
const two = async (value: number): NumberPromise => await one<number>(value);
const three = async <T,>(value: T): Promise<T> => await one<T>(value);
console.log(await two(2), await three<string>('three'));
function* sequence(): Generator<number, void, unknown> { yield 1; yield 2; }
console.log([...sequence()].join(','));
