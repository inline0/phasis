function escapedString(text, offset) {
  'use strict';
  { let hidden = 1; }
  hidden = 2;
  while (offset < text.length) offset++;
  return offset;
}
function escapedNumeric(offset) {
  'use strict';
  { let hidden = 1; }
  hidden = 2;
  while (offset < 1) offset++;
  return offset;
}
function escapedRead() {
  { const hidden = 1; }
  return hidden;
}
function escapedCompound() {
  'use strict';
  { let hidden = 1; }
  hidden += 1;
  return 0;
}
function escapedUpdate() {
  'use strict';
  { let hidden = 1; }
  hidden++;
  return 0;
}
function escapedTypeof() {
  { let hidden = 1; }
  return typeof hidden;
}
function escapedSloppy() {
  { let scopeLeak = 1; }
  scopeLeak = 2;
  return globalThis.scopeLeak;
}
function validBlock(offset) {
  { let hidden = offset + 1; offset = hidden + 1; }
  return offset;
}
globalThis.scopeLeak = 0;
const lexicalBlockEscapeResult = [];
for (const run of [
  () => escapedString('x', 0), () => escapedNumeric(0), () => escapedRead(),
  () => escapedCompound(), () => escapedUpdate(), () => escapedTypeof(), () => escapedSloppy()
]) {
  try { lexicalBlockEscapeResult.push(run()); }
  catch (error) { lexicalBlockEscapeResult.push(error.name); }
}
lexicalBlockEscapeResult.push(validBlock(0), typeof hidden, globalThis.scopeLeak);
