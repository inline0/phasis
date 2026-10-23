'use strict';

exports.isWindows = process.platform === 'win32';
exports.getLazy = function getLazy(factory) {
  let value;
  let loaded = false;
  return function () {
    if (!loaded) { value = factory(); loaded = true; }
    return value;
  };
};
exports.spliceOne = function spliceOne(list, index) { list.splice(index, 1); };
exports.isInsideNodeModules = function isInsideNodeModules() {
  return /[\\/]node_modules[\\/]/.test(new Error().stack || '');
};
