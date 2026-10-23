'use strict';

function number(value, options) {
  if (Object.is(value, -0)) return '-0';
  let text = String(value);
  if (options && options.numericSeparator && !/[eE]/.test(text)) {
    const pieces = text.split('.');
    pieces[0] = pieces[0].replace(/\B(?=(\d{3})+(?!\d))/g, '_');
    if (pieces[1]) pieces[1] = pieces[1].replace(/(\d{3})(?=\d)/g, '$1_');
    text = pieces.join('.');
  }
  return text + (typeof value === 'bigint' ? 'n' : '');
}

function json(value) {
  try { return JSON.stringify(value); } catch (error) {
    let circular;
    try { const object = {}; object.object = object; JSON.stringify(object); } catch (failure) { circular = failure.message.split('\n')[0]; }
    if (error.name === 'TypeError' && error.message.split('\n')[0] === circular) return '[Circular]';
    throw error;
  }
}

function builtInToString(value) {
  const toString = value.toString;
  const toPrimitive = value[Symbol.toPrimitive];
  if (typeof toString !== 'function' && typeof toPrimitive !== 'function') return true;
  let owner = value;
  while (owner !== null) {
    if (typeof toString === 'function' && Object.hasOwn(owner, 'toString') ||
        typeof toPrimitive === 'function' && Object.hasOwn(owner, Symbol.toPrimitive)) {
      const descriptor = Object.getOwnPropertyDescriptor(owner, 'constructor');
      return !!descriptor && typeof descriptor.value === 'function' && /\[native code\]/.test(Function.prototype.toString.call(descriptor.value));
    }
    owner = Object.getPrototypeOf(owner);
  }
  return true;
}

exports.format = function format(inspect, options, args) {
  if (args.length === 0) return '';
  if (typeof args[0] !== 'string') return args.map(value => typeof value === 'string' ? value : inspect(value, options)).join(' ');
  if (args.length === 1) return args[0];
  let index = 1;
  let result = args[0].replace(/%[sdifoOjc%]/g, token => {
    if (token === '%%') return '%';
    if (index === args.length) return token;
    const value = args[index++];
    switch (token) {
      case '%s':
        if (typeof value === 'number' || typeof value === 'bigint') return number(value, options);
        return value !== null && typeof value === 'object' && builtInToString(value)
          ? inspect(value, { ...options, compact: 3, colors: false, depth: 0 }) : String(value);
      case '%d': return typeof value === 'symbol' ? 'NaN' : number(typeof value === 'bigint' ? value : Number(value), options);
      case '%i': return typeof value === 'symbol' ? 'NaN' : number(typeof value === 'bigint' ? value : parseInt(value), options);
      case '%f': return typeof value === 'symbol' ? 'NaN' : number(parseFloat(value), options);
      case '%j': return json(value);
      case '%o': return inspect(value, { ...options, showHidden: true, showProxy: true, depth: 4 });
      case '%O': return inspect(value, options);
      case '%c': return '';
    }
  });
  for (; index < args.length; index++) {
    result += ' ' + (typeof args[index] === 'string' ? args[index] : inspect(args[index], options));
  }
  return result;
};
