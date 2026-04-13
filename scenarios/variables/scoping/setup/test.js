// Block scope
let x = 1;
{
    let x = 2;
    console.log(x);
}
console.log(x);

// Closure scope
function outer() {
    let x = 10;
    function inner() {
        return x;
    }
    return inner();
}
console.log(outer());

// Var hoisting
console.log(typeof hoisted);
var hoisted = 42;
console.log(hoisted);

// For loop scope
let funcs = [];
for (let i = 0; i < 3; i++) {
    funcs.push(function() { return i; });
}
console.log(funcs[0]());
console.log(funcs[1]());
console.log(funcs[2]());
