import { createElement, version as reactVersion } from 'react';
import { version as reactDOMVersion } from 'react-dom';
import { hydrateRoot } from 'react-dom/client';

const protocol = 1;
const initialized = new WeakSet();
const fields = [
    'protocol', 'entryId', 'export', 'buildId', 'rootId',
    'identifierPrefix', 'reactVersion', 'props',
];

function isFiniteData(value, depth = 0) {
    if (depth > 128) return false;
    if (typeof value === 'number') return Number.isFinite(value);
    if (value && typeof value === 'object') {
        return Object.values(value).every((item) => isFiniteData(item, depth + 1));
    }
    return true;
}

/** A transport or identity error detected before React changes the server HTML. */
export class HydrationError extends Error {
    constructor(code, message) {
        super(message);
        this.name = 'HydrationError';
        this.code = code;
    }
}

/**
 * Hydrate one element using its adjacent captured state and a statically
 * imported component module. Identity failures throw and preserve the HTML.
 */
export function hydrate(root, componentModule, expected) {
    const fail = (code, message) => {
        const error = new HydrationError(code, message);
        try {
            expected?.onError?.(error);
        } finally {
            throw error;
        }
    };
    if (!root || root.nodeType !== 1 || root.namespaceURI !== 'http://www.w3.org/1999/xhtml'
        || ['html', 'head', 'body'].includes(root.localName) || !root.id) {
        fail('root', 'Hydration requires an HTML element root with a nonempty ID.');
    }
    if (initialized.has(root) || root.hasAttribute('data-phasis-hydrated')) {
        fail('duplicate', 'This element has already been initialized by Phasis.');
    }
    const matches = Array.from(root.ownerDocument.querySelectorAll('[id]')).filter((element) => element.id === root.id);
    if (matches.length !== 1 || matches[0] !== root) {
        fail('root', 'The hydration root ID must be unique within its document.');
    }
    const tag = root.nextElementSibling;
    if (!tag || tag.localName !== 'script' || tag.type !== 'application/json'
        || tag.getAttribute('data-phasis-state') !== root.id) {
        fail('state', 'The hydration root must be followed by its inert Phasis state tag.');
    }
    let state;
    try {
        state = JSON.parse(tag.textContent);
    } catch {
        fail('state', 'The hydration state is not valid JSON.');
    }
    if (!state || typeof state !== 'object' || Array.isArray(state)
        || Object.keys(state).length !== fields.length
        || fields.some((field) => !Object.hasOwn(state, field))
        || !state.props || typeof state.props !== 'object' || Array.isArray(state.props)
        || fields.slice(1, 7).some((field) => typeof state[field] !== 'string')
        || ['entryId', 'export', 'buildId', 'rootId', 'reactVersion'].some((field) => state[field] === '')) {
        fail('state', 'The hydration state has an invalid shape.');
    }
    if (!isFiniteData(state.props)) {
        fail('state', 'The hydration props exceed the data depth or contain a non-finite number.');
    }
    if (state.protocol !== protocol) {
        fail('protocol', 'Unsupported Phasis hydration protocol.');
    }
    if (!expected || typeof expected.entryId !== 'string' || !expected.entryId
        || typeof expected.buildId !== 'string' || !expected.buildId
        || (expected.export !== undefined && (typeof expected.export !== 'string' || !expected.export))) {
        fail('identity', 'The browser entry must supply its expected entryId, buildId and export.');
    }
    if (state.entryId !== expected.entryId || state.buildId !== expected.buildId
        || state.export !== (expected.export ?? 'default') || state.rootId !== root.id) {
        fail('identity', 'The server root and browser entry identities do not match.');
    }
    if (state.reactVersion !== reactVersion || reactDOMVersion !== reactVersion) {
        fail('react', 'The server, React and React DOM versions must match exactly.');
    }
    if (!componentModule || !Object.hasOwn(componentModule, state.export)) {
        fail('export', 'The statically imported module does not own the selected component export.');
    }
    const component = componentModule[state.export];
    if (component === null || !['function', 'object', 'string', 'symbol'].includes(typeof component)) {
        fail('export', 'The selected module export is not a React component.');
    }
    const options = { identifierPrefix: state.identifierPrefix };
    for (const name of ['onRecoverableError', 'onCaughtError', 'onUncaughtError']) {
        if (expected[name] !== undefined) {
            if (typeof expected[name] !== 'function') {
                fail('diagnostics', `${name} must be a function.`);
            }
            options[name] = expected[name];
        }
    }
    // React retains ownership of actual hydration diagnostics and recovery.
    // A stale transport never reaches hydrateRoot or a createRoot fallback.
    const element = createElement(component, state.props);
    initialized.add(root);
    root.setAttribute('data-phasis-hydrated', '');
    try {
        return hydrateRoot(root, element, options);
    } catch (error) {
        initialized.delete(root);
        root.removeAttribute('data-phasis-hydrated');
        throw error;
    }
}
