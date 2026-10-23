import { setTimeout as nativeTimeout } from 'node:timers';
import { withDeadline } from '../deadline.mjs';
const realNow = performance.now.bind(performance);
const delay = duration => new Promise(resolve => nativeTimeout(resolve, duration));
const pending = () => new Promise(() => {});
const busy = duration => {
  const end = realNow() + duration;
  while (realNow() < end) {}
};

afterEach(() => vi.useRealTimers());
test('ordinary completion', () => expect(1).toBe(1));
test('async deadline', pending, 30);
test('synchronous deadline', () => busy(70), 30);
test('microtask deadline', () => Promise.resolve().then(() => busy(70)), 30);
test('options deadline', { timeout: 30 }, pending);
test('disabled deadline', () => delay(40), 0);
test('infinite deadline', { timeout: Infinity }, () => delay(40));
test('abort failures remain visible without losing completion', async () => {
  await expect(withDeadline(pending, 30, false, () => { throw new Error('abort failed'); }))
    .rejects.toMatchObject({ message: expect.stringContaining('Test timed out in 30ms.'),
      cause: { message: 'abort failed' } });
});
test('fake timers preserve real deadline', { timeout: 30 }, () => {
  vi.useFakeTimers();
  return pending();
});
test('late synchronous errors remain original errors', () => {
  busy(70);
  throw new Error('original synchronous error');
}, 30);
describe('inherited deadline', { timeout: 30 }, () => {
  test('inherits suite limit', pending);
});
test.each([1])('parameterized deadline %s', { timeout: 30 }, pending);
describe('beforeEach limit', () => {
  beforeEach(pending, 30);
  test('reports hook timeout', () => expect.unreachable());
});
describe('beforeAll limit', () => {
  beforeAll(pending, 30);
  test('reports setup timeout', () => expect.unreachable());
});
describe('cleanup limit', () => {
  beforeEach(() => pending, 30);
  test('reports cleanup timeout', () => expect(1).toBe(1));
});
