var computedDataReads = [];
var readEvents = [];
function readComputed(object, key) { return object[key]; }
const key = Symbol('key');
const object = {field: 1, [key]: 2};
computedDataReads.push(['data', readComputed(object, 'field'), readComputed(object, key)]);
object.field = 3;
object[key] = 4;
computedDataReads.push(['writes', readComputed(object, 'field'), readComputed(object, key)]);
Object.defineProperty(object, 'field', {value: 5, writable: false});
Object.defineProperty(object, key, {value: 6, writable: false});
computedDataReads.push(['descriptors', readComputed(object, 'field'), readComputed(object, key)]);
Object.defineProperty(object, 'field', {get() {readEvents.push('own-string'); return 7;}});
Object.defineProperty(object, key, {get() {readEvents.push('own-symbol'); return 8;}});
computedDataReads.push(['accessors', readComputed(object, 'field'), readComputed(object, key)]);
delete object.field;
delete object[key];
const prototype = {
  get field() {readEvents.push('prototype-string'); return this.marker;},
  get [key]() {readEvents.push('prototype-symbol'); return this.marker + 1;},
};
object.marker = 9;
Object.setPrototypeOf(object, prototype);
computedDataReads.push(['prototype', readComputed(object, 'field'), readComputed(object, key)]);
const proxy = new Proxy({field: 11, [key]: 12}, {
  get(target, property, receiver) {
    readEvents.push(typeof property === 'symbol' ? 'proxy-symbol' : 'proxy-string');
    return Reflect.get(target, property, receiver);
  },
});
computedDataReads.push(['proxy', readComputed(proxy, 'field'), readComputed(proxy, key)]);
const stringKey = {[Symbol.toPrimitive]() {readEvents.push('coerce-string'); return 'field';}};
const symbolKey = {[Symbol.toPrimitive]() {readEvents.push('coerce-symbol'); return key;}};
computedDataReads.push(['coercion', readComputed(proxy, stringKey), readComputed(proxy, symbolKey)]);
const inheritedProxy = Object.create(new Proxy({field: 18, [key]: 19}, {
  get(target, property, receiver) {
    readEvents.push(typeof property === 'symbol' ? 'inherited-proxy-symbol' : 'inherited-proxy-string');
    return Reflect.get(target, property, receiver);
  },
}));
computedDataReads.push(['inherited-proxy', readComputed(inheritedProxy, 'field'), readComputed(inheritedProxy, key)]);
let nullCoercions = 0, nullError = false;
try { readComputed(null, {[Symbol.toPrimitive]() {nullCoercions++; return 'field';}}); }
catch (error) { nullError = error instanceof TypeError; }
computedDataReads.push(['nullish-order', nullError, nullCoercions]);
const unusual = {length: 13, ['0']: 14, ['']: 15};
computedDataReads.push(['keys', readComputed(unusual, 'length'), readComputed(unusual, 0), readComputed(unusual, '')]);
computedDataReads.push(['exotic', readComputed([16], 0), readComputed([16], 'length'), readComputed(new Uint8Array([17]), '0')]);
computedDataReads.push(['primitive', readComputed('abc', 'length'), readComputed('abc', '1')]);
computedDataReads.push(['missing', readComputed({}, key) === undefined, readComputed({}, 'missing') === undefined]);
computedDataReads.push(['events', readEvents]);
