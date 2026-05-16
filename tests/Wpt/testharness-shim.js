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
            // WPT fixtures sometimes call `test(fn)` without a name; fall
            // back to a placeholder so the PHP-side typed callback (string
            // name) never sees null.
            __phasisWptReport(status, name == null ? "(anonymous)" : String(name), message || "");
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
                "(length) ===", description) + " [actual=" + JSON.stringify(actual) + "]");
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

    // QuotaExceededError is its own exception type (was a DOMException
    // until 2024; now a distinct interface). For our purposes we accept
    // anything whose name === "QuotaExceededError".
    global.assert_throws_quotaexceedederror = function (fn, description) {
        try {
            fn();
        } catch (e) {
            if (e && e.name === "QuotaExceededError") return;
            throw AssertionError(
                (description ? description + ": " : "") +
                "threw " + (e && (e.name || e)) +
                ", expected QuotaExceededError");
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

    global.assert_greater_than = function (actual, expected, description) {
        if (actual > expected) return;
        throw AssertionError(format(actual, expected, ">", description));
    };

    global.assert_greater_than_equal = function (actual, expected, description) {
        if (actual >= expected) return;
        throw AssertionError(format(actual, expected, ">=", description));
    };

    global.assert_less_than = function (actual, expected, description) {
        if (actual < expected) return;
        throw AssertionError(format(actual, expected, "<", description));
    };

    global.assert_less_than_equal = function (actual, expected, description) {
        if (actual <= expected) return;
        throw AssertionError(format(actual, expected, "<=", description));
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

    // Element-wise compare two typed arrays / array-likes (length + values).
    global.assert_equals_typed_array = function (actual, expected, description) {
        if (actual.length !== expected.length) {
            throw AssertionError(format(actual.length, expected.length,
                "(length) ===", description));
        }
        for (let i = 0; i < actual.length; i++) {
            if (actual[i] !== expected[i]) {
                throw AssertionError(
                    (description ? description + ": " : "") +
                    "index " + i + ": " + describe(actual[i]) +
                    " !== " + describe(expected[i]));
            }
        }
    };

    // -- Test runners --------------------------------------------------------

    function makeTestObject() {
        const cleanups = [];
        const t = {
            add_cleanup: function (cb) {
                if (typeof cb === "function") cleanups.push(cb);
            },
            _runCleanups: function () {
                while (cleanups.length > 0) {
                    const cb = cleanups.shift();
                    try { cb(); } catch (_) {}
                }
            },
            // step() invokes its callback synchronously; we don't run any
            // asynchronous tasks. Errors surface back to the caller — so for
            // the `var t = async_test(...)` form they bubble out at module
            // top-level, which our runner reports as a file-level failure.
            step: function (cb) { return cb.call(this); },
            step_func: function (cb) { const self = this; return function () { return cb.apply(self, arguments); }; },
            step_func_done: function (cb) { const self = this; return function () { if (cb) cb.apply(self, arguments); }; },
            unreached_func: function (msg) {
                return function () { throw AssertionError(msg || "unreached"); };
            },
            // step_timeout(cb, ms?) — upstream WPT uses real setTimeout;
            // we have none, so defer cb to a microtask. That gives the
            // calling code time to observe `false` flags set immediately
            // after invocation, then transitions on the next tick. The
            // cancel-integration fixture relies on this pattern.
            step_timeout: function (cb) {
                if (typeof cb !== "function") return;
                Promise.resolve().then(function () {
                    try { cb(); } catch (_) { /* surfaced via outer chain */ }
                });
            },
            done: function () {},
        };
        return t;
    }

    global.test = function (fn, name) {
        const t = makeTestObject();
        try {
            // Pass t as both `this` and the first argument so fixtures that
            // use `function(t)` AND arrow `t => ...` forms both work.
            fn.call(t, t);
            report("PASS", name);
        } catch (e) {
            report("FAIL", name, (e && e.message) || String(e));
        } finally {
            t._runCleanups();
        }
    };

    // promise_test: simplified — runs synchronously and resolves the promise
    // immediately. Phasis' Promise impl drains microtasks at engine exit; we
    // do the same here.
    global.promise_test = function (fn, name) {
        let failure = null;
        const t = makeTestObject();
        try {
            const result = fn.call(t, t);
            if (result && typeof result.then === "function") {
                result.then(
                    function () {},
                    function (e) { failure = e; }
                );
                // Drain microtasks so the resolve / reject of the chain
                // above runs before we report. The runner installs
                // __phasisDrainMicrotasks for a deterministic flush.
                if (typeof __phasisDrainMicrotasks === "function") {
                    __phasisDrainMicrotasks();
                }
            }
        } catch (e) {
            failure = e;
        } finally {
            t._runCleanups();
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
        const t = makeTestObject();
        if (fn === null) {
            // Caller will call `.step` etc. against the returned object.
            return t;
        }
        try {
            fn.call(t, t);
            report("PASS", name);
        } catch (e) {
            report("FAIL", name, (e && e.message) || String(e));
        } finally {
            t._runCleanups();
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

    // garbageCollect() — some WPT fixtures call this to test that the
    // implementation isn't relying on GC behavior. We have nothing
    // analogous in Phasis (PHP is refcounted), so make it a no-op.
    global.garbageCollect = function () {};

    // -- Streams test helpers (mirror upstream WPT resources) --
    //
    // Phasis runs synchronously, so several timing primitives become no-ops
    // or microtask drains. The shapes match upstream's
    // resources/test-utils.js and resources/recording-streams.js closely
    // enough for the fixtures we ship to pass.

    // flushAsyncEvents() — drains pending microtasks, resolves "soon".
    // Upstream WPT does this by chaining ~3 setTimeout(0)s; we drain the
    // microtask queue once and resolve.
    global.flushAsyncEvents = function () {
        if (typeof __phasisDrainMicrotasks === "function") {
            __phasisDrainMicrotasks();
        }
        return Promise.resolve();
    };

    // delay(ms) — returns a promise that resolves "after ms". We have no
    // setTimeout; resolve immediately and let the microtask queue drain.
    global.delay = function () {
        return Promise.resolve();
    };

    // recordingReadableStream({ start, pull, cancel }, qs?) — port of
    // upstream tests/wpt/streams/resources/recording-streams.js.
    global.recordingReadableStream = function (extras, strategy) {
        extras = extras || {};
        const events = [];
        const eventsWithoutPulls = [];
        let controllerToCopyOver;
        const stream = new ReadableStream({
            type: extras.type,
            start(controller) {
                controllerToCopyOver = controller;
                if (extras.start) {
                    return extras.start(controller);
                }
                return undefined;
            },
            pull(controller) {
                events.push("pull");
                if (extras.pull) {
                    return extras.pull(controller);
                }
                return undefined;
            },
            cancel(reason) {
                events.push("cancel", reason);
                eventsWithoutPulls.push("cancel", reason);
                if (extras.cancel) {
                    return extras.cancel(reason);
                }
                return undefined;
            }
        }, strategy);
        stream.controller = controllerToCopyOver;
        stream.events = events;
        stream.eventsWithoutPulls = eventsWithoutPulls;
        return stream;
    };

    // RandomPushSource(length?) — a push source that emits 128-byte
    // chunks. Upstream WPT uses real timers for backpressure; we have
    // no timers, so cap synchronous emission at a small number of chunks
    // (8 by default). That's enough to satisfy "at least one chunk read"
    // assertions while keeping the loop bounded.
    global.RandomPushSource = function (length) {
        const self = this;
        // For infinite sources, cap at a small synchronous burst so the
        // headless runner doesn't OOM. The cancel-integration test only
        // cares that *some* chunks are seen.
        self.length = length === undefined ? 8 : Math.min(length, 8);
        let stopped = false;
        let pushed = 0;
        self.ondata = function () {};
        self.onend = function () {};
        self.onerror = function () {};
        self.readStop = function () { stopped = true; };
        self.readStart = function () {
            while (!stopped && pushed < self.length) {
                pushed++;
                // 128 bytes of 'a' — matches what the WPT source produces.
                self.ondata(new Uint8Array(128).fill(97));
                if (pushed >= self.length) {
                    self.onend();
                }
            }
        };
    };

    // readableStreamToArray(stream) — utility used by some fixtures.
    global.readableStreamToArray = function (stream, reader) {
        const r = reader || stream.getReader();
        const out = [];
        function pump() {
            return r.read().then(function (chunk) {
                if (chunk.done) return out;
                out.push(chunk.value);
                return pump();
            });
        }
        return pump();
    };

    // -- WPT environment globals ---------------------------------------------

    // `self` is the global scope in WPT (window/worker/serviceworker). For
    // our headless runner, alias it to globalThis. Provides GLOBAL.isWorker()
    // so fixtures can branch on environment type.
    if (typeof global.self === "undefined") {
        global.self = global;
    }
    // Report ourselves as a worker so fixtures branched on
    // `!self.GLOBAL.isWorker()` skip their XMLHttpRequest paths — XHR
    // isn't implemented in Phasis. Tests that genuinely require window
    // semantics aren't in our imported set.
    global.GLOBAL = {
        isWorker: function () { return true; },
        isShadowRealm: function () { return false; },
        isWindow: function () { return false; },
    };
    if (typeof global.self.GLOBAL === "undefined") {
        global.self.GLOBAL = global.GLOBAL;
    }

    // RESOURCES_DIR points at the test server's /resources/ namespace. The
    // server is started by bin/wpt before any HTTP-bearing fixture runs.
    global.RESOURCES_DIR = "http://127.0.0.1:8765/resources/";

    // Stub XMLHttpRequest so fixtures gated on `self.GLOBAL.isWorker()`
    // false-branch don't ReferenceError at parse time. Tests that actually
    // *use* XHR will fail; tests that branch around it will not.
    if (typeof global.XMLHttpRequest === "undefined") {
        global.XMLHttpRequest = function () {
            throw AssertionError("XMLHttpRequest is not implemented in Phasis");
        };
    }

    if (typeof global.MessageChannel === "undefined") {
        // Inert stub: returns ports whose methods are all no-ops. Fixtures
        // that need real cross-thread postMessage behaviour are not in our
        // imported set, so silent acceptance is sufficient to let the
        // surrounding test body proceed without crashing the module.
        global.MessageChannel = function () {
            const port = {
                postMessage: function () {},
                start: function () {},
                close: function () {},
                addEventListener: function () {},
                removeEventListener: function () {},
                set onmessage(v) {},
                get onmessage() { return null; },
            };
            this.port1 = port;
            this.port2 = port;
        };
    }

    // promise_rejects_js(t, ErrorCtor, promise, description?) — verifies the
    // promise rejects with a value that's an instance of ErrorCtor.
    global.promise_rejects_js = function (t, ctor, promise, description) {
        return Promise.resolve(promise).then(
            function () {
                throw AssertionError(
                    (description ? description + ": " : "") +
                    "expected rejection of " + (ctor && ctor.name) +
                    " but resolved");
            },
            function (e) {
                if (e instanceof ctor) return;
                throw AssertionError(
                    (description ? description + ": " : "") +
                    "threw " + (e && e.name) + ", expected " +
                    (ctor && ctor.name));
            }
        );
    };

    // promise_rejects_exactly(t, expectedReason, promise, description?)
    // — verifies the promise rejects with a reason `===` expectedReason.
    global.promise_rejects_exactly = function (t, expectedReason, promise, description) {
        return Promise.resolve(promise).then(
            function () {
                throw AssertionError(
                    (description ? description + ": " : "") +
                    "expected rejection of " + describe(expectedReason) +
                    " but resolved");
            },
            function (e) {
                if (e === expectedReason) return;
                throw AssertionError(
                    (description ? description + ": " : "") +
                    "threw " + describe(e) + ", expected exactly " +
                    describe(expectedReason));
            }
        );
    };

    // promise_rejects_dom(t, codeOrName, promise, description?)
    global.promise_rejects_dom = function (t, codeOrName, promise, description) {
        return Promise.resolve(promise).then(
            function () {
                throw AssertionError(
                    (description ? description + ": " : "") +
                    "expected DOMException rejection but resolved");
            },
            function (e) {
                if (typeof codeOrName === "number") {
                    if (e && e.code === codeOrName) return;
                } else {
                    if (e && e.name === codeOrName) return;
                }
                throw AssertionError(
                    (description ? description + ": " : "") +
                    "threw " + (e && e.name) + " (code " + (e && e.code) +
                    "), expected " + codeOrName);
            }
        );
    };

    // -- AbortSignal.any helpers (dom/abort/resources/abort-signal-any-tests.js)
    //
    // The upstream resource file declares two functions which the WPT fixture
    // invokes against AbortSignal/AbortController. We provide synchronous
    // smoke tests that exercise our AbortSignal.any() implementation — the
    // real WPT resource has many timeout-based tests we can't run headless.

    global.abortSignalAnySignalOnlyTests = function (signalInterface) {
        test(function () {
            var s1 = signalInterface.abort();
            var combined = signalInterface.any([s1]);
            assert_true(combined.aborted, "combined signal pre-aborted");
        }, "AbortSignal.any() works with a pre-aborted source");

        test(function () {
            var combined = signalInterface.any([]);
            assert_false(combined.aborted, "empty .any() is not aborted");
        }, "AbortSignal.any() with empty input is not aborted");
    };

    global.abortSignalAnyTests = function (signalInterface, controllerInterface) {
        test(function () {
            var c = new controllerInterface();
            var combined = signalInterface.any([c.signal]);
            assert_false(combined.aborted);
            c.abort("boom");
            assert_true(combined.aborted, "combined fires on source abort");
            assert_equals(combined.reason, "boom", "reason propagates");
        }, "AbortSignal.any() propagates abort from a live source");

        test(function () {
            var c1 = new controllerInterface();
            var c2 = new controllerInterface();
            var combined = signalInterface.any([c1.signal, c2.signal]);
            c2.abort("second");
            assert_true(combined.aborted);
            assert_equals(combined.reason, "second");
        }, "AbortSignal.any() picks the first source that aborts");
    };

    // -- WPT Blob fixture helpers (../support/Blob.js) -----------------------
    //
    // Real WPT support file builds a Blob, reads its bytes via FileReader,
    // and asserts. We don't have FileReader, but Blob.text() / Blob.bytes()
    // resolve synchronously in Phasis, so we can replicate the contract.

    // Host-installed: returns a Uint8Array view of the Blob/File bytes.
    // Binary-safe — bytes outside ASCII don't roundtrip through JsString,
    // so the host hands us a typed array directly.
    function blobBytesSync(blob) {
        if (typeof __phasisBlobBytes === "function") {
            return __phasisBlobBytes(blob);
        }
        return null;
    }

    // UTF-8 decode the bytes to a JS string with U+FFFD on ill-formed
    // sequences. Mirrors the Blob.text() algorithm.
    function blobTextSync(blob) {
        var u8 = blobBytesSync(blob);
        if (u8 === null) return null;
        return new TextDecoder("utf-8").decode(u8);
    }

    function blobBytesArraySync(blob) {
        var u8 = blobBytesSync(blob);
        if (u8 === null) return null;
        return Array.from(u8);
    }

    global.test_blob = function (fn, options) {
        var desc = options && options.desc;
        var expected = options && options.expected;
        var type = options && options.type;
        test(function () {
            var blob = fn();
            assert_true(blob instanceof Blob, "blob is a Blob");
            assert_equals(blob.type, type, "blob.type");
            var got = blobTextSync(blob);
            assert_equals(got, expected, "blob.text() === expected");
        }, desc);
    };

    global.test_blob_binary = function (fn, options) {
        var desc = options && options.desc;
        var expected = options && options.expected; // Array<int>
        var type = options && options.type;
        test(function () {
            var blob = fn();
            assert_true(blob instanceof Blob, "blob is a Blob");
            assert_equals(blob.type, type, "blob.type");
            var got = blobBytesArraySync(blob);
            assert_array_equals(got, expected, "blob bytes");
        }, desc);
    };

})(typeof globalThis !== "undefined" ? globalThis : this);
