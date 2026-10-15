import {
  require_react_dom_server_edge_production
} from "./chunk-ZL7F3C3Q.mjs";
import "./chunk-ESKPO7C3.mjs";
import "./chunk-B7C5XBGA.mjs";
import {
  __commonJS,
  __toESM
} from "./chunk-B7F5QLE5.mjs";

// tests/Jsx/oracle/node_modules/react-dom/static.edge.js
var require_static_edge = __commonJS({
  "tests/Jsx/oracle/node_modules/react-dom/static.edge.js"(exports) {
    "use strict";
    var s;
    if (true) {
      s = require_react_dom_server_edge_production();
    } else {
      s = null;
    }
    exports.version = s.version;
    exports.prerender = s.prerender;
    exports.resumeAndPrerender = s.resumeAndPrerender;
  }
});

// tests/Jsx/oracle/.vendor-entries/static.js
var import_static = __toESM(require_static_edge(), 1);
var { version, prerender, resumeAndPrerender } = import_static.default;
var static_default = import_static.default;
export {
  static_default as default,
  prerender,
  resumeAndPrerender,
  version
};
