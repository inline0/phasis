import { AsyncLocalStorage } from 'node:async_hooks';
import * as chai from 'chai';
import {
  ASYMMETRIC_MATCHERS_OBJECT, GLOBAL_EXPECT, JestAsymmetricMatchers,
  JestChaiExpect, JestExtend, ChaiStyleAssertions, customMatchers, getState, setState,
} from '@vitest/expect';
import { fn, spyOn, clearAllMocks, resetAllMocks, restoreAllMocks } from '@vitest/spy';
import { expectTypeOf } from 'expect-type';
import fakeTimers from '@sinonjs/fake-timers';
import { format } from '@vitest/pretty-format';
import { defaultTestTimeout, defaultHookTimeout, withDeadline, monotonicNow } from './deadline.mjs';

chai.use(JestExtend);
chai.use(JestChaiExpect);
chai.use(JestAsymmetricMatchers);
chai.use(ChaiStyleAssertions);
let activeTest;
export const expect = Object.assign((value, message) => {
  const state = getState(expect);
  setState({ assertionCalls: state.assertionCalls + 1 }, expect);
  const assertion = chai.expect(value, message);
  return activeTest ? assertion.withTest(activeTest) : assertion;
}, chai.expect, globalThis[ASYMMETRIC_MATCHERS_OBJECT]);
globalThis[GLOBAL_EXPECT] = expect;
setState({ assertionCalls: 0, currentTestName: '', isExpectingAssertions: false, expectedAssertionsNumber: null }, expect);
expect.getState = () => getState(expect);
expect.setState = state => setState(state, expect);
expect.extend = matchers => chai.expect.extend(expect, matchers);
expect.extend(customMatchers);
expect.assertions = count => setState({ expectedAssertionsNumber: count }, expect);
expect.hasAssertions = () => setState({ isExpectingAssertions: true }, expect);
expect.unreachable = message => { throw new Error(message || 'Expected code to be unreachable'); };
expect.soft = (value, message) => expect(value, message).withContext({ soft: true });
expect.extend({
  toMatchInlineSnapshot(received, expected) {
    if (typeof expected !== 'string') unsupported('property snapshots and snapshot updates');
    const actual = format(received, { escapeRegex: true, printFunctionName: false });
    return { pass: actual === expected, message: () => `Snapshot mismatch\nExpected: ${expected}\nReceived: ${actual}` };
  },
  toThrowErrorMatchingInlineSnapshot(received, expected) {
    if (this.promise !== 'rejects') {
      if (typeof received !== 'function') throw new TypeError('Expected a function for error snapshot');
      try { received(); } catch (error) { return this.equals(format(error, { escapeRegex: true, printFunctionName: false }), expected)
        ? { pass: true, message: () => 'Expected the function not to throw the snapshot error' }
        : { pass: false, message: () => `Error snapshot mismatch: ${format(error)} !== ${expected}` }; }
      return { pass: false, message: () => 'Expected the function to throw' };
    }
    const actual = format(received, { escapeRegex: true, printFunctionName: false });
    return { pass: actual === expected, message: () => `Error snapshot mismatch: ${actual} !== ${expected}` };
  },
});
export { expectTypeOf };

