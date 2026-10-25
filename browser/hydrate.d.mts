import type { HydrationOptions, Root } from 'react-dom/client';

/** A transport or identity failure detected before React starts hydrating. */
export declare class HydrationError extends Error {
    readonly code: 'root' | 'duplicate' | 'state' | 'protocol' | 'identity' | 'react' | 'export' | 'diagnostics';
    constructor(code: HydrationError['code'], message: string);
}

/** Identities compiled into the host's external browser entry and React diagnostics. */
export interface ExpectedHydration extends Pick<HydrationOptions, 'onRecoverableError' | 'onCaughtError' | 'onUncaughtError'> {
    entryId: string;
    buildId: string;
    export?: string;
    onError?: (error: HydrationError) => void;
}

/** Hydrate using a statically imported module and the root's adjacent Phasis state. */
export declare function hydrate(
    root: Element,
    componentModule: object,
    expected: ExpectedHydration,
): Root;
