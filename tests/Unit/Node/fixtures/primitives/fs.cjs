'use strict';
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { pipeline } = require('node:stream/promises');
const { Transform } = require('node:stream');
const { once } = require('node:events');
const { promisify } = require('node:util');

require('./helpers.cjs').run(async () => {
  const output = [];
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'phasis-fs-oracle-'));
  const file = path.join(directory, 'bytes');
  try {
    fs.writeFileSync(file, Buffer.from([0, 1, 127, 128, 254, 255]));
    output.push(['binary', fs.readFileSync(file).toString('hex'), fs.readFileSync(new URL('file://' + file)).equals(fs.readFileSync(Buffer.from(file)))]);
    fs.writeFileSync(file, 'hello 😀', 'utf8');
    fs.appendFileSync(file, '!');
    output.push(['text', fs.readFileSync(file, 'utf8'), fs.readFileSync(file).length]);

    const fd = fs.openSync(file, 'r+');
    const backing = Buffer.alloc(8, 46);
    const destination = new Uint8Array(backing.buffer, backing.byteOffset + 2, 4);
    const positional = fs.readSync(fd, destination, 1, 2, 1);
    const sequential = Buffer.alloc(2);
    fs.readSync(fd, sequential, 0, 2, null);
    fs.writeSync(fd, 'X', 0, 'utf8');
    const rest = fs.readFileSync(fd, 'utf8');
    output.push(['descriptor', positional, backing.toString('hex'), sequential.toString(), rest, fs.readFileSync(file, 'utf8')]);
    fs.ftruncateSync(fd, 5);
    fs.fsyncSync(fd);
    fs.closeSync(fd);
    try { fs.closeSync(fd); } catch (error) { output.push(['closed', error.code, error.syscall]); }

    const before = fs.statSync(file);
    fs.utimesSync(file, 1000000000, new Date(1000000020000));
    const stat = fs.statSync(file);
    const big = fs.statSync(file, { bigint: true });
    output.push(['stat', stat instanceof fs.Stats, stat.isFile(), stat.isDirectory(), stat.size, stat.mtimeMs, stat.atime instanceof Date, typeof big.size, typeof big.mtimeNs, before.mode & 0o777]);
    const missing = path.join(directory, 'missing');
    output.push(['missing', fs.existsSync(missing), fs.statSync(missing, { throwIfNoEntry: false }) === undefined]);
    for (const [operation, fn] of [['stat', () => fs.statSync(missing)], ['open', () => fs.openSync(missing)], ['read', () => fs.readFileSync(directory)], ['exclusive', () => fs.writeFileSync(file, 'no', { flag: 'wx' })]]) {
      try { fn(); } catch (error) { output.push(['error', operation, error.name, error.code, error.syscall]); }
    }

    const child = path.join(directory, 'child', 'nested');
    output.push(['mkdir', fs.mkdirSync(child, { recursive: true }) === path.join(directory, 'child'), fs.mkdirSync(child, { recursive: true }) === undefined]);
    fs.writeFileSync(path.join(child, 'a'), 'A');
    fs.symlinkSync('bytes', path.join(directory, 'link'));
    fs.linkSync(file, path.join(directory, 'hard'));
    output.push(['links', fs.lstatSync(path.join(directory, 'link')).isSymbolicLink(), fs.readlinkSync(path.join(directory, 'link')), fs.realpathSync(path.join(directory, 'link')) === file, fs.statSync(path.join(directory, 'hard')).ino === fs.statSync(file).ino]);
    output.push(['directory', fs.readdirSync(directory, { withFileTypes: true }).map(entry => [entry.name, entry.isDirectory(), entry.isSymbolicLink(), entry.parentPath === directory])]);
    fs.copyFileSync(file, path.join(directory, 'copy'));
    fs.renameSync(path.join(directory, 'copy'), path.join(directory, 'renamed'));
    output.push(['copy', fs.readFileSync(path.join(directory, 'renamed'), 'utf8')]);

    const order = [];
    const asyncFile = path.join(directory, 'async');
    await new Promise((resolve, reject) => {
      fs.writeFile(asyncFile, 'callback', error => { if (error) { reject(error); return; } order.push('callback'); resolve(); });
      order.push('sync');
    });
    output.push(['callback', order, await fs.promises.readFile(asyncFile, 'utf8'), await promisify(fs.readFile)(asyncFile, 'utf8')]);
    const handle = await fs.promises.open(asyncFile, 'r+');
    const target = Buffer.alloc(4);
    const read = await handle.read(target, 1, 2, 2);
    const write = await handle.write('!', 0, 'utf8');
    const closeEvents = [];
    handle.on('close', () => closeEvents.push('close'));
    await handle.close(); await handle.close();
    output.push(['filehandle', read.bytesRead, read.buffer === target, target.toString('hex'), write.bytesWritten, write.buffer, handle.fd, closeEvents]);

    fs.writeFileSync(file, '0123456789');
    const ranged = fs.createReadStream(file, { start: 2, end: 7, highWaterMark: 2 });
    const rangeEvents = [];
    ranged.on('open', () => rangeEvents.push('open'));
    ranged.on('ready', () => rangeEvents.push('ready'));
    ranged.on('end', () => rangeEvents.push('end'));
    ranged.on('close', () => rangeEvents.push('close'));
    const parts = [];
    for await (const part of ranged) parts.push(part);
    output.push(['readstream', Buffer.concat(parts).toString(), ranged.bytesRead, ranged.fd, rangeEvents]);

    const copied = path.join(directory, 'stream-copy');
    const writer = fs.createWriteStream(copied, { highWaterMark: 2 });
    await pipeline(fs.createReadStream(file, { highWaterMark: 2 }), new Transform({ transform(chunk, encoding, done) { done(null, Buffer.from(chunk.toString().replace(/0/g, 'X'))); } }), writer);
    output.push(['pipeline', fs.readFileSync(copied, 'utf8'), writer.bytesWritten, writer.fd]);
    const keptFd = fs.openSync(file, 'r');
    const kept = fs.createReadStream(undefined, { fd: keptFd, autoClose: false, end: 1 });
    for await (const part of kept) {}
    output.push(['kept-fd', fs.fstatSync(keptFd).size]);
    fs.closeSync(keptFd);
    const errored = fs.createReadStream(missing);
    const streamError = await new Promise(resolve => errored.on('error', resolve));
    output.push(['stream-error', streamError.code, errored.destroyed]);

    const dir = await fs.promises.opendir(child);
    const entries = [];
    for await (const entry of dir) entries.push([entry.name, entry.isFile()]);
    try { dir.readSync(); } catch (error) { output.push(['opendir', entries, error.code]); }
    const controller = new AbortController(); controller.abort('stop');
    try { await fs.promises.readFile(file, { signal: controller.signal }); }
    catch (error) { output.push(['abort', error.name, error.code, error.cause]); }
    await fs.promises.writeFile(path.join(directory, 'iterable'), (async function* () { yield 'one'; yield Buffer.from('two'); })());
    output.push(['iterable-write', fs.readFileSync(path.join(directory, 'iterable'), 'utf8')]);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
  return output;
});