const root = { name: '', children: [], beforeAll: [], afterAll: [], beforeEach: [], afterEach: [], parent: null, options: {} };
const collectionContext = new AsyncLocalStorage();
const pendingCollections = [];
const suiteErrors = [];
const currentSuite = () => collectionContext.getStore() || root;
let nextId = 0;
class HarnessUnsupportedError extends Error {}
class SkippedTest extends Error {}
const unsupported = name => { throw new HarnessUnsupportedError('Harness support required: ' + name); };
function caseName(template, args, index) {
  let position = 0;
  return template.replace(/%[%sdifjo#]|\$[\w.]+/g, token => {
    if (token === '%%') return '%';
    if (token === '%#') return String(index);
    if (token[0] === '$') {
      if ((args[0] === null || typeof args[0] !== 'object') && !/^\d+$/.test(token.slice(1))) return token;
      let value = args[0];
      for (const key of token.slice(1).split('.')) value = value?.[key];
      return String(value);
    }
    const value = args[position++];
    return token === '%j' || token === '%o' ? JSON.stringify(value) : String(value);
  });
}
function eachTable(table, ...values) {
  if (!Array.isArray(table) || !Object.hasOwn(table, 'raw')) return table;
  const headings = table[0].trim().split('|').map(value => value.trim());
  const rows = [];
  for (let i = 0; i < values.length; i += headings.length) {
    const row = {};
    for (let j = 0; j < headings.length; j++) row[headings[j]] = values[i + j];
    rows.push(row);
  }
  return rows;
}
function registration(kind, skipped = false) {
  const register = (name, optionsOrAction, timeoutOrAction) => {
    const { options, action } = parseArguments(optionsOrAction, timeoutOrAction);
    const suite = currentSuite();
    const entry = { name: String(name), id: nextId++, skipped: skipped || suite.skipped, parent: suite, suite: suite.parent ? suite : undefined,
      options: { ...suite.options, ...options } };
    if (kind === 'suite') {
      Object.assign(entry, { children: [], beforeAll: [], afterAll: [], beforeEach: [], afterEach: [] });
      suite.children.push(entry);
      const collecting = collectionContext.run(entry, action);
      if (collecting && typeof collecting.then === 'function') pendingCollections.push(collecting);
    } else {
      entry.action = action;
      suite.children.push(entry);
    }
  };
  register.each = (table, ...values) => (name, optionsOrAction, timeoutOrAction) => {
    const { options, action } = parseArguments(optionsOrAction, timeoutOrAction);
    eachTable(table, ...values).forEach((row, index) => {
      const args = Array.isArray(row) ? row : [row];
      register(caseName(name, args, index), options, () => action(...args));
    });
  };
  register.skipIf = condition => registration(kind, skipped || Boolean(condition));
  register.runIf = condition => registration(kind, skipped || !condition);
  Object.defineProperty(register, 'skip', { get: () => registration(kind, true) });
  Object.defineProperty(register, 'only', { get: () => unsupported('exclusive test selection') });
  register.todo = name => registration(kind, true)(name, () => {});
  return register;
}
function parseArguments(optionsOrAction, timeoutOrAction) {
  if (timeoutOrAction !== null && typeof timeoutOrAction === 'object') {
    throw new TypeError('Pass test options as the second argument, as required by Vitest 5.');
  }
  if (typeof optionsOrAction === 'function' && typeof timeoutOrAction === 'function') {
    throw new TypeError('Cannot use two functions as test arguments.');
  }
  return {
    action: typeof optionsOrAction === 'function' ? optionsOrAction : timeoutOrAction,
    options: typeof timeoutOrAction === 'number' ? { timeout: timeoutOrAction }
      : optionsOrAction !== null && typeof optionsOrAction === 'object' ? optionsOrAction : {},
  };
}
export const describe = registration('suite');
export const test = registration('test');
export const it = test;
const hook = kind => (action, timeout = defaultHookTimeout) => currentSuite()[kind].push({ action, timeout: timeout ?? defaultHookTimeout });
export const beforeAll = hook('beforeAll');
export const afterAll = hook('afterAll');
export const beforeEach = hook('beforeEach');
export const afterEach = hook('afterEach');
const savedGlobals = new Map();
const savedEnv = new Map();
let fakeClock;
export const vi = {
  fn, spyOn, clearAllMocks, resetAllMocks, restoreAllMocks,
  stubGlobal(name, value) {
    if (!savedGlobals.has(name)) savedGlobals.set(name, Object.getOwnPropertyDescriptor(globalThis, name));
    Object.defineProperty(globalThis, name, { configurable: true, writable: true, enumerable: true, value });
    return vi;
  },
  unstubAllGlobals() {
    for (const [name, descriptor] of savedGlobals) {
      if (descriptor) Object.defineProperty(globalThis, name, descriptor);
      else delete globalThis[name];
    }
    savedGlobals.clear();
    return vi;
  },
  stubEnv(name, value) {
    if (typeof process === 'undefined') unsupported('process.env');
    if (!savedEnv.has(name)) savedEnv.set(name, Object.getOwnPropertyDescriptor(process.env, name));
    if (value === undefined) delete process.env[name]; else process.env[name] = String(value);
    return vi;
  },
  unstubAllEnvs() {
    for (const [name, descriptor] of savedEnv) {
      if (descriptor) Object.defineProperty(process.env, name, descriptor);
      else delete process.env[name];
    }
    savedEnv.clear();
    return vi;
  },
  useFakeTimers(options = {}) {
    fakeClock ||= fakeTimers.withGlobal(globalThis).install({
      ...options,
      toFake: options.toFake || Object.keys(fakeTimers.timers).filter(name => name !== 'nextTick' && name !== 'queueMicrotask'),
    });
    return vi;
  },
  setSystemTime(value) { if (!fakeClock) vi.useFakeTimers(); fakeClock.setSystemTime(value); return vi; },
  useRealTimers() { fakeClock?.uninstall(); fakeClock = undefined; return vi; },
  resetModules() {
    if (typeof globalThis.__honoResetModules !== 'function') unsupported('module-cache reset');
    globalThis.__honoResetModules();
    return vi;
  },
};
Object.assign(globalThis, { describe, test, it, beforeAll, afterAll, beforeEach, afterEach, expect, expectTypeOf, vi });
function fullName(entry) {
  const names = [];
  for (let current = entry; current?.parent; current = current.parent) names.unshift(current.name);
  return names.join(' > ');
}
function failure(error, depth = 0) {
  return { name: error?.name || 'Error', message: String(error?.message ?? error), stack: String(error?.stack || ''),
    ...(error?.cause !== undefined && depth < 3 ? { cause: failure(error.cause, depth + 1) } : {}) };
}
async function invoke(hook, context) {
  const cleanup = await withDeadline(() => hook.action(context), hook.timeout, true, error => context.abort?.(error));
  return typeof cleanup === 'function' ? { action: () => cleanup(), timeout: hook.timeout } : undefined;
}
async function runCase(entry, ancestors, results, inheritedError) {
  const started = monotonicNow();
  const record = { id: entry.id, name: fullName(entry), status: 'pass', assertions: 0 };
  globalThis.__honoCase?.(JSON.stringify({ id: record.id, name: record.name }));
  if (entry.skipped) {
    record.status = 'upstream-skip';
    results.push(record);
    return;
  }
  if (inheritedError) {
    record.status = 'suite-blocked';
    record.error = failure(inheritedError);
    results.push(record);
    return;
  }
  const task = { name: entry.name, suite: entry.parent, promises: [], onFinished: [], result: { state: 'run', errors: [] } };
  const controller = new AbortController();
  const context = { expect, task, signal: controller.signal, abort: error => controller.abort(error),
    skip: reason => { throw new SkippedTest(String(reason || 'Skipped by upstream test')); } };
  activeTest = task;
  setState({ assertionCalls: 0, currentTestName: record.name, isExpectingAssertions: false, expectedAssertionsNumber: null }, expect);
  const cleanups = [];
  let problem = inheritedError;
  try {
    if (problem) throw problem;
    for (const ancestor of ancestors) {
      for (const hook of ancestor.beforeEach) {
        const cleanup = await invoke(hook, context);
        if (cleanup) cleanups.push(cleanup);
      }
    }
    if (typeof entry.action !== 'function') throw new Error('Test has no callback');
    await withDeadline(async () => {
      await entry.action(context);
      await Promise.all(task.promises);
    }, entry.options.timeout ?? defaultTestTimeout, false, context.abort);
    for (const complete of task.onFinished) await withDeadline(complete, defaultHookTimeout, true, context.abort);
    if (task.result.errors.length) throw new Error(task.result.errors.map(error => error.message).join('\n'));
    const state = getState(expect);
    if (state.isExpectingAssertions && state.assertionCalls === 0) throw new Error('Expected at least one assertion');
    if (state.expectedAssertionsNumber !== null && state.expectedAssertionsNumber !== state.assertionCalls) {
      throw new Error(`Expected ${state.expectedAssertionsNumber} assertions, got ${state.assertionCalls}`);
    }
  } catch (error) { problem = error; }
  for (const ancestor of [...ancestors].reverse()) {
    for (const hook of [...ancestor.afterEach].reverse()) {
      try { await invoke(hook, context); } catch (error) { problem ||= error; }
    }
  }
  for (const cleanup of cleanups.reverse()) {
    try { await invoke(cleanup, context); } catch (error) { problem ||= error; }
  }
  record.assertions = getState(expect).assertionCalls;
  record.durationMs = monotonicNow() - started;
  if (problem) {
    record.status = problem instanceof SkippedTest ? 'upstream-skip' : problem instanceof HarnessUnsupportedError ? 'harness-blocked' : 'fail';
    record.error = failure(problem);
  }
  results.push(record);
  globalThis.__honoResult?.(JSON.stringify(record));
  activeTest = undefined;
}
async function runSuite(current, ancestors, results, inheritedError) {
  const lineage = [...ancestors, current];
  const cleanups = [];
  let problem = inheritedError;
  if (!current.skipped && !problem) {
    try {
      for (const hook of current.beforeAll) {
        const cleanup = await invoke(hook, {});
        if (cleanup) cleanups.push(cleanup);
      }
    } catch (error) {
      problem = error;
      suiteErrors.push({ name: fullName(current) + ' > beforeAll', error: failure(error) });
    }
  }
  for (const child of current.children) {
    if (child.children) await runSuite(child, lineage, results, problem);
    else await runCase(child, lineage, results, problem);
  }
  if (!current.skipped) {
    for (const hook of [...current.afterAll].reverse()) {
      try { await invoke(hook, {}); } catch (error) { suiteErrors.push({ name: fullName(current) + ' > afterAll', error: failure(error) }); }
    }
    for (const cleanup of cleanups.reverse()) {
      try { await invoke(cleanup, {}); } catch (error) { suiteErrors.push({ name: fullName(current) + ' > beforeAll cleanup', error: failure(error) }); }
    }
  }
}
export async function run() {
  // Vitest starts test execution after asynchronous module collection.
  await Promise.resolve();
  while (pendingCollections.length) await Promise.all(pendingCollections.splice(0));
  const results = [];
  await runSuite(root, [], results);
  const counts = { pass: 0, fail: 0, 'upstream-skip': 0, 'harness-blocked': 0, 'suite-blocked': 0 };
  for (const result of results) counts[result.status]++;
  return { counts, results, suiteErrors };
}
