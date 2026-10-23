const realSetTimeout = globalThis.setTimeout;
const realClearTimeout = globalThis.clearTimeout;
export const monotonicNow = globalThis.performance ? globalThis.performance.now.bind(globalThis.performance) : Date.now;

export const defaultTestTimeout = 5_000;
export const defaultHookTimeout = 10_000;

// Vitest 5 checks elapsed time even when synchronous work or microtasks starve timers.
export function withDeadline(action, timeout, isHook = false, abort) {
  if (timeout <= 0 || timeout === Infinity) return action();
  return new Promise((resolve, reject) => {
    const end = monotonicNow() + timeout;
    let settled = false;
    const timer = realSetTimeout(expired, timeout);
    function expired() {
      if (settled) return;
      settled = true;
      realClearTimeout(timer);
      const kind = isHook ? 'Hook' : 'Test';
      const error = new Error(`${kind} timed out in ${timeout}ms.\nIf this is a long-running ${kind.toLowerCase()}, pass a timeout value as the last argument or configure it globally with "${isHook ? 'hookTimeout' : 'testTimeout'}".`);
      try {
        abort?.(error);
      } catch (cause) {
        error.cause = cause;
      } finally {
        reject(error);
      }
    }
    function complete(value) {
      if (settled) return;
      if (monotonicNow() >= end) return expired();
      settled = true;
      realClearTimeout(timer);
      resolve(value);
    }
    function failed(error) {
      if (settled) return;
      settled = true;
      realClearTimeout(timer);
      reject(error);
    }
    try {
      const result = action();
      if (typeof result === 'object' && result !== null && typeof result.then === 'function') result.then(complete, failed);
      else complete(result);
    } catch (error) {
      failed(error);
    }
  });
}
