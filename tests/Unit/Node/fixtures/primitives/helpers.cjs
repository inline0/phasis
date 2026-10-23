'use strict';
exports.run = function (work) {
  Promise.resolve().then(work).then((result) => {
    globalThis.__nodePrimitiveResult = JSON.stringify(result);
    console.log(globalThis.__nodePrimitiveResult);
  }, (error) => {
    globalThis.__nodePrimitiveError = String(error.stack || error);
    console.error(globalThis.__nodePrimitiveError);
    process.exitCode = 1;
  });
};
