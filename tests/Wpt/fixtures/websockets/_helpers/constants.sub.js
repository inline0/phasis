// Resolved copy of upstream tests/Wpt/upstream/websockets/constants.sub.js.
// The upstream file uses WPT template substitutions ({{host}}, {{ports[ws][0]}},
// ...) that the WPT server normally fills in. This pre-resolved variant
// hard-codes plausible values so the Create-* constructor-validation
// fixtures can run without the WPT server bound. Tests that depend on a
// real WebSocket peer aren't imported.

const __SERVER__NAME = "web-platform.test";
const __PATH = "echo";

let __SCHEME;
let __PORT;
if (url_has_flag("h2")) {
    __SCHEME = "wss";
    __PORT = "9000";
} else if (url_has_variant("wss") || location.protocol === "https:") {
    __SCHEME = "wss";
    __PORT = "8443";
} else {
    __SCHEME = "ws";
    __PORT = "8080";
}

const SCHEME_DOMAIN_PORT = __SCHEME + "://" + __SERVER__NAME + ":" + __PORT;
const SCHEME_CROSSDOMAIN_PORT = __SCHEME + "://" + "www.web-platform.test" + ":" + __PORT;

function url_has_variant(variant) {
    const params = new URLSearchParams(location.search);
    return params.get(variant) === "";
}

function url_has_flag(flag) {
    const params = new URLSearchParams(location.search);
    return params.getAll("wpt_flags").indexOf(flag) !== -1;
}

function IsWebSocket() {
    if (!self.WebSocket) {
        assert_true(false, "Browser does not support WebSocket");
    }
}

function CreateWebSocketNonAsciiProtocol(nonAsciiProtocol) {
    IsWebSocket();
    const url = SCHEME_DOMAIN_PORT + "/" + __PATH;
    return new WebSocket(url, nonAsciiProtocol);
}

function CreateWebSocketWithAsciiSep(asciiWithSep) {
    IsWebSocket();
    const url = SCHEME_DOMAIN_PORT + "/" + __PATH;
    return new WebSocket(url, asciiWithSep);
}

function CreateWebSocketWithBlockedPort(blockedPort) {
    IsWebSocket();
    const url = __SCHEME + "://" + __SERVER__NAME + ":" + blockedPort + "/" + __PATH;
    return new WebSocket(url);
}

function CreateWebSocketWithSpaceInUrl(urlWithSpace) {
    IsWebSocket();
    const url = __SCHEME + "://" + urlWithSpace + ":" + __PORT + "/" + __PATH;
    return new WebSocket(url);
}

function CreateWebSocketWithSpaceInProtocol(protocolWithSpace) {
    IsWebSocket();
    const url = SCHEME_DOMAIN_PORT + "/" + __PATH;
    return new WebSocket(url, protocolWithSpace);
}

function CreateWebSocketWithRepeatedProtocols() {
    IsWebSocket();
    const url = SCHEME_DOMAIN_PORT + "/" + __PATH;
    return new WebSocket(url, ["echo", "echo"]);
}

function CreateWebSocketWithRepeatedProtocolsCaseInsensitive() {
    IsWebSocket();
    const url = SCHEME_DOMAIN_PORT + "/" + __PATH;
    return new WebSocket(url, ["echo", "eCho"]);
}

function CreateInsecureWebSocket() {
    IsWebSocket();
    const url = "ws://" + __SERVER__NAME + ":8080/" + __PATH;
    return new WebSocket(url);
}

function CreateWebSocket(isProtocol, isProtocols) {
    IsWebSocket();
    const url = SCHEME_DOMAIN_PORT + "/" + __PATH;
    if (isProtocol) {
        return new WebSocket(url, "echo");
    }
    if (isProtocols) {
        return new WebSocket(url, ["echo", "chat"]);
    }
    return new WebSocket(url);
}
