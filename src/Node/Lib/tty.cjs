'use strict';
const streams = require('./stdio.cjs');
class WriteStream extends streams.WriteStream {
  clearLine(direction, callback) { return this.write('\x1b[' + (direction < 0 ? '1' : direction > 0 ? '0' : '2') + 'K', callback); }
  clearScreenDown(callback) { return this.write('\x1b[0J', callback); }
  cursorTo(x, y, callback) { if (typeof y === 'function') { callback = y; y = undefined; } return this.write(y === undefined ? `\x1b[${x + 1}G` : `\x1b[${y + 1};${x + 1}H`, callback); }
  moveCursor(dx, dy, callback) { let output = ''; if (dx) output += `\x1b[${Math.abs(dx)}${dx < 0 ? 'D' : 'C'}`; if (dy) output += `\x1b[${Math.abs(dy)}${dy < 0 ? 'A' : 'B'}`; return this.write(output, callback); }
  getColorDepth(env = process.env) { if (env.FORCE_COLOR === '0' || env.NO_COLOR !== undefined || env.NODE_DISABLE_COLORS) return 1; if (env.FORCE_COLOR === '3' || /truecolor|24bit/.test(env.COLORTERM || '')) return 24; if (env.FORCE_COLOR === '2' || /256color/.test(env.TERM || '')) return 8; return this.isTTY || env.FORCE_COLOR ? 4 : 1; }
  hasColors(count = 16, env) { if (typeof count === 'object') { env = count; count = 16; } return count <= 2 ** this.getColorDepth(env); }
}
module.exports = { isatty: fd => typeof fd === 'number' && Number.isInteger(fd) && __phasisNodeHost.isatty(fd), WriteStream, ReadStream: streams.ReadStream };
