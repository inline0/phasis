// Resolved get-host-info helper. The upstream copy has WPT template
// substitutions ({{ports[http][0]}}, {{host}}, etc.) that the WPT
// server normally fills in. For our headless runner everything maps
// to the single fetch-server endpoint at 127.0.0.1:8765 — origin and
// "remote" origin are deliberately the same so cross-origin tests
// still hit the same handler set (which already returns the CORS
// headers the fixtures look for).

function get_host_info() {
    return {
        HTTP_PORT: "8765",
        HTTP_PORT2: "8765",
        HTTPS_PORT: "8765",
        HTTPS_PORT2: "8765",
        HTTP_PORT_ELIDED: ":8765",
        HTTP_PORT2_ELIDED: ":8765",
        HTTPS_PORT_ELIDED: ":8765",
        PORT: "8765",
        PORT2: "8765",
        PORT_ELIDED: ":8765",
        ORIGINAL_HOST: "127.0.0.1",
        // "Remote" uses `localhost` so curl treats it as a different
        // host (same backend, different name → different origin from
        // its perspective). That lets the cross-origin redirect tests
        // observe Authorization stripping that wouldn't otherwise
        // happen on a single-host setup.
        REMOTE_HOST: "localhost",
        OTHER_HOST: "localhost",
        NOTSAMESITE_HOST: "localhost",
        OTHER_NOTSAMESITE_HOST: "localhost",
        ORIGIN: "http://127.0.0.1:8765",
        HTTP_ORIGIN: "http://127.0.0.1:8765",
        HTTPS_ORIGIN: "http://127.0.0.1:8765",
        HTTP_NOTSAMESITE_ORIGIN: "http://localhost:8765",
        HTTPS_NOTSAMESITE_ORIGIN: "http://localhost:8765",
        REMOTE_ORIGIN: "http://localhost:8765",
        HTTP_REMOTE_ORIGIN: "http://localhost:8765",
        HTTPS_REMOTE_ORIGIN: "http://localhost:8765",
        OTHER_ORIGIN: "http://localhost:8765",
        UNAUTHENTICATED_ORIGIN: "http://127.0.0.1:8765",
        AUTHENTICATED_ORIGIN: "http://127.0.0.1:8765",
        ORIGINS: ["http://127.0.0.1:8765", "http://localhost:8765"],
        SAME_ORIGIN: "http://127.0.0.1:8765",
    };
}
