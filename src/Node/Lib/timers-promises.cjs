'use strict';

const timers = require('timers');
function abortError(signal) { return Object.assign(new Error('The operation was aborted', { cause: signal && signal.reason }), { name: 'AbortError', code: 'ABORT_ERR' }); }
function optionsValue(options) {
  if (options === undefined) return {};
  if (options === null || typeof options !== 'object') throw Object.assign(new TypeError('options must be an object'), { code: 'ERR_INVALID_ARG_TYPE' });
  if (options.ref !== undefined && typeof options.ref !== 'boolean') throw Object.assign(new TypeError('options.ref must be a boolean'), { code: 'ERR_INVALID_ARG_TYPE' });
  if (options.signal !== undefined && !(options.signal instanceof AbortSignal)) throw Object.assign(new TypeError('options.signal must be an AbortSignal'), { code: 'ERR_INVALID_ARG_TYPE' });
  return options;
}
function wait(kind, delay, value, options) {
  return new Promise((resolve, reject) => {
    options = optionsValue(options);
    const signal = options.signal;
    if (signal && signal.aborted) { reject(abortError(signal)); return; }
    let timer;
    const cleanup = () => { if (signal) signal.removeEventListener('abort', aborted); };
    const aborted = () => { kind === 'immediate' ? timers.clearImmediate(timer) : timers.clearTimeout(timer); cleanup(); reject(abortError(signal)); };
    const done = () => { cleanup(); resolve(value); };
    timer = kind === 'immediate' ? timers.setImmediate(done) : timers.setTimeout(done, delay);
    if (options.ref === false) timer.unref();
    if (signal) signal.addEventListener('abort', aborted, { once: true });
  });
}
async function* interval(delay, value, options) {
  options = optionsValue(options);
  const signal = options.signal;
  if (signal && signal.aborted) throw abortError(signal);
  let available = 0, wake, aborted = false;
  const timer = timers.setInterval(() => { available++; if (wake) { const callback = wake; wake = null; callback(); } }, delay);
  if (options.ref === false) timer.unref();
  const onAbort = () => { aborted = true; if (wake) { const callback = wake; wake = null; callback(); } };
  if (signal) signal.addEventListener('abort', onAbort, { once: true });
  try {
    while (true) {
      if (available === 0 && !aborted) await new Promise(resolve => { wake = resolve; });
      if (aborted) throw abortError(signal);
      available--;
      yield value;
    }
  } finally {
    timers.clearInterval(timer);
    if (signal) signal.removeEventListener('abort', onAbort);
  }
}
module.exports = {
  setTimeout: (delay, value, options) => wait('timeout', delay, value, options),
  setImmediate: (value, options) => wait('immediate', 0, value, options),
  setInterval: interval,
  scheduler: { wait: (delay, options) => wait('timeout', delay, undefined, options), yield: () => wait('immediate') },
};
