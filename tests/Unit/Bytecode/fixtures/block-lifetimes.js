function siblings(seed) {
  const output = [];
  { let value = seed; value++; output.push(value); }
  { let value; output.push(value); value = seed * 2; output.push(value); }
  return output;
}
function branches(flag, seed) {
  const output = [];
  if (flag) { let value = seed; if (value) output.push(value); }
  else { let value = seed + 1; output.push(value); }
  { let value = seed + 2; output.push(value); }
  return output;
}
function looping(count) {
  const output = [];
  for (let index = 0; index < count; index++) {
    { let value = index; if (value % 2) continue; output.push(value); }
    { let value = index + 10; output.push(value); }
  }
  return output;
}
function abrupt(seed) {
  const output = [];
  try { let value = seed; output.push(value); throw new Error('stop'); }
  catch (error) { output.push(error.message); }
  finally { let value = seed + 1; output.push(value); }
  return output;
}
function leaked() {
  { let value = 1; }
  { let value = 2; }
  return typeof value;
}
function closures() {
  let first;
  { let value = 1; first = () => value; }
  { let value = 2; return [first(), value]; }
}
function nested() {
  const output = [];
  { let value = 1; { let value = 2; output.push(value); } output.push(value); }
  { let value = 3; output.push(value); }
  return output;
}
function parameter(value) {
  { let value = 1; }
  { let value = 2; }
  return value;
}
function hoisted() {
  { let value = 1; }
  { let value = 2; }
  var value = 9;
  return value;
}
function beforeInit() {
  const output = [];
  { try { output.push(value); } catch (error) { output.push(error.name); } let value = 1; output.push(value); }
  { let value = 2; output.push(value); }
  return output;
}
function selfInit() {
  { let value = 1; }
  { let value = value; return value; }
}
function constMix() {
  { let value = 1; }
  { const value = 2; return value; }
}
const blockLifetimeResult = [];
for (let pass = 0; pass < 2; pass++) {
  blockLifetimeResult.push(siblings(pass + 1), branches(true, pass + 1), branches(false, pass + 1),
    looping(4), abrupt(pass), leaked(), closures(), nested(), parameter(8), hoisted(), beforeInit(), constMix());
  try { selfInit(); } catch (error) { blockLifetimeResult.push(error.name); }
}
