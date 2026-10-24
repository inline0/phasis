const realSetTimeout = globalThis.setTimeout;
const realClearTimeout = globalThis.clearTimeout;
export const monotonicNow = globalThis.performance ? globalThis.performance.now.bind(globalThis.performance) : Date.now;

export const defaultTestTimeout = 5_000;
export const defaultHookTimeout = 10_000;

export function parseTimeoutScale(value) {
  const source = String(value).trim();
  if (!/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/.test(source)) {
    throw new Error('The timeout scale must be a finite positive number');
  }
  const scale = Number(source);
  if (!Number.isFinite(scale) || scale <= 0 || scale > 100) {
    throw new Error('The timeout scale must be finite, greater than 0 and at most 100');
  }
  return scale;
}

export const timeoutScale = parseTimeoutScale(globalThis.process?.env?.PHASIS_FRAMEWORK_TIMEOUT_SCALE ?? '1');

export function scaledTimeout(originalTimeout, scale = timeoutScale) {
  if (originalTimeout <= 0 || originalTimeout === Infinity) return originalTimeout;
  const effectiveTimeout = originalTimeout * scale;
  if (!Number.isFinite(effectiveTimeout) || effectiveTimeout <= 0 || effectiveTimeout > 2_147_483_647) {
    throw new Error('The effective timeout must be finite, positive and at most 2147483647ms');
  }
  return effectiveTimeout;
}

export function timeoutSettings(originalTimeout) {
  const effectiveTimeout = scaledTimeout(originalTimeout);
  return {
    originalTimeout: originalTimeout === Infinity ? 'Infinity' : originalTimeout,
    effectiveTimeout: effectiveTimeout === Infinity ? 'Infinity' : effectiveTimeout,
  };
}

// Vitest 5 checks elapsed time even when synchronous work or microtasks starve timers.
export function withDeadline(action, originalTimeout, isHook = false, abort) {
  const timeout = scaledTimeout(originalTimeout);
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
      error.originalTimeout = originalTimeout;
      error.effectiveTimeout = timeout;
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
