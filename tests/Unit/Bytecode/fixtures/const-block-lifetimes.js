'use strict';

function constSiblings(seed) {
  const result = [];
  { const value = seed; result.push(value); }
  { const value = seed * 2; result.push(value); }
  return result;
}
function constBranches(flag, seed) {
  const result = [];
  if (flag) { const value = seed; result.push(value); }
  else { const value = seed + 1; result.push(value); }
  { const value = seed + 2; result.push(value); }
  return result;
}
function constLoop(count) {
  const result = [];
  for (let index = 0; index < count; index++) {
    { const value = index; if (value % 2) continue; result.push(value); }
    { const value = index + 10; result.push(value); }
  }
  return result;
}
function constWhile(input) {
  const result = [];
  let index = 0;
  while (index < input.length) {
    const value = input.charCodeAt(index++);
    result.push(value);
    if (value === 59) break;
  }
  while (index < input.length) {
    const value = input.charCodeAt(index++);
    if (value === 59) {
      const part = index;
      result.push(part);
    } else if (value === 44) {
      const part = index + 10;
      result.push(part);
    }
  }
  return result;
}
function constWrite(seed) {
  const result = [];
  { const value = seed; try { value = (result.push('rhs'), 9); } catch (error) { result.push(error.name, value); } }
  { const value = seed + 1; try { value += (result.push('compound rhs'), 1); } catch (error) { result.push(error.name, value); } }
  return result;
}
function constUpdate(seed) {
  const result = [];
  { const value = seed; try { value++; } catch (error) { result.push(error.name, value); } }
  { const value = seed + 1; try { --value; } catch (error) { result.push(error.name, value); } }
  return result;
}
function constThenLet(seed) {
  const result = [];
  { const value = seed; result.push(value); }
  { let value = seed + 1; value++; result.push(value); }
  return result;
}
function letThenConst(seed) {
  const result = [];
  { let value = seed; value++; result.push(value); }
  { const value = seed + 1; result.push(value); }
  return result;
}
function constClosures(count) {
  const readers = [];
  for (let index = 0; index < count; index++) {
    { const value = index; readers.push(() => value); }
    { const value = index + 10; readers.push(() => value); }
  }
  return readers.map(reader => reader());
}
function constNested() {
  const result = [];
  { const value = 1; { const value = 2; result.push(value); } result.push(value); }
  { const value = 3; result.push(value); }
  return result;
}
function constBeforeInit() {
  const result = [];
  { try { result.push(value); } catch (error) { result.push(error.name); } const value = 1; result.push(value); }
  { const value = 2; result.push(value); }
  return result;
}
function constSelfInit() {
  { const value = 1; }
  { const value = value; return value; }
}
function constOutside() {
  { const value = 1; }
  { const value = 2; }
  return typeof value;
}

var constBlockLifetimeResult = [];
for (let repeat = 0; repeat < 3; repeat++) {
  constBlockLifetimeResult.push(constSiblings(repeat + 1), constBranches(true, repeat),
    constBranches(false, repeat), constLoop(4), constWhile('a;b;,c'), constWrite(repeat),
    constUpdate(repeat), constThenLet(repeat), letThenConst(repeat), constClosures(3),
    constNested(), constBeforeInit(), constOutside());
  try { constSelfInit(); } catch (error) { constBlockLifetimeResult.push(error.name); }
}
