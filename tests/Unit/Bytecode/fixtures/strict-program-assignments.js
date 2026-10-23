var strictAssignmentCases = [
  {
    name: 'ordinary globals and completion',
    setup: '',
    source: '"use strict"; var target = 1; target = 3; target;',
    observation: '[target, globalThis.target]',
  },
  {
    name: 'RHS throw precedes the unresolved write',
    setup: 'var trace = []; function rhs() { trace.push("rhs"); throw new RangeError("rhs"); }',
    source: '"use strict"; missing = rhs();',
    observation: '[trace, Object.hasOwn(globalThis, "missing")]',
  },
  {
    name: 'assignment does not read the old accessor value',
    setup: `var trace = [];
      Object.defineProperty(globalThis, 'target', {
        configurable: true,
        get() { trace.push('unexpected getter'); throw new Error('get'); },
        set(value) { trace.push('set ' + value); }
      });
      function rhs() { trace.push('rhs'); return 12; }`,
    source: '"use strict"; target = rhs();',
    observation: 'trace',
  },
  {
    name: 'RHS deletion is observed by the original global environment',
    setup: `var trace = []; globalThis.target = 1;
      function rhs() { trace.push('delete'); delete globalThis.target; return 8; }`,
    source: '"use strict"; target = rhs();',
    observation: '[trace, Object.hasOwn(globalThis, "target")]',
  },
  {
    name: 'RHS replaces the accessor before PutValue',
    setup: `var trace = [];
      Object.defineProperty(globalThis, 'target', {
        configurable: true, set(value) { trace.push('old set ' + value); }
      });
      function rhs() {
        trace.push('rhs');
        Object.defineProperty(globalThis, 'target', {
          configurable: true, set(value) { trace.push('new set ' + value); }
        });
        return 9;
      }`,
    source: '"use strict"; target = rhs();',
    observation: 'trace',
  },
  {
    name: 'setter throw follows the completed RHS',
    setup: `var trace = [];
      Object.defineProperty(globalThis, 'target', {
        configurable: true, set(value) { trace.push('set ' + value); throw new SyntaxError('set'); }
      });
      function rhs() { trace.push('rhs'); return 13; }`,
    source: '"use strict"; target = rhs();',
    observation: 'trace',
  },
  {
    name: 'anonymous function name inference remains interpreter-owned',
    setup: 'var target;',
    source: '"use strict"; target = function () {}; target.name;',
    observation: 'target.name',
  },
  {
    name: 'this is the script global inside the fallback expression',
    setup: 'var target;',
    source: '"use strict"; target = this; target === globalThis;',
    observation: 'target === globalThis',
  },
];
