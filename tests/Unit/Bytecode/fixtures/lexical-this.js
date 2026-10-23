globalThis.value = 17;
var owner = {value: 41};
var other = {value: 99};
var direct = () => this.value;
function capture() { return () => this.value; }
function captureBlock() { const read = () => this.value; return read; }
function nestedCapture() { return () => () => this.value; }
function ordinary() { return this.value; }
var first = capture.call(owner);
var second = capture.call(other);
var block = captureBlock.call(owner);
var nested = nestedCapture.call(owner)();
var observations = [];
for (var round = 0; round < 4; round++) {
  observations.push([
    direct.call(other), direct.apply(other), direct.bind(other)(),
    first.call(other), first.apply(other), first.bind(other)(),
    Reflect.apply(first, other, []), second.call(owner),
    block.call(other), nested.call(other), ordinary.call(owner), ordinary.call(other)
  ]);
}
globalThis.lexicalThisResult = observations;
