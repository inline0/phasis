let obj = { name: "Alice", age: 30 };
console.log(obj.name);
console.log(obj.age);
console.log(obj["name"]);

obj.city = "NYC";
console.log(obj.city);

let key = "age";
console.log(obj[key]);

// Shorthand
let x = 1, y = 2;
let point = { x, y };
console.log(point.x);
console.log(point.y);

// Computed properties
let propName = "dynamic";
let o = { [propName]: 42 };
console.log(o.dynamic);

// Spread
let a = { x: 1, y: 2 };
let b = { ...a, z: 3 };
console.log(b.x);
console.log(b.y);
console.log(b.z);
