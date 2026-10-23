'use strict';

const host = __phasisNodeHost;
const builtinModules = host.builtinNames();
module.exports = {
  builtinModules,
  createRequire: (filename) => host.createRequire(filename),
  isBuiltin: (name) => typeof name === 'string' && builtinModules.includes(name.startsWith('node:') ? name.slice(5) : name),
  syncBuiltinESMExports() {
    throw new Error('syncBuiltinESMExports has not been implemented');
  },
};
