function strictAfterAlias() {
  type NumberLike = number;
  'use strict';
  return this;
}
function strictAfterAssertion() {
  'another directive' as string;
  'use strict';
  return this;
}
function interfaceSemicolon() {
  interface Empty {};
  'use strict';
  return this;
}
console.log(strictAfterAlias() === undefined, strictAfterAssertion() === undefined);
console.log(interfaceSemicolon() === undefined);
function blockBreaksDirective() {
  type Value = number;
  {}
  'use strict';
  return this;
}
console.log(blockBreaksDirective() === undefined);
