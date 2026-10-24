'use strict';
const Stream = require('./node_modules/readable-stream/lib/stream.js');
const process = require('process');
const { Buffer } = require('buffer');
Stream.setDefaultHighWaterMark(false, process.platform === 'win32' ? 16384 : 65536);

function invalidStream(name) { return new TypeError(`The "${name}" argument must be a stream.`); }
Stream.Readable.fromWeb = function (web, options = {}) {
  if (!web || typeof web.getReader !== 'function') throw invalidStream('readableStream');
  const reader = web.getReader();
  let pending = false;
  let ended = false;
  let released = false;
  // Node's adapter owns the reader lock even after reaching EOF.
  const release = () => { released = true; };
  const readable = new Stream.Readable({
    ...options,
    read() {
      if (pending || ended) return;
      pending = true;
      reader.read().then(({ value, done }) => {
        pending = false;
        if (done) { ended = true; release(); this.push(null); }
        else this.push(value);
      }, (error) => { pending = false; this.destroy(error); });
    },
    destroy(error, callback) {
      if (ended || released) { callback(error); return; }
      ended = true;
      Promise.resolve(reader.cancel(error)).then(() => { release(); callback(error); }, (reason) => { release(); callback(reason || error); });
    },
  });
  return readable;
};
Stream.Readable.toWeb = function (readable, options = {}) {
  if (!readable || typeof readable.on !== 'function' || typeof readable.pause !== 'function') throw invalidStream('streamReadable');
  let controller;
  let ended = false;
  function cleanup() {
    readable.removeListener('data', data);
    readable.removeListener('end', end);
    readable.removeListener('error', error);
    readable.removeListener('close', close);
  }
  function data(chunk) {
    if (ended) return;
    controller.enqueue(Buffer.isBuffer(chunk) ? new Uint8Array(chunk) : chunk);
    if (controller.desiredSize <= 0) readable.pause();
  }
  function end() { if (ended) return; ended = true; cleanup(); controller.close(); }
  function error(reason) { if (ended) return; ended = true; cleanup(); controller.error(reason); }
  function close() {
    if (ended) return;
    if (readable.readableEnded) end();
    else { const reason = new Error('Premature close'); reason.code = 'ERR_STREAM_PREMATURE_CLOSE'; error(reason); }
  }
  return new ReadableStream({
    start(value) {
      controller = value;
      readable.on('data', data);
      readable.on('end', end);
      readable.on('error', error);
      readable.on('close', close);
      readable.pause();
      if (readable.readableEnded) end();
      else if (readable.destroyed) close();
    },
    pull() { if (!ended) readable.resume(); },
    cancel(reason) {
      ended = true;
      cleanup();
      readable.once('error', () => {});
      readable.destroy(reason);
    },
  }, { highWaterMark: readable.readableHighWaterMark, ...options.strategy });
};
Stream.Writable.fromWeb = function (web, options = {}) {
  if (!web || typeof web.getWriter !== 'function') throw invalidStream('writableStream');
  const writer = web.getWriter();
  let ended = false;
  let released = false;
  // Node retains the writer lock for the lifetime of this adapter.
  const release = () => { released = true; };
  return new Stream.Writable({
    ...options,
    write(chunk, encoding, callback) { Promise.resolve(writer.write(chunk)).then(() => callback(), callback); },
    final(callback) {
      Promise.resolve(writer.close()).then(() => { ended = true; release(); callback(); }, callback);
    },
    destroy(error, callback) {
      if (ended || released) { callback(error); return; }
      ended = true;
      Promise.resolve(writer.abort(error)).then(() => { release(); callback(error); }, (reason) => { release(); callback(reason || error); });
    },
  });
};
Stream.Writable.toWeb = function (writable) {
  if (!writable || typeof writable.write !== 'function' || typeof writable.on !== 'function') throw invalidStream('streamWritable');
  let controller;
  let rejectPending;
  let ended = false;
  function error(reason) { if (!ended) controller.error(reason); rejectPending?.(reason); rejectPending = undefined; }
  function cleanup() { writable.removeListener('error', error); }
  return new WritableStream({
    start(value) { controller = value; writable.on('error', error); if (writable.errored) error(writable.errored); },
    write(chunk) {
      return new Promise((resolve, reject) => {
        rejectPending = reject;
        writable.write(chunk, (reason) => { rejectPending = undefined; reason ? reject(reason) : resolve(); });
      });
    },
    close() {
      return new Promise((resolve, reject) => {
        rejectPending = reject;
        writable.end((reason) => { ended = true; rejectPending = undefined; cleanup(); reason ? reject(reason) : resolve(); });
      });
    },
    abort(reason) {
      ended = true;
      cleanup();
      writable.once('error', () => {});
      writable.destroy(reason);
    },
  }, { highWaterMark: writable.writableHighWaterMark });
};
Stream.Duplex.fromWeb = function (pair, options = {}) {
  if (!pair || !pair.readable || !pair.writable) throw invalidStream('pair');
  return Stream.Duplex.from({ readable: Stream.Readable.fromWeb(pair.readable, options), writable: Stream.Writable.fromWeb(pair.writable, options) });
};
Stream.Duplex.toWeb = function (duplex) {
  return { readable: Stream.Readable.toWeb(duplex), writable: Stream.Writable.toWeb(duplex) };
};
module.exports = Stream;
