// Renders an HTML-profile fixture with renderToString and
// renderToStringAsync. Shared by the Node oracle (where `phasis` is the
// Preact-based shim) and the Phasis tests.
import { createElement, renderToString, renderToStringAsync } from 'phasis';

export async function run(mod) {
    const props = mod.props ?? {};
    const out = {};
    try {
        out.string = renderToString(createElement(mod.default, props));
    } catch (e) {
        out.string = 'ERROR: ' + (e && e.message);
    }
    try {
        out.async = await renderToStringAsync(createElement(mod.default, props));
    } catch (e) {
        out.async = 'ERROR: ' + (e && e.message);
    }
    return out;
}
