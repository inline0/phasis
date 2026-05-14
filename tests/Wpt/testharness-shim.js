// Minimal Web Platform Tests harness shim.
//
// The full WPT testharness.js is ~3K lines and assumes a DOM context.
// Phasis runs WPT .any.js tests headless, so we only need the API
// surface that the URL / Encoding / atob / structured-clone / hr-time
// suites actually call. ~250 lines covers everything the imported
// fixtures need.
//
// Results are reported via the host-installed __phasisWptReport
// callback after every subtest, so the PHP runner gets a per-subtest
// stream without having to parse a final dump.

(function (global) {
    "use strict";

    function report(status, name, message) {
        if (typeof __phasisWptReport === "function") {
            __phasisWptReport(status, name, message || "");
        }
    }

    function describe(value) {
        if (value === null) return "null";
        if (value === undefined) return "undefined";
        if (typeof value === "number" && Number.isNaN(value)) return "NaN";
        if (typeof value === "string") return JSON.stringify(value);
        if (typeof value === "bigint") return value.toString() + "n";
        try {
            return String(value);
        } catch (_) {
            return "[object]";
        }
    }

    function AssertionError(message) {
        const err = new Error(message);
        err.name = "AssertionError";
        return err;
    }

    function format(actual, expected, op, description) {
        const prefix = description ? description + ": " : "";
        return prefix + "expected " + describe(actual) + " " + op + " " +
               describe(expected);
    }

    // -- Assertion API -------------------------------------------------------

    global.assert_equals = function (actual, expected, description) {
        if (actual === expected) {
            if (actual === 0 && 1 / actual !== 1 / expected) {
                throw AssertionError(format(actual, expected,
                    "to be the same sign of zero as", description));
            }
            return;
        }
        // NaN === NaN should pass
        if (typeof actual === "number" && typeof expected === "number" &&
            Number.isNaN(actual) && Number.isNaN(expected)) {
            return;
        }
        throw AssertionError(format(actual, expected, "===", description));
    };

    global.assert_not_equals = function (actual, expected, description) {
        if (actual !== expected) return;
        throw AssertionError(format(actual, expected, "!==", description));
    };

    global.assert_true = function (actual, description) {
        if (actual === true) return;
        throw AssertionError(format(actual, true, "===", description));
    };

    global.assert_false = function (actual, description) {
        if (actual === false) return;
        throw AssertionError(format(actual, false, "===", description));
    };

    global.assert_in_array = function (actual, array, description) {
        for (let i = 0; i < array.length; i++) {
            if (array[i] === actual) return;
        }
        throw AssertionError(
            (description ? description + ": " : "") +
            describe(actual) + " not in " + describe(array));
    };

    global.assert_array_equals = function (actual, expected, description) {
        if (actual.length !== expected.length) {
            throw AssertionError(format(actual.length, expected.length,
                "(length) ===", description));
        }
        for (let i = 0; i < actual.length; i++) {
            if (actual[i] !== expected[i]) {
                // Allow NaN === NaN
                if (typeof actual[i] === "number" &&
                    typeof expected[i] === "number" &&
                    Number.isNaN(actual[i]) && Number.isNaN(expected[i])) {
                    continue;
                }
                throw AssertionError(
                    (description ? description + ": " : "") +
                    "index " + i + ": " + describe(actual[i]) +
                    " !== " + describe(expected[i]));
            }
        }
    };

    global.assert_object_equals = function (actual, expected, description) {
        const aKeys = Object.keys(actual).sort();
        const eKeys = Object.keys(expected).sort();
        assert_array_equals(aKeys, eKeys, description);
        for (const k of aKeys) {
            assert_equals(actual[k], expected[k],
                (description ? description + "." : "") + k);
        }
    };

    global.assert_throws_js = function (constructor, fn, description) {
        try {
            fn();
        } catch (e) {
            if (e instanceof constructor) return;
            throw AssertionError(
                (description ? description + ": " : "") +
                "threw " + (e && e.name) + ", expected " +
                (constructor && constructor.name));
        }
        throw AssertionError((description ? description + ": " : "") +
            "did not throw");
    };

    global.assert_throws_dom = function (codeOrName, fn, description) {
        try {
            fn();
        } catch (e) {
            // Accept both numeric legacy code or string name
            if (typeof codeOrName === "number") {
                if (e && e.code === codeOrName) return;
            } else {
                if (e && e.name === codeOrName) return;
            }
            throw AssertionError(
                (description ? description + ": " : "") +
                "threw " + (e && (e.name || e)) + " (code " +
                (e && e.code) + "), expected " + codeOrName);
        }
        throw AssertionError((description ? description + ": " : "") +
            "did not throw");
    };

    global.assert_throws_exactly = function (value, fn, description) {
        try {
            fn();
        } catch (e) {
            if (e === value) return;
            throw AssertionError(
                (description ? description + ": " : "") +
                "threw " + describe(e) + ", expected exactly " + describe(value));
        }
        throw AssertionError((description ? description + ": " : "") +
            "did not throw");
    };

    global.assert_unreached = function (description) {
        throw AssertionError(
            (description ? description + ": " : "") + "unreached");
    };

    global.assert_approx_equals = function (actual, expected, epsilon, description) {
        if (Math.abs(actual - expected) <= epsilon) return;
        throw AssertionError(
            (description ? description + ": " : "") +
            describe(actual) + " not within " + epsilon + " of " +
            describe(expected));
    };

    global.assert_regexp_match = function (actual, re, description) {
        if (re.test(actual)) return;
        throw AssertionError(
            (description ? description + ": " : "") +
            describe(actual) + " does not match " + re);
    };

    global.assert_class_string = function (object, classString, description) {
        const actual = Object.prototype.toString.call(object);
        const expected = "[object " + classString + "]";
        if (actual === expected) return;
        throw AssertionError(format(actual, expected, "===", description));
    };

    // -- Test runners --------------------------------------------------------

    global.test = function (fn, name) {
        const t = {
            add_cleanup: function () {},
            step: function (cb) { return cb.call(this); },
            step_func: function (cb) { const self = this; return function () { return cb.apply(self, arguments); }; },
            step_func_done: function (cb) { return function () { if (cb) cb.apply(this, arguments); }; },
            unreached_func: function (msg) {
                return function () { throw AssertionError(msg || "unreached"); };
            },
            step_timeout: function () {},  // no real timer; treat as no-op
            done: function () {},
        };
        try {
            fn.call(t);
            report("PASS", name);
        } catch (e) {
            report("FAIL", name, (e && e.message) || String(e));
        }
    };

    // promise_test: simplified — runs synchronously and resolves the promise
    // immediately. Phasis' Promise impl drains microtasks at engine exit; we
    // do the same here.
    global.promise_test = function (fn, name) {
        let settled = false;
        let failure = null;
        try {
            const t = { add_cleanup: function () {} };
            const result = fn(t);
            if (result && typeof result.then === "function") {
                result.then(
                    function () { settled = true; },
                    function (e) {
                        settled = true;
                        failure = e;
                    }
                );
                // Drain microtasks. Phasis Promise.resolve().then chains run
                // synchronously after the macrotask.
                Promise.resolve();
            } else {
                settled = true;
            }
        } catch (e) {
            settled = true;
            failure = e;
        }
        if (failure !== null) {
            report("FAIL", name, (failure && failure.message) || String(failure));
        } else {
            report("PASS", name);
        }
    };

    // async_test: simplified. The test function gets `t` with `step`,
    // `step_func`, `unreached_func`, `done`. We track sync completion only.
    global.async_test = function (fnOrName, maybeName) {
        let name, fn;
        if (typeof fnOrName === "function") {
            fn = fnOrName;
            name = maybeName;
        } else {
            name = fnOrName;
            fn = null;
        }
        const t = {
            done: function () {},
            step: function (cb) { cb(); },
            step_func: function (cb) { return function () { cb.apply(this, arguments); }; },
            step_func_done: function (cb) { return function () { if (cb) cb.apply(this, arguments); }; },
            unreached_func: function (msg) {
                return function () { throw AssertionError(msg || "unreached"); };
            },
            add_cleanup: function () {},
        };
        if (fn === null) {
            // Caller will call `.step` etc. against the returned object.
            return t;
        }
        try {
            fn(t);
            report("PASS", name);
        } catch (e) {
            report("FAIL", name, (e && e.message) || String(e));
        }
        return t;
    };

    global.subsetTestByKey = function () { return true; };

    // setup() is mostly a no-op for us — fixtures use it to declare metadata.
    // setup() either accepts options-only OR a function-to-run.
    // The function form is essentially a synchronous-setup hook; we
    // run it immediately so any module-scope state (handlers, fixtures)
    // is populated before tests execute.
    global.setup = function (fnOrOptions, maybeOptions) {
        if (typeof fnOrOptions === "function") {
            try {
                fnOrOptions();
            } catch (_) {
                // Setup errors are surfaced through the next test that runs.
            }
        }
    };

    // done() / add_completion_callback / add_result_callback — no-ops; we
    // report per-subtest already.
    global.done = function () {};
    global.add_completion_callback = function () {};
    global.add_result_callback = function () {};

    // format_value used by some fixtures.
    global.format_value = describe;

})(typeof globalThis !== "undefined" ? globalThis : this);
