type Result = number;
function double(value: number): Result { return value * 2; }
module.exports = { value: double(21) };
console.log(module.exports.value);
