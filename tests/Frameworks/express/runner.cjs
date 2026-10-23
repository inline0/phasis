'use strict';

require('mocha/mocha.js');
const Mocha = globalThis.Mocha;

function serializeError(error) {
  return {
    name: error && error.name || 'Error',
    message: error && error.message || String(error),
    stack: error && error.stack || '',
    code: error && error.code,
  };
}

module.exports = function runExpressTests(files, options = {}) {
  const output = { complete: false, discovered: 0, pass: 0, fail: 0, pending: 0, tests: [], loadErrors: [], asynchronousErrors: [] };
  const caseIds = new WeakMap();
  let responses = [];
  const ClientRequest = require('http').ClientRequest;
  const originalEmit = ClientRequest.prototype.emit;
  ClientRequest.prototype.emit = function (event, ...args) {
    if (event === 'response') {
      const response = args[0], socket = response.socket;
      responses.push({ url: this.path, host: this.host, status: response.statusCode, headers: response.headers,
        localAddress: socket.localAddress, localPort: socket.localPort,
        remoteAddress: socket.remoteAddress, remotePort: socket.remotePort });
    }
    return originalEmit.call(this, event, ...args);
  };
  function checkpoint() {
    if (typeof globalThis.__expressProgress === 'function') globalThis.__expressProgress(JSON.stringify(output));
  }
  globalThis.__expressReport = output;
  const mocha = new Mocha({ ui: 'bdd', timeout: options.timeout || 2000, checkLeaks: true, reporter: function Reporter(runner) {
    runner.on('test', test => {
      responses = [];
      if (typeof globalThis.__expressCurrent === 'function') globalThis.__expressCurrent(test.fullTitle(), test.file);
    });
    runner.on('pass', (test) => {
      output.pass++;
      output.tests.push({ id: caseIds.get(test), title: test.fullTitle(), file: test.file, status: 'pass', duration: test.duration });
      if (output.tests.length % 100 === 0) checkpoint();
    });
    runner.on('fail', (test, error) => {
      output.fail++;
      output.tests.push({ id: caseIds.get(test), title: test.fullTitle(), file: test.file, status: 'fail', duration: test.duration, error: serializeError(error), responses });
      checkpoint();
    });
    runner.on('pending', (test) => {
      output.pending++;
      output.tests.push({ id: caseIds.get(test), title: test.fullTitle(), file: test.file, status: 'pending' });
    });
  } });
  const onUncaught = (error) => {
    output.asynchronousErrors.push(serializeError(error));
    if (typeof globalThis.onerror === 'function') globalThis.onerror(error.message, '', 0, 0, error);
  };
  process.on('uncaughtException', onUncaught);
  process.on('unhandledRejection', onUncaught);

  require('./upstream/test/support/env.js');
  for (const file of files) {
    try {
      mocha.suite.emit('pre-require', globalThis, file, mocha);
      const value = require(file);
      mocha.suite.emit('require', value, file, mocha);
      mocha.suite.emit('post-require', globalThis, file, mocha);
    } catch (error) {
      output.loadErrors.push({ file, error: serializeError(error) });
    }
  }
  output.discovered = mocha.suite.total();
  const fileCounts = Object.create(null);
  output.caseManifest = [];
  mocha.suite.eachTest(test => {
    const file = options.fileNames[test.file];
    const id = file + ':' + (fileCounts[file] = (fileCounts[file] || 0) + 1);
    caseIds.set(test, id);
    output.caseManifest.push({ id, title: test.fullTitle() });
  });
  if (JSON.stringify(output.caseManifest) !== JSON.stringify(options.expectedCases)) {
    output.loadErrors.push({ error: { name: 'CaseIdentityMismatch', message: 'The discovered upstream case identities differ from the pinned manifest.' } });
  }
  if (options.expectedTests !== undefined && output.discovered !== options.expectedTests) {
    output.loadErrors.push({ error: { name: 'DiscoveryMismatch', message: `Expected ${options.expectedTests} upstream tests, discovered ${output.discovered}.` } });
  }
  checkpoint();
  if (options.grep) mocha.grep(new RegExp(options.grep));
  mocha.run(() => {
    ClientRequest.prototype.emit = originalEmit;
    process.removeListener('uncaughtException', onUncaught);
    process.removeListener('unhandledRejection', onUncaught);
    output.complete = true;
    if (typeof globalThis.__expressFinished === 'function') globalThis.__expressFinished(JSON.stringify(output));
  });
  return output;
};
