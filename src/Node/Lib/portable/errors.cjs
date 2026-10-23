'use strict';

function describe(value) {
  if (value === null) return 'null';
  if (value === undefined) return 'undefined';
  if (typeof value === 'string') return "type string ('" + value + "')";
  if (typeof value === 'object') return 'an instance of ' + (value.constructor && value.constructor.name || 'Object');
  return 'type ' + typeof value + ' (' + String(value) + ')';
}

function coded(Base, code, message) {
  return class extends Base {
    constructor(...args) {
      super(message(...args));
      this.code = code;
      Object.defineProperty(this, 'name', { value: Base.name, configurable: true, writable: true });
    }
  };
}

exports.codes = {
  ERR_INVALID_ARG_TYPE: coded(TypeError, 'ERR_INVALID_ARG_TYPE', (name, expected, actual) =>
    'The "' + name + '" argument must be of type ' + expected + '. Received ' + describe(actual)),
  ERR_INVALID_ARG_VALUE: coded(TypeError, 'ERR_INVALID_ARG_VALUE', (name, value, reason = 'is invalid') =>
    'The argument \'' + name + '\' ' + reason + '. Received ' + String(value)),
  ERR_INVALID_URL: coded(TypeError, 'ERR_INVALID_URL', () => 'Invalid URL'),
  ERR_INVALID_URI: coded(URIError, 'ERR_INVALID_URI', () => 'URI malformed'),
};
