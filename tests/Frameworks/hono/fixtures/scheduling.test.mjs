const trace = [];
let index = 0;
const schedule = phase => {
  trace.push(phase);
  process.nextTick(() => trace.push(phase + ':nextTick'));
  Promise.resolve().then(() => trace.push(phase + ':promise'));
  queueMicrotask(() => trace.push(phase + ':microtask'));
  setImmediate(() => trace.push(phase + ':immediate'));
};
beforeEach(() => schedule('before' + (++index)));
afterEach(() => schedule('after' + index));
it('synchronous lifecycle', () => {
  schedule('test1');
  expect(index).toBe(1);
});
it('promise lifecycle', async () => {
  schedule('test2');
  await Promise.resolve();
  trace.push('await2');
  expect(index).toBe(2);
});
it('explicit host turn', async () => {
  schedule('test3');
  await new Promise(resolve => setImmediate(resolve));
  trace.push('await3');
  expect(index).toBe(3);
});
afterAll(() => process.stdout.write('__PHASIS_HONO_SCHEDULE__' + JSON.stringify(trace) + '\n'));
