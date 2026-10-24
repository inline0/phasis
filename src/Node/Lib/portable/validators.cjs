'use strict';

const { ERR_INVALID_ARG_TYPE } = require('./errors.cjs').codes;

exports.validateString = function validateString(value, name) {
  if (typeof value !== 'string') throw new ERR_INVALID_ARG_TYPE(name, 'string', value);
};

exports.validateObject = function validateObject(value, name, options = {}) {
  if ((value === null && !options.nullable) ||
      (typeof value !== 'object' && !(options.allowFunction && typeof value === 'function')) ||
      (!options.allowArray && Array.isArray(value))) {
    throw new ERR_INVALID_ARG_TYPE(name, 'Object', value);
  }
};
