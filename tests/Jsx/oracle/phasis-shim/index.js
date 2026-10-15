// `phasis` for the HTML render oracle: the same API on top of Preact 11.0.1
// and preact-render-to-string 6.8.0, the reference the HTML profile matches.
import { h, Fragment, createContext } from 'preact';
import {
    useState, useReducer, useMemo, useCallback, useRef, useEffect, useLayoutEffect,
    useContext, useImperativeHandle, useDebugValue, useId,
} from 'preact/hooks';
import { renderToString as prts, renderToStringAsync as prtsAsync } from 'preact-render-to-string';

export {
    Fragment, createContext, useState, useReducer, useMemo, useCallback, useRef, useEffect,
    useLayoutEffect, useContext, useImperativeHandle, useDebugValue, useId,
};
export const createElement = h;
export const useInsertionEffect = () => {};
export const useSyncExternalStore = (subscribe, getSnapshot, getServerSnapshot) => (getServerSnapshot ?? getSnapshot)();
export const useTransition = () => [false, () => {}];
export const useDeferredValue = (value, initialValue) => (initialValue !== undefined ? initialValue : value);

/** React-style use(): suspends by throwing the pending promise, as Preact's Suspense expects. */
export function use(usable) {
    if (usable && typeof usable.then === 'function') {
        if (usable.status === 'fulfilled') return usable.value;
        if (usable.status === 'rejected') throw usable.reason;
        if (usable.status !== 'pending') {
            usable.status = 'pending';
            usable.then(
                (value) => { usable.status = 'fulfilled'; usable.value = value; },
                (reason) => { usable.status = 'rejected'; usable.reason = reason; },
            );
        }
        throw usable;
    }
    return useContext(usable);
}

export const renderToString = (vnode, context) => prts(vnode, context);
export const renderToStringAsync = (vnode, context) => prtsAsync(vnode, context);

export function raw() {
    throw new Error('raw() is a Phasis extension and has no oracle');
}
