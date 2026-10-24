'use strict';

const { Buffer } = require('buffer');
const host = __phasisNodeHost.vm;
const vm = module.exports;
function error(code, message, Constructor = TypeError) { return Object.assign(new Constructor(message), { code }); }
function object(value, name) {
  if (typeof value !== 'object' || value === null) throw error('ERR_INVALID_ARG_TYPE', `The "${name}" argument must be of type object`);
  return value;
}
function options(value) {
  if (value === undefined) return {};
  if (typeof value === 'string') return { filename: value };
  object(value, 'options');
  for (const name of ['lineOffset', 'columnOffset']) if (value[name] !== undefined && !Number.isInteger(value[name])) throw error('ERR_OUT_OF_RANGE', `The value of "options.${name}" is out of range`, RangeError);
  if (value.timeout !== undefined && (!Number.isInteger(value.timeout) || value.timeout <= 0 || value.timeout > 0xffffffff)) throw error('ERR_OUT_OF_RANGE', 'The value of "options.timeout" is out of range', RangeError);
  return value;
}
function run(script, context, value) {
  const config = options(value);
  const result = host.run(script, context, config);
  if (result.timeout) throw error('ERR_SCRIPT_EXECUTION_TIMEOUT', `Script execution timed out after ${config.timeout}ms`, Error);
  return result.value;
}
vm.isContext = value => host.isContext(value);
vm.createContext = (context = {}, value) => {
  object(context, 'object');
  const config = options(value);
  if (config.codeGeneration?.strings === false || config.codeGeneration?.wasm === false) {
    throw error('ERR_FEATURE_UNAVAILABLE_ON_PLATFORM', 'Per-context code-generation controls are not yet available in Phasis', Error);
  }
  return host.createContext(context, config);
};
class Script {
  constructor(code, value) {
    const config = options(value);
    this._source = String(code); this._options = config;
    this._script = host.compile(this._source, config);
    if (config.cachedData !== undefined) this.cachedDataRejected = true;
    if (config.produceCachedData) this.cachedDataProduced = false;
  }
  runInContext(context, value) {
    if (!vm.isContext(context)) throw error('ERR_INVALID_ARG_TYPE', 'The "contextifiedObject" argument must be a vm.Context');
    return run(this._script, context, value);
  }
  runInNewContext(context = {}, value) { return this.runInContext(vm.createContext(context, value), value); }
  runInThisContext(value) { return run(this._script, undefined, value); }
  createCachedData() { throw error('ERR_FEATURE_UNAVAILABLE_ON_PLATFORM', 'V8 cached-data serialization is unavailable in Phasis', Error); }
  get sourceMapURL() { return /\/\/[#@]\s*sourceMappingURL=(\S+)/.exec(this._source)?.[1]; }
}
vm.Script = Script;
vm.createScript = (code, value) => new Script(code, value);
vm.runInContext = (code, context, value) => new Script(code, value).runInContext(context, value);
vm.runInNewContext = (code, context, value) => new Script(code, value).runInNewContext(context, value);
vm.runInThisContext = (code, value) => new Script(code, value).runInThisContext(value);
vm.compileFunction = (code, params = [], value = {}) => {
  if (typeof code !== 'string') throw error('ERR_INVALID_ARG_TYPE', 'The "code" argument must be of type string');
  if (!Array.isArray(params) || params.some(name => typeof name !== 'string')) throw error('ERR_INVALID_ARG_TYPE', 'The "params" argument must be an array of strings');
  const config = options(value);
  if (config.contextExtensions?.length) throw error('ERR_FEATURE_UNAVAILABLE_ON_PLATFORM', 'compileFunction context extensions are not yet available in Phasis', Error);
  const source = `(function (${params.join(',')}) {\n${code}\n})`;
  return config.parsingContext ? vm.runInContext(source, config.parsingContext, config) : vm.runInThisContext(source, config);
};
