function makeCounter() {
    let count = 0;
    return function() {
        count++;
        return count;
    };
}
let counter = makeCounter();
console.log(counter());
console.log(counter());
console.log(counter());

function makeAdder(x) {
    return function(y) { return x + y; };
}
let add5 = makeAdder(5);
console.log(add5(3));
console.log(add5(10));

// Arrow function closures
let arr = [1, 2, 3];
let doubled = [];
for (let i = 0; i < arr.length; i++) {
    doubled.push(arr[i] * 2);
}
console.log(doubled[0]);
console.log(doubled[1]);
console.log(doubled[2]);
