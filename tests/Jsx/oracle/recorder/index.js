import { record } from './shared.js';

export { Fragment } from './jsx-runtime.js';

export function createElement(...args) {
    return record('createElement', args);
}

export function h(...args) {
    return record('h', args);
}

export function Frag(props) {
    return props.children;
}

export function log(label) {
    console.log('eval ' + label);
    return label;
}
