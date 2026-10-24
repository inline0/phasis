'use strict';
const { Readable, Writable } = require('stream');
const { Buffer } = require('buffer');
const host = __phasisNodeHost;

class WriteStream extends Writable {
  constructor(fd, options) {
    super({ ...options, autoDestroy: false });
    if (fd !== 1 && fd !== 2) throw Object.assign(new Error('Unsupported standard stream descriptor'), { code: 'EBADF' });
    this.fd = fd;
    this.isTTY = host.isatty(fd);
  }
  _write(chunk, encoding, callback) {
    try {
      if (!Buffer.isBuffer(chunk)) chunk = Buffer.from(chunk, encoding);
      let offset = 0;
      while (offset < chunk.length) {
        const count = host.stdioWrite(this.fd, chunk.subarray(offset));
        if (!count) throw Object.assign(new Error('Standard stream write made no progress'), { code: 'EIO' });
        offset += count;
      }
      callback();
    } catch (error) { callback(error); }
  }
}
class ReadStream extends Readable {
  constructor(fd = 0, options) {
    super(options);
    if (fd !== 0) throw Object.assign(new Error('Unsupported standard input descriptor'), { code: 'EBADF' });
    this.fd = fd;
    this.isTTY = host.isatty(fd);
    this.isRaw = false;
    this._timer = null;
  }
  _read() {
    const data = host.stdioRead(this.fd);
    if (data === undefined) this._timer = setTimeout(() => { this._timer = null; this._read(); }, 10);
    else this.push(data === null ? null : Buffer.from(data));
  }
  _destroy(error, callback) { if (this._timer) clearTimeout(this._timer); callback(error); }
  setRawMode() { throw Object.assign(new Error('Raw terminal mode is unavailable through PHP streams'), { code: 'ERR_FEATURE_UNAVAILABLE_ON_PLATFORM' }); }
}
module.exports = { WriteStream, ReadStream };
