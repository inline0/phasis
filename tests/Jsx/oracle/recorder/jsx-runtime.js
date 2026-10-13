import { record } from './shared.js';

export function Fragment(props) {
    return props.children;
}

export function jsx(...args) {
    return record('jsx', args);
}

export function jsxs(...args) {
    return record('jsxs', args);
}
