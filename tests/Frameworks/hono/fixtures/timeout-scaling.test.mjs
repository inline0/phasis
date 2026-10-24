import { setTimeout as nativeTimeout } from 'node:timers';
import { parseTimeoutScale, scaledTimeout, timeoutScale, withDeadline } from '../deadline.mjs';
const realNow = performance.now.bind(performance);
const delay = duration => new Promise(resolve => nativeTimeout(resolve, duration));
const busy = duration => {
  const end = realNow() + duration;
  while (realNow() < end) {}
};

test('scale validation and timer bounds', () => {
  expect(parseTimeoutScale('2.5')).toBe(2.5);
  for (const value of ['', '0', '-1', 'NaN', 'Infinity', '1e500', '1e-500', '1_0', '0x10', '101', '1e6']) {
    expect(() => parseTimeoutScale(value)).toThrow();
  }
  expect(() => scaledTimeout(1e9, 10)).toThrow();
  expect(() => scaledTimeout(Number.MIN_VALUE, .5)).toThrow();
  expect(scaledTimeout(0, 10)).toBe(0);
  expect(scaledTimeout(Infinity, 10)).toBe(Infinity);
});
test('scaled deadlines remain enforced', async () => {
  for (const action of [() => delay(60), () => busy(60)]) {
    await expect(withDeadline(action, 1)).rejects.toMatchObject({
      originalTimeout: 1,
      effectiveTimeout: timeoutScale,
    });
  }
});
test('explicit awaited deadline', () => delay(60), 20);
test('explicit elapsed deadline', { timeout: 20 }, () => busy(60));
test('disabled deadline stays disabled', () => delay(30), 0);
test('infinite deadline stays disabled', { timeout: Infinity }, () => delay(30));
describe('scaled beforeEach', () => {
  beforeEach(() => busy(60), 20);
  test('hook completes within effective deadline', () => expect(true).toBe(true));
});
describe('scaled beforeAll', () => {
  beforeAll(() => delay(60), 20);
  test('setup completes within effective deadline', () => expect(true).toBe(true));
});
describe('scaled cleanup', () => {
  beforeEach(() => () => delay(60), 20);
  test('cleanup completes within effective deadline', () => expect(true).toBe(true));
});
