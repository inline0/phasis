import _ from "lodash";
import debounce from "lodash/debounce.js";
console.log(JSON.stringify(_.groupBy([1.2, 1.4, 2.1], Math.floor)), _.kebabCase("Hello World"), typeof debounce);
