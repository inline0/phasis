// Recording JSX runtime for the lowering oracle: every call prints one line
// describing exactly what the compiled code passed in, and returns a token
// that later lines refer to as #id.
let next = 0;

export function show(value) {
    if (value === undefined) return 'undefined';
    if (value === null) return 'null';
    switch (typeof value) {
        case 'string': return JSON.stringify(value);
        case 'number':
        case 'boolean': return String(value);
        case 'bigint': return value + 'n';
        case 'symbol': return value.toString();
        case 'function': return 'fn:' + (value.name || 'anonymous');
    }
    if (Array.isArray(value)) return '[' + value.map(show).join(', ') + ']';
    if (Object.prototype.hasOwnProperty.call(value, '$el')) return '#' + value.$el;
    const proto = Object.getPrototypeOf(value);
    const prefix = proto === Object.prototype ? '' : proto === null ? '(null proto)' : '(custom proto)';
    return prefix + '{' + Object.keys(value).map((k) => JSON.stringify(k) + ': ' + show(value[k])).join(', ') + '}';
}

export function record(fn, args) {
    const id = ++next;
    console.log(`#${id} ${fn}(${args.map(show).join(', ')})`);
    return { $el: id };
}
