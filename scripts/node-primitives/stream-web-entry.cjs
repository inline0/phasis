'use strict';
class TextEncoderStream {
  #stream;
  constructor() {
    const encoder = new TextEncoder();
    let pending = '';
    this.#stream = new TransformStream({
      transform(value, controller) {
        let text = pending + `${value}`;
        pending = '';
        if (text.length && text.charCodeAt(text.length - 1) >= 0xd800 && text.charCodeAt(text.length - 1) <= 0xdbff) {
          pending = text.slice(-1);
          text = text.slice(0, -1);
        }
        if (text.length) controller.enqueue(encoder.encode(text));
      },
      flush(controller) { if (pending) controller.enqueue(encoder.encode(pending)); },
    });
  }
  get encoding() { return 'utf-8'; }
  get readable() { return this.#stream.readable; }
  get writable() { return this.#stream.writable; }
  get [Symbol.toStringTag]() { return 'TextEncoderStream'; }
}
class TextDecoderStream {
  #stream;
  #decoder;
  constructor(label = 'utf-8', options = {}) {
    this.#decoder = new TextDecoder(label, options);
    const decoder = this.#decoder;
    this.#stream = new TransformStream({
      transform(value, controller) {
        const text = decoder.decode(value, { stream: true });
        if (text.length) controller.enqueue(text);
      },
      flush(controller) {
        const text = decoder.decode();
        if (text.length) controller.enqueue(text);
      },
    });
  }
  get encoding() { return this.#decoder.encoding; }
  get fatal() { return this.#decoder.fatal; }
  get ignoreBOM() { return this.#decoder.ignoreBOM; }
  get readable() { return this.#stream.readable; }
  get writable() { return this.#stream.writable; }
  get [Symbol.toStringTag]() { return 'TextDecoderStream'; }
}
module.exports = {
  ReadableStream, ReadableStreamDefaultReader, ReadableStreamBYOBReader,
  ReadableStreamDefaultController, ReadableByteStreamController, ReadableStreamBYOBRequest,
  WritableStream, WritableStreamDefaultWriter, WritableStreamDefaultController,
  TransformStream, TransformStreamDefaultController, ByteLengthQueuingStrategy, CountQueuingStrategy,
  TextEncoderStream, TextDecoderStream, CompressionStream, DecompressionStream,
};
