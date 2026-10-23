function assignAnd(value, right) { return [value &&= right(), value]; }
function assignOr(value, right) { return [value ||= right(), value]; }
function assignNull(value, right) { return [value ??= right(), value]; }
function memberAnd(base, right) { return base().value &&= right(); }
function memberOr(base, right) { return base().value ||= right(); }
function memberNull(base, right) { return base().value ??= right(); }
function captured(value, right) {
  const read = function readValue() { return value; };
  const result = value ??= right();
  return [result, read()];
}
const logicalTrace = [];
const logicalResults = [];
for (let pass = 0; pass < 2; pass++) {
  for (const value of [undefined, null, false, true, 0, 3, '', 'x']) {
    const rhs = () => { logicalTrace.push('rhs'); return 7; };
    logicalResults.push(assignAnd(value, rhs), assignOr(value, rhs), assignNull(value, rhs), captured(value, rhs));
    for (const method of [memberAnd, memberOr, memberNull]) {
      let object;
      const original = object = {
        get value() { logicalTrace.push('get'); return value; },
        set value(next) { logicalTrace.push('set ' + next); this.stored = next; },
      };
      const result = method(() => { logicalTrace.push('base'); return object; }, () => {
        logicalTrace.push('rhs'); object = {}; return 9;
      });
      logicalResults.push([result, original.stored, object.stored]);
    }
  }
  const frozenTruthy = Object.freeze({ value: 1 });
  const frozenFalsy = Object.freeze({ value: 0 });
  logicalResults.push(memberOr(() => frozenTruthy, () => { throw new Error('unexpected rhs'); }));
  logicalResults.push(memberAnd(() => frozenFalsy, () => { throw new Error('unexpected rhs'); }));
  try { memberNull(() => null, () => { logicalTrace.push('unexpected rhs'); }); }
  catch (error) { logicalResults.push(error.name); }
}
const logicalAssignmentResult = [logicalResults, logicalTrace];
